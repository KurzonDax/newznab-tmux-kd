<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\ParHash;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\NameFixing\NameFixingService;
use App\Services\NNTP\NNTPService;
use App\Services\ObfuscationRecovery\RecoveryCachedInventory;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\ObfuscationRecovery\RecoveryInspection;
use App\Services\ObfuscationRecovery\RecoveryInventory;
use App\Services\ObfuscationRecovery\RecoveryNaming;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use App\Services\Par2Sidecar\SidecarEvidence;
use App\Services\Releases\ExecutableReleaseDiscardService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for parsing PAR2 data and applying results to releases.
 */
class Par2Processor
{
    private readonly ExecutableReleaseDiscardService $discardService;

    public function __construct(
        private readonly NameFixingService $nameFixingService,
        private readonly Par2Info $par2Info,
        private readonly bool $addPar2,
        ?ExecutableReleaseDiscardService $discardService = null
    ) {
        $this->discardService = $discardService ?? new ExecutableReleaseDiscardService;
    }

    /**
     * Attempt to get a better name from a PAR2 file and categorize the release.
     *
     * @param  string  $messageID  MessageID from NZB file.
     * @param  int  $relID  ID of the release.
     * @param  int  $groupID  Group ID of the release.
     * @param  NNTPService  $nntp  Class NNTPService
     * @param  int  $show  Only show result or apply it.
     */
    public function parseFromMessage(string $messageID, int $relID, int $groupID, NNTPService $nntp, int $show): bool
    {
        if ($messageID === '') {
            return false;
        }

        $recovery = (new RecoveryIdentityPolicy)->publication($relID);
        if ($recovery !== null) {
            $cached = app(RecoveryEvidence::class)->get($recovery->index_message_id);

            return $cached !== null && $this->parseData($cached->data, $relID, $show);
        }
        if (! Release::query()->where(['isrenamed' => 0, 'id' => $relID])->exists()) {
            return false;
        }
        $par2 = $nntp->getMessages(UsenetGroup::getNameByID($groupID), $messageID);
        if (! is_string($par2) || $nntp->isError($par2)) {
            return false;
        }

        return $this->parseData($par2, $relID, $show);
    }

    public function parseData(string $par2, int $relID, int $show = 0, bool $allowNaming = true, ?RecoveryInspection $inspection = null): bool
    {
        $recovery = (new RecoveryIdentityPolicy)->publication($relID);
        if ($recovery !== null) {
            $owned = $inspection === null;
            $inspection ??= RecoveryInspection::acquire($recovery);
            if ($inspection === null) {
                return false;
            }
            try {
                $inspection->assertPublication($recovery);
                $canonical = app(RecoveryEvidence::class)->get($recovery->index_message_id);
                if ($canonical === null || ! hash_equals($canonical->data, $par2)) {
                    throw new \RuntimeException('recovery_cached_index_mismatch');
                }
                $inventory = (new RecoveryCachedInventory)->validate($par2,
                    RecoveryPlan::fromArray(json_decode($recovery->sealed_plan, true, flags: JSON_THROW_ON_ERROR)));
                $this->par2Info->setData($par2);
                if ($this->par2Info->error) {
                    throw new \RuntimeException('cached_inventory_parser_failed');
                }

                return $inspection->mutate(fn (): bool => $this->applyData($par2, $relID, $show, $allowNaming, $inventory));
            } finally {
                if ($owned) {
                    $inspection->release();
                }
            }
        }

        return $this->applyData($par2, $relID, $show, $allowNaming);
    }

    private function applyData(string $par2, int $relID, int $show, bool $allowNaming, ?RecoveryInventory $inventory = null): bool
    {
        $recovery = (new RecoveryIdentityPolicy)->publication($relID);
        $query = Release::query()->where('id', $relID)
            ->when($recovery === null, static fn ($query) => $query->where('isrenamed', 0))
            ->select(['id', 'groups_id', 'categories_id', 'name', 'searchname', 'postdate', 'id as releases_id'])->first();
        if ($query === null || ($recovery !== null && $recovery->state !== 'published')) {
            return false;
        }
        $foundName = ! in_array((int) $query->categories_id, Category::OTHERS_GROUP, true);
        if ($inventory === null) {
            $this->par2Info->setData($par2);
        }
        if ($this->par2Info->error) {
            if ($inventory !== null) {
                throw new \RuntimeException('cached_inventory_parser_failed');
            }

            return false;
        }

        if ($recovery === null) {
            (new SidecarEvidence)->storeParsedDescriptors($relID, $par2);
        }

        // Get the file list from Par2Info.
        $files = $inventory === null ? $this->par2Info->getFileList() : array_map(static fn ($file): array => [
            'name' => $file->filename, 'size' => $file->size, 'hash_16K' => bin2hex($file->prefixMd5),
        ], $inventory->files);
        $recordingLimit = $recovery === null ? 21 : 32;

        // Executable check runs against the complete file list before any
        // recording caps, so a payload buried past the cap is still caught.
        $discardableFileName = $this->discardService->firstDiscardableFileName($files, (int) $query['categories_id']);

        if ($discardableFileName !== null) {
            $this->discardService->discardById($relID, $discardableFileName);

            return false;
        }

        if (\count($files) > 0) {
            $filesAdded = 0;

            // Loop through the files.
            foreach ($files as $file) {
                if (! isset($file['name'])) {
                    continue;
                }

                $hash = (string) ($file['hash_16K'] ?? '');
                if (strlen($hash) === 32) {
                    ParHash::query()->insertOrIgnore([
                        'releases_id' => $relID,
                        'hash' => $hash,
                    ]);
                }

                // Keep scanning hashes after the release-file display cap.
                if ($recovery === null && $foundName === true && $filesAdded >= $recordingLimit) {
                    continue;
                }

                if ($this->addPar2) {
                    // Add to release files.
                    if ($filesAdded < $recordingLimit && ReleaseFile::query()->where(['releases_id' => $relID, 'name' => $file['name']])->first() === null) {
                        // Try to add the files to the DB.
                        if (ReleaseFile::addReleaseFiles(
                            $relID,
                            $file['name'],
                            $file['size'] ?? 0,
                            $query['postdate'] !== null ? Carbon::createFromFormat('Y-m-d H:i:s', $query['postdate']) : now(),
                            0,
                            $hash
                        )) {
                            $filesAdded++;
                        }
                    }
                } else {
                    $filesAdded++;
                }

                // Try to get a new name.
                if ($recovery === null && $foundName === false) {
                    $query['textstring'] = $file['name'];
                    if ($this->nameFixingService->checkName($query, true, 'PAR2, ', true, (bool) $show)) {
                        $foundName = true;
                    }
                }
            }

            // If we found some files.
            if ($filesAdded > 0) {
                // Update the file count with the new file count + old file count.
                if ($this->addPar2) {
                    Release::whereId($relID)->update(['rarinnerfilecount' => ReleaseFile::query()->where('releases_id', $relID)->count()]);
                } else {
                    Release::whereId($relID)->where('rarinnerfilecount', '<', $filesAdded)->update(['rarinnerfilecount' => $filesAdded]);
                }
            }
            if ($recovery !== null && $inventory !== null) {
                return (new RecoveryNaming)->apply($recovery, $inventory, $this->nameFixingService,
                    $allowNaming && (int) Settings::settingValue('lookuppar2') === 1, (bool) $show);
            }
            if ($foundName === true) {
                return true;
            }
        }

        return false;
    }
}
