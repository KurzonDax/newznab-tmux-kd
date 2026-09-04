<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\DuplicateAbsorbOutcome;
use App\Enums\NzbImportStatus;
use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\BlacklistService;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseManagementService;
use App\Support\Utf8;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for importing NZB files into the database.
 */
class NzbImportService
{
    protected BlacklistService $blacklistService;

    protected ReleaseCleaningService $releaseCleaner;

    protected \stdClass|bool $site;

    protected mixed $crossPostt;

    protected CategorizationService $category;

    protected ReleaseDuplicateFinder $releaseDuplicateFinder;

    protected ReleaseDuplicateAbsorber $releaseDuplicateAbsorber;

    protected NzbParserService $parserService;

    protected ReleaseManagementService $releaseManagement;

    protected ReleaseImageService $releaseImage;

    /**
     * List of all the group names/ids in the DB.
     *
     * @var array<string, mixed>
     */
    protected array $allGroups;

    /**
     * Was this run from the browser?
     */
    protected bool $browser;

    /**
     * Return value for browser.
     */
    protected string $retVal;

    /**
     * Guid of the current releases.
     */
    protected string $relGuid;

    protected ?int $relId = null;

    public mixed $echoCLI;

    public NzbService $nzb;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        array $options = [],
        ?ReleaseManagementService $releaseManagement = null,
        ?ReleaseImageService $releaseImage = null,
    ) {
        $this->echoCLI = config('nntmux.echocli');
        $this->blacklistService = new BlacklistService;
        $this->category = new CategorizationService;
        $this->nzb = app(NzbService::class);
        $this->releaseCleaner = new ReleaseCleaningService;
        $this->releaseDuplicateFinder = app(ReleaseDuplicateFinder::class);
        $this->releaseDuplicateAbsorber = app(ReleaseDuplicateAbsorber::class);
        $this->parserService = app(NzbParserService::class);
        $this->releaseManagement = $releaseManagement ?? app(ReleaseManagementService::class);
        $this->releaseImage = $releaseImage ?? new ReleaseImageService;
        $this->crossPostt = Settings::settingValueOr('crossposttime', 2);

        // Set properties from options
        $this->browser = isset($options['Browser']) ? (bool) $options['Browser'] : false;
        $this->retVal = '';
    }

    /**
     * Begin importing NZB files.
     *
     * @param  null|callable(array{path:string,status:NzbImportStatus,release_id:int|null,release_guid:string|null}):void  $resultCallback
     *
     * @throws FileNotFoundException
     */
    public function beginImport(
        mixed $filesToProcess,
        bool $useNzbName = false,
        bool $delete = false,
        bool $deleteFailed = false,
        int $source = 1,
        ?callable $resultCallback = null,
    ): bool|string {
        // Get all the groups in the DB.
        if (! $this->getAllGroups()) {
            if ($this->browser) {
                return $this->retVal;
            }

            return false;
        }

        $start = now()->toImmutable()->format('Y-m-d H:i:s');
        $nzbsImported = $nzbsSkipped = $nzbsDuplicate = 0;

        // Convert all files to string paths and filter to only process NZB files
        $nzbFiles = [];
        foreach ($filesToProcess as $file) {
            $filePath = $file instanceof \SplFileInfo ? $file->getPathname() : (string) $file;
            $lowerFilePath = strtolower($filePath);
            if (Str::endsWith($lowerFilePath, '.nzb') || Str::endsWith($lowerFilePath, '.nzb.gz')) {
                $nzbFiles[] = $filePath;
            }
        }

        if (empty($nzbFiles)) {
            $this->echoOut('No NZB files found to process.');
            if ($this->browser) {
                return $this->retVal;
            }

            return false;
        }

        $totalFilesFiltered = count($filesToProcess) - count($nzbFiles);
        if ($totalFilesFiltered > 0) {
            $this->echoOut("Filtered out {$totalFilesFiltered} non-NZB files. Processing ".count($nzbFiles).' NZB files.');
        }

        $reportResult = static function (
            string $path,
            NzbImportStatus $status,
            ?int $releaseId = null,
            ?string $releaseGuid = null,
        ) use ($resultCallback): void {
            if ($resultCallback === null) {
                return;
            }

            $resultCallback([
                'path' => $path,
                'status' => $status,
                'release_id' => $status === NzbImportStatus::Inserted ? $releaseId : null,
                'release_guid' => $status === NzbImportStatus::Inserted ? $releaseGuid : null,
            ]);
        };

        // Loop over the NZB file names only.
        foreach ($nzbFiles as $nzbFilePath) {
            // Check if the file is really there.
            if (File::isFile($nzbFilePath)) {
                $this->relGuid = '';
                $this->relId = null;

                // Get the contents of the NZB file as a string.
                if (Str::endsWith(strtolower($nzbFilePath), '.nzb.gz')) {
                    $nzbString = unzipGzipFile($nzbFilePath);
                } else {
                    $nzbString = File::get($nzbFilePath);
                }

                if ($nzbString === false) {
                    $this->echoOut('ERROR: Unable to read: '.$nzbFilePath);
                    $reportResult($nzbFilePath, NzbImportStatus::Failed);

                    if ($deleteFailed) {
                        File::delete($nzbFilePath);
                    }
                    $nzbsSkipped++;

                    continue;
                }

                // Load it as an XML object.
                $nzbXML = @simplexml_load_string($nzbString);
                if ($nzbXML === false || strtolower($nzbXML->getName()) !== 'nzb') {
                    $this->echoOut('ERROR: Unable to load NZB XML data: '.$nzbFilePath);
                    $reportResult($nzbFilePath, NzbImportStatus::Failed);

                    if ($deleteFailed) {
                        File::delete($nzbFilePath);
                    }
                    $nzbsSkipped++;

                    continue;
                }

                // Try to insert the NZB details into the DB.
                $nzbFileName = $useNzbName === true ? $this->deriveReleaseNameFromNzbPath($nzbFilePath) : '';
                try {
                    $importStatus = $this->scanNZBFile($nzbXML, $nzbFileName, $source);
                } catch (\Throwable $exception) {
                    Log::error('NZB import failed while scanning or inserting a release.', [
                        'path' => $nzbFilePath,
                        'exception' => $exception,
                    ]);

                    $message = 'ERROR: Problem inserting: '.$nzbFilePath;
                    if (! $this->browser) {
                        $message .= ': '.$exception->getMessage();
                    }

                    $this->echoOut($message);
                    $importStatus = NzbImportStatus::Failed;
                }

                if ($importStatus === NzbImportStatus::Inserted) {
                    $path = null;
                    try {
                        $path = $this->nzb->getNzbPath($this->relGuid, 0, true);
                        $stored = $this->writeCompressedNzb($path, $nzbString);
                    } catch (\Throwable $exception) {
                        Log::error('NZB import failed while storing the compressed file.', [
                            'guid' => $this->relGuid,
                            'path' => $path,
                            'exception' => $exception,
                        ]);
                        $stored = false;
                    }

                    if (! $stored) {
                        $destination = $path ?? $this->relGuid;
                        $this->echoOut('ERROR: Problem compressing NZB file to: '.$destination);

                        $this->deleteImportedRelease();
                        $reportResult($nzbFilePath, NzbImportStatus::Failed);

                        if ($deleteFailed) {
                            File::delete($nzbFilePath);
                        }
                        $nzbsSkipped++;
                    } else {
                        $reportResult($nzbFilePath, NzbImportStatus::Inserted, $this->relId, $this->relGuid);

                        if ($delete) {
                            // Remove the nzb file.
                            File::delete($nzbFilePath);
                        }

                        $nzbsImported++;
                    }
                } else {
                    $reportResult($nzbFilePath, $importStatus);

                    if ($importStatus === NzbImportStatus::Duplicate) {
                        $nzbsDuplicate++;

                        if ($delete || $deleteFailed) {
                            File::delete($nzbFilePath);
                        }
                    } else {
                        if (in_array($importStatus, [NzbImportStatus::Blacklisted, NzbImportStatus::NoGroup], true)) {
                            if ($delete || $deleteFailed) {
                                File::delete($nzbFilePath);
                            }
                        } else {
                            $this->echoOut('ERROR: Failed to insert NZB!');
                            if ($deleteFailed) {
                                File::delete($nzbFilePath);
                            }
                        }

                        $nzbsSkipped++;
                    }
                }
            } else {
                $this->echoOut('ERROR: Unable to fetch: '.$nzbFilePath);
                $reportResult($nzbFilePath, NzbImportStatus::Failed);
                $nzbsSkipped++;
            }
        }
        $this->echoOut(
            'Processed '.
            $nzbsImported.
            ' NZBs in '.
            now()->diffInSeconds($start, true).' seconds, '.
            $nzbsSkipped.
            ' NZBs were skipped, '.
            $nzbsDuplicate.
            ' were duplicates.'
        );

        if ($this->browser) {
            return $this->retVal;
        }

        return true;
    }

    private function deleteImportedRelease(): void
    {
        $release = Release::query()
            ->where('guid', $this->relGuid)
            ->first(['id', 'guid']);
        if ($release === null) {
            return;
        }

        $this->releaseManagement->deleteBatch([$release], $this->nzb, $this->releaseImage);
    }

    /**
     * Derive a clean release name from an NZB file path by stripping the
     * trailing ".nzb"/".nzb.gz" wrapper and a known trailing media/container
     * extension (e.g. ".mkv", ".mp4"). Example:
     *   "Show - S01E07 [1080p].mkv.nzb.gz" => "Show - S01E07 [1080p]".
     */
    protected function deriveReleaseNameFromNzbPath(string $path): string
    {
        $name = basename($path);

        // Strip trailing .nzb or .nzb.gz (case-insensitive).
        $name = preg_replace('/\.nzb(\.gz)?$/i', '', $name) ?? $name;

        // Strip one trailing known media/container extension from the allow-list.
        $name = preg_replace(
            '/\.(mkv|mp4|avi|mov|wmv|flv|m4v|ts|webm|mpg|mpeg|iso|m2ts|ogm|divx|vob)$/i',
            '',
            $name
        ) ?? $name;

        // Tidy up any trailing dots/whitespace left behind.
        return rtrim($name, ". \t\n\r\0\x0B");
    }

    protected function writeCompressedNzb(string $path, string $contents): bool
    {
        $handle = @gzopen($path, 'w5');
        if ($handle === false) {
            Log::error('Unable to open imported NZB destination for writing.', ['path' => $path]);

            return false;
        }

        $stored = false;

        try {
            $written = gzwrite($handle, $contents);
            if ($written !== \strlen($contents) || ! gzclose($handle)) {
                Log::error('Unable to write the complete imported NZB file.', ['path' => $path]);

                return false;
            }

            $handle = null;
            $stored = File::isFile($path);

            return $stored;
        } catch (\Throwable $exception) {
            Log::error('Imported NZB compression failed.', [
                'path' => $path,
                'exception' => $exception,
            ]);

            return false;
        } finally {
            if (\is_resource($handle)) {
                @gzclose($handle);
            }
            if (! $stored) {
                File::delete($path);
            }
        }
    }

    /**
     * Scan and process an NZB file.
     *
     * @throws \Exception
     */
    protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
    {
        $binary_names = [];
        $segmentMessageIds = [];
        $completion = new CompletionTally;
        $totalFiles = $totalSize = $groupID = 0;
        $isBlackListed = $groupName = $firstName = $posterName = $postDate = false;

        // Go through the NZB, get the details, look if it's blacklisted, look if we have the groups.
        foreach ($nzbXML->file as $file) {
            $binary_names[] = $file['subject'];
            $totalFiles++;
            $groupID = -1;

            // Get the nzb info.
            if ($firstName === false) {
                $firstName = (string) $file->attributes()->subject;
            }
            if ($posterName === false) {
                $posterName = (string) $file->attributes()->poster;
            }
            if ($postDate === false) {
                $postDate = Carbon::createFromTimestamp((string) $file->attributes()->date, date_default_timezone_get())->format('Y-m-d H:i:s');
            }

            // Make a fake message array to use to check the blacklist.
            $msg = ['Subject' => (string) $file->attributes()->subject, 'From' => (string) $file->attributes()->poster, 'Message-ID' => ''];

            // Get the group names, group_id, check if it's blacklisted.
            $groupArr = [];
            foreach ($file->groups->group as $group) {
                $group = $this->normalizeGroupName($group);

                // If group_id is -1 try to get a group_id.
                if ($groupID === -1) {
                    if (array_key_exists($group, $this->allGroups)) {
                        $groupID = $this->allGroups[$group];
                        if (! $groupName) {
                            $groupName = $group;
                        }
                    } else {
                        $group = UsenetGroup::isValidGroup($group);
                        if ($group !== false) {
                            $groupID = UsenetGroup::addGroup([
                                'name' => $group,
                                'description' => 'Added by NZBimport script.',
                                'backfill_target' => 1,
                                'minfilestoformrelease' => '',
                                'minsizetoformrelease' => '',
                                'first_record' => 0,
                                'last_record' => 0,
                                'active' => 0,
                                'backfill' => 0,
                            ]);
                            $this->allGroups[$group] = $groupID;

                            $this->echoOut("Adding missing group: ($group)");
                        }
                    }
                }
                // Add all the found groups to an array.
                $groupArr[] = $group;

                // Check if this NZB is blacklisted (only if group is valid).
                if ($group !== false && $this->blacklistService->isBlackListed($msg, $group)) {
                    $isBlackListed = true;
                    break;
                }
            }

            // If we found a group and it's not blacklisted.
            if ($groupID !== -1 && ! $isBlackListed) {
                // Get the size of the release and collect the segment
                // message-IDs for the deterministic import hash.
                $segments = $file->segments->segment;
                $segmentCount = \count($segments);
                if ($segmentCount > 0) {
                    foreach ($segments as $segment) {
                        $totalSize += (int) $segment->attributes()->bytes;
                        $messageId = trim((string) $segment);
                        if ($messageId !== '') {
                            $segmentMessageIds[] = $messageId;
                        }
                    }
                }

                $subject = (string) $file->attributes()->subject;
                $completion->addFile(
                    $segmentCount,
                    $this->parserService->extractPartsTotal($subject),
                    $this->parserService->extractFilesTotal($subject),
                );
            } else {
                if ($isBlackListed) {
                    $errorMessage = 'Subject is blacklisted: '.mb_convert_encoding(trim($firstName), 'UTF-8', mb_list_encodings());
                } else {
                    $errorMessage = 'No group found for '.$firstName.' (one of '.implode(', ', $groupArr).' are missing';
                }
                $this->echoOut($errorMessage);

                // Persist blacklist usage stats if we matched any rule during this NZB processing
                $this->blacklistService->updateBlacklistUsage($this->blacklistService->getAndClearIdsToUpdate()); // @phpstan-ignore argument.type

                return $isBlackListed ? NzbImportStatus::Blacklisted : NzbImportStatus::NoGroup;
            }
        }

        // After scanning all files, persist any matched whitelist/blacklist usage
        $this->blacklistService->updateBlacklistUsage($this->blacklistService->getAndClearIdsToUpdate()); // @phpstan-ignore argument.type

        $completionSignals = $completion->signals();

        // Try to insert the NZB details into the DB.
        return $this->insertNZB(
            [
                'subject' => $firstName,
                'useFName' => $nzbFileName,
                'postDate' => empty($postDate) ? now()->format('Y-m-d H:i:s') : $postDate,
                'from' => empty($posterName) ? '' : $posterName,
                'groups_id' => $groupID,
                'groupName' => $groupName,
                'totalFiles' => $totalFiles,
                'totalSize' => $totalSize,
                'completion' => $completionSignals->percentage(),
                'declaredFiles' => $completionSignals->filesDeclared,
                'nzbCategoryId' => $this->resolveNzbCategoryId($nzbXML),
                'segmentMessageIds' => $segmentMessageIds,
                'nzbXml' => $nzbXML->asXML(),
            ]
        );
    }

    protected function resolveNzbCategoryId(mixed $nzbXML): ?int
    {
        if (! $nzbXML instanceof \SimpleXMLElement) {
            return null;
        }

        $categoryMetadata = [];
        foreach ($nzbXML->head->meta ?? [] as $meta) {
            if (mb_strtolower(trim((string) $meta['type'])) !== 'category') {
                continue;
            }

            $value = trim((string) $meta);
            if ($value !== '') {
                $categoryMetadata[] = $value;
            }
        }

        if ($categoryMetadata === []) {
            return null;
        }

        $activeCategories = Category::query()
            ->where('status', Category::STATUS_ACTIVE)
            ->get(['id', 'title']);
        $resolvedCategoryIds = [];

        foreach ($categoryMetadata as $value) {
            if (ctype_digit($value)) {
                $matchingCategories = $activeCategories->filter(
                    static fn (Category $category): bool => $category->id === (int) $value
                );
            } else {
                $normalizedValue = mb_strtolower($value);
                $matchingCategories = $activeCategories->filter(
                    static fn (Category $category): bool => mb_strtolower($category->title) === $normalizedValue
                );
            }

            if ($matchingCategories->count() !== 1) {
                return null;
            }

            $resolvedCategoryIds[] = (int) $matchingCategories->first()->id;
        }

        $resolvedCategoryIds = array_values(array_unique($resolvedCategoryIds));

        return count($resolvedCategoryIds) === 1 ? $resolvedCategoryIds[0] : null;
    }

    /**
     * Insert the NZB details into the database.
     *
     * @throws \Exception
     */
    protected function insertNZB(mixed $nzbDetails): NzbImportStatus
    {
        // Make up a GUID for the release.
        $this->relGuid = Str::uuid()->toString();

        // Remove part count from subject.
        $partLess = preg_replace('/(\(\d+\/\d+\))*$/', 'yEnc', $nzbDetails['subject']);
        // Remove added yEnc from above and anything after.
        $subject = mb_convert_encoding(trim(preg_replace('/yEnc.*$/i', 'yEnc', $partLess)), 'UTF-8', mb_list_encodings());

        $renamed = 0;
        $properlyNamed = false;
        $cleanedMeta = null;
        if ($nzbDetails['useFName'] !== '') {
            $cleanName = $nzbDetails['useFName'];
            $renamed = 1;
        } else {
            $cleanedMeta = $this->releaseCleaner->releaseCleaner($subject, $nzbDetails['from'], $nzbDetails['groupName']);
            if (\is_array($cleanedMeta)) {
                $cleanName = $cleanedMeta['cleansubject'] ?? $subject;
                $properlyNamed = isset($cleanedMeta['properlynamed']) && $cleanedMeta['properlynamed'] === true;
                $renamed = $properlyNamed ? 1 : 0;
            } else {
                $cleanName = \is_string($cleanedMeta) ? $cleanedMeta : $subject;
            }
        }

        if (! \is_string($cleanName)) {
            $cleanName = $subject;
        }

        $preIdFromCleaner = 0;
        if (\is_array($cleanedMeta) && isset($cleanedMeta['predb']) && (int) $cleanedMeta['predb'] > 0) {
            $preIdFromCleaner = (int) $cleanedMeta['predb'];
        }

        $predbIdInt = $preIdFromCleaner;
        if ($predbIdInt === 0 && $cleanName !== '') {
            $preMatch = Predb::matchPre($cleanName);
            if ($preMatch !== false) {
                $cleanName = $preMatch['title'];
                $predbIdInt = (int) $preMatch['predb_id'];
                $renamed = 1;
                $properlyNamed = true;
            }
        }

        $escapedSubject = $subject;
        $escapedFromName = $nzbDetails['from'];
        $escapedSearchName = Utf8::clean($cleanName);
        if ($escapedSearchName === '') {
            $escapedSearchName = $escapedSubject;
        }

        [$dupeCheck, $dupeReason] = $this->releaseDuplicateFinder->findDuplicate(
            $escapedSubject,
            $escapedSearchName,
            $predbIdInt,
            (int) $nzbDetails['totalSize']
        );

        if ($dupeCheck !== null) {
            $absorbed = false;
            if ($this->releaseDuplicateAbsorber->supportsReason($dupeReason)) {
                $nzbXml = $nzbDetails['nzbXml'] ?? false;
                if (! \is_string($nzbXml)) {
                    throw new \RuntimeException('The incoming duplicate NZB could not be serialized.');
                }

                $absorbResult = $this->releaseDuplicateAbsorber->absorbXml(
                    $dupeCheck,
                    $nzbXml,
                    (int) $nzbDetails['totalSize'],
                    (int) $nzbDetails['declaredFiles'],
                    (float) $nzbDetails['completion'],
                );
                $absorbed = $absorbResult->wasAbsorbed();

                // A deferred or failed absorb still records the import as an
                // ordinary duplicate; the run never aborts over it. There is
                // no preserved collection here, so nothing is retried.
                if ($absorbResult->outcome === DuplicateAbsorbOutcome::Failed) {
                    Log::warning('NZB import duplicate absorb failed; recording the import as an ordinary duplicate.', [
                        'matched_release_id' => $dupeCheck->id,
                        'reason' => $absorbResult->reason,
                    ]);
                }
            }

            Log::info('NZB import skipped as duplicate', [
                'reason' => $dupeReason,
                'matched_release_id' => $dupeCheck->id,
                'absorbed' => $absorbed,
                'new_searchname' => $escapedSearchName,
                'existing_searchname' => $dupeCheck->searchname,
                'new_size' => (int) $nzbDetails['totalSize'],
                'existing_size' => (int) $dupeCheck->size,
                'new_fromname' => $escapedFromName,
                'existing_fromname' => $dupeCheck->fromname,
                'new_name' => $escapedSubject,
                'existing_name' => $dupeCheck->name,
            ]);
            $this->echoOut('This release is already in our DB so skipping: '.$subject);

            return NzbImportStatus::Duplicate;
        }

        $categoryId = $nzbDetails['nzbCategoryId'];
        if (! \is_int($categoryId)) {
            $determinedCategory = $this->category->determineCategory($nzbDetails['groups_id'], $cleanName, $escapedFromName);
            $categoryId = (int) $determinedCategory['categories_id'];
        }

        $importHash = $this->computeSegmentMessageIdHash($nzbDetails['segmentMessageIds'] ?? []);

        try {
            $relID = Release::insertRelease(
                [
                    'name' => $escapedSubject,
                    'searchname' => $escapedSearchName,
                    'totalpart' => $nzbDetails['totalFiles'],
                    'declaredfiles' => $nzbDetails['declaredFiles'],
                    'groups_id' => $nzbDetails['groups_id'],
                    'guid' => $this->relGuid,
                    'postdate' => $nzbDetails['postDate'],
                    'fromname' => $escapedFromName,
                    'size' => $nzbDetails['totalSize'],
                    'categories_id' => $categoryId,
                    'isrenamed' => $renamed,
                    'is_trusted_name' => $properlyNamed || $predbIdInt > 0,
                    'predb_id' => $predbIdInt,
                    'nzbstatus' => NzbService::NZB_ADDED,
                    'completion' => $nzbDetails['completion'],
                    'collectionhash' => $importHash,
                ]
            );
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $importHash === null
                ? null
                : Release::query()
                    ->where('collectionhash', $importHash)
                    ->first(['id', 'searchname', 'fromname', 'size', 'name']);

            if ($existing === null) {
                throw $exception;
            }

            Log::info('NZB import skipped as duplicate', [
                'reason' => 'collectionhash_match',
                'matched_release_id' => $existing->id,
                'absorbed' => false,
                'new_searchname' => $escapedSearchName,
                'existing_searchname' => $existing->searchname,
                'new_size' => (int) $nzbDetails['totalSize'],
                'existing_size' => (int) $existing->size,
                'new_fromname' => $escapedFromName,
                'existing_fromname' => $existing->fromname,
                'new_name' => $escapedSubject,
                'existing_name' => $existing->name,
            ]);
            $this->echoOut('This release is already in our DB so skipping: '.$subject);

            return NzbImportStatus::Duplicate;
        }

        if ($relID === null) {
            $this->echoOut('ERROR: Problem inserting: '.$subject);

            return NzbImportStatus::Failed;
        }

        $this->relId = (int) $relID;

        return NzbImportStatus::Inserted;
    }

    /**
     * Deterministic identity for an imported NZB: sha1 over the sorted,
     * de-duplicated set of segment message-IDs, raw binary to match
     * releases.collectionhash BINARY(20). Sorting first makes the hash
     * independent of segment order in the file. Message-IDs are globally
     * unique per Usenet article, so the same NZB (or a re-generated NZB for
     * the same upload) always hashes the same, while a true repost (new
     * articles, new message-IDs) correctly remains a distinct release.
     *
     * Deliberate asymmetry with the native pipeline: releases created from
     * collections carry sha1(cleanedSubject . totalFiles). Reconstructing
     * that subject-derived hash from NZB file subjects would be fragile
     * (cleaning regexes evolve), so matching an import against a
     * natively-indexed release stays with the heuristic
     * {@see ReleaseDuplicateFinder}.
     *
     * An NZB with no segments returns null — hashing the empty set would
     * make every empty NZB collide on one value.
     *
     * @param  list<string>  $messageIds
     */
    protected function computeSegmentMessageIdHash(array $messageIds): ?string
    {
        if ($messageIds === []) {
            return null;
        }

        $messageIds = array_values(array_unique($messageIds));
        sort($messageIds);

        return sha1(implode("\n", $messageIds), true);
    }

    protected function normalizeGroupName(\SimpleXMLElement|string $group): string
    {
        return trim((string) $group);
    }

    /**
     * Get all groups in the DB.
     */
    protected function getAllGroups(): bool
    {
        $this->allGroups = [];
        $groups = UsenetGroup::query()->get(['id', 'name']);

        if ($groups instanceof \Traversable) {
            foreach ($groups as $group) {
                $this->allGroups[$group['name']] = $group['id'];
            }
        }

        if (\count($this->allGroups) === 0) {
            $this->echoOut('You have no groups in your database!');

            return false;
        }

        return true;
    }

    /**
     * Echo message to browser or CLI.
     */
    protected function echoOut(string $message): void
    {
        if ($this->browser) {
            $this->retVal .= $message.'<br />';
        } elseif ($this->echoCLI) {
            cli()->notice($message);
        }
    }
}
