<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\ParHash;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\UsenetGroup;
use App\Services\NameFixing\NameFixingService;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\ExecutableReleaseDiscardService;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Carbon;

/**
 * Service responsible for parsing PAR2 data and applying results to releases.
 */
class Par2Processor
{
    private NameFixingService $nameFixingService;

    private Par2Info $par2Info;

    private bool $addPar2;

    private ExecutableReleaseDiscardService $discardService;

    public function __construct(
        NameFixingService $nameFixingService,
        Par2Info $par2Info,
        bool $addPar2,
        ?ExecutableReleaseDiscardService $discardService = null
    ) {
        $this->nameFixingService = $nameFixingService;
        $this->par2Info = $par2Info;
        $this->addPar2 = $addPar2;
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

        $query = Release::query()
            ->where(['isrenamed' => 0, 'id' => $relID])
            ->select(['id', 'groups_id', 'categories_id', 'name', 'searchname', 'postdate', 'id as releases_id'])
            ->first();

        if ($query === null) {
            return false;
        }

        // Only get a new name if the category is OTHER.
        $foundName = true;
        if (\in_array((int) $query['categories_id'], Category::OTHERS_GROUP, true)) {
            $foundName = false;
        }

        // Get the PAR2 file.
        $par2 = $nntp->getMessages(UsenetGroup::getNameByID($groupID), $messageID);
        if ($nntp->isError($par2)) {
            return false;
        }

        // Put the PAR2 into Par2Info, check if there's an error.
        $this->par2Info->setData($par2);
        if ($this->par2Info->error) {
            return false;
        }

        // Get the file list from Par2Info.
        $files = $this->par2Info->getFileList();

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
                if ($foundName === true && $filesAdded > 20) {
                    continue;
                }

                if ($this->addPar2) {
                    // Add to release files.
                    if ($filesAdded < 21 && ReleaseFile::query()->where(['releases_id' => $relID, 'name' => $file['name']])->first() === null) {
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
                if ($foundName === false) {
                    $query['textstring'] = $file['name'];
                    if ($this->nameFixingService->checkName($query, true, 'PAR2, ', true, (bool) $show)) {
                        $foundName = true;
                    }
                }
            }

            // If we found some files.
            if ($filesAdded > 0) {
                // Update the file count with the new file count + old file count.
                Release::whereId($relID)->increment('rarinnerfilecount', $filesAdded);
            }
            if ($foundName === true) {
                return true;
            }
        }

        return false;
    }
}
