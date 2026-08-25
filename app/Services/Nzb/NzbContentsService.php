<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\Settings;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\PostProcessService;

/**
 * Service for processing NZB contents - extracting NFO files, PAR2 information,
 * and calculating release completion from NZB files.
 */
class NzbContentsService
{
    protected NzbService $nzbService;

    protected NzbParserService $parserService;

    protected NNTPService $nntp;

    protected NfoService $nfo;

    protected PostProcessService $postProcessService;

    protected bool $lookupPar2;

    protected bool $echoOutput;

    /**
     * Upper bound on PAR2 candidate files fetched from the news server for one
     * release in {@see self::checkPar2()}.
     *
     * A provider that lacks the PAR2 index is very unlikely to have the other
     * volumes either, and each missing article can cost a tarpitted 430, so the
     * loop stops after this many attempts instead of walking every candidate.
     */
    public const int MAX_PAR2_FETCH_ATTEMPTS = 3;

    /**
     * Subjects that look like a PAR2 file posted as a single part: `.par2`
     * (quoted, spaced, or bare) or a quoted name ending in a two- or
     * three-digit suffix, followed by a `(1/1)` part count.
     */
    private const string PAR2_SUBJECT_PATTERN = '/\.(par[2" ]|\d{2,3}").+\(1\/1\)/i';

    /** @var array{files: int, attempts: int} */
    private array $lastPar2Stats = ['files' => 0, 'attempts' => 0];

    public function __construct(
        ?NzbService $nzbService = null,
        ?NzbParserService $parserService = null,
        ?NNTPService $nntp = null,
        ?NfoService $nfo = null,
        ?PostProcessService $postProcessService = null
    ) {
        $this->echoOutput = (bool) config('nntmux.echocli');
        $this->nzbService = $nzbService ?? app(NzbService::class);
        $this->parserService = $parserService ?? app(NzbParserService::class);
        $this->nntp = $nntp ?? new NNTPService;
        $this->nfo = $nfo ?? new NfoService;
        $this->postProcessService = $postProcessService ?? app(PostProcessService::class);
        $this->lookupPar2 = (int) Settings::settingValue('lookuppar2') === 1;
    }

    /**
     * Look for an .nfo file in the NZB, download it, verify it, and return the content.
     *
     * @param  string  $guid  The release GUID.
     * @param  int  $relID  The release ID.
     * @param  int  $groupID  The group ID.
     * @param  string  $groupName  The group name.
     * @return string|false The verified NFO content as a string, or false if not found, download failed, or verification failed.
     *
     * @throws \Exception If NNTP operations fail.
     */
    public function getNfoFromNzb(string $guid, int $relID, int $groupID, string $groupName): string|false
    {
        // Step 1: Attempt to find a potential NFO message ID
        $messageID = $this->parseNzb($guid, $relID, $groupID, true);

        // If no NFO message ID found
        if ($messageID === false || ! isset($messageID['id'])) {
            if ($this->echoOutput) {
                echo '-';
            }
            // Make sure we set status to NFO_NONFO
            Release::query()->where('id', $relID)->update(['nfostatus' => NfoService::NFO_NONFO]);

            return false;
        }

        // Step 2: Attempt to download the potential NFO content
        $fetchedBinary = $this->nntp->getMessages($groupName, $messageID['id']);

        // Check if download failed
        if ($this->nntp->isError($fetchedBinary)) {
            // NFO download failed, decrement attempts to allow retries
            Release::query()->where('id', $relID)->decrement('nfostatus');
            if ($this->echoOutput) {
                echo 'f';
            }

            return false;
        }

        // Step 3: Verify if the downloaded content is actually an NFO file
        if ($this->nfo->isNFO($fetchedBinary, $guid)) {
            // NFO verification successful
            if ($this->echoOutput) {
                // Show if it was found via explicit name (+) or potentially hidden (*)
                echo $messageID['hidden'] === false ? '+' : '*';
            }

            return $fetchedBinary;
        }

        // Step 4: Handle verification failure - not a valid NFO
        if ($this->echoOutput) {
            echo '-';
        }
        Release::query()->where('id', $relID)->update(['nfostatus' => NfoService::NFO_NONFO]);

        return false;
    }

    /**
     * Gets the completion from the NZB, optionally looks if there is an NFO/PAR2 file.
     *
     * @param  string  $guid  The release GUID.
     * @param  int  $relID  The release ID.
     * @param  int  $groupID  The group ID.
     * @param  bool  $nfoCheck  Whether to specifically look for an NFO file.
     * @return array<string, mixed>|false An array containing NFO message ID and hidden status, or false if not found/error.
     *
     * @throws \Exception If NNTP operations fail.
     */
    public function parseNzb(string $guid, int $relID, int $groupID, bool $nfoCheck = false): bool|array
    {
        $nzbFile = $this->loadNzb($guid);
        if ($nzbFile === false) {
            return false;
        }

        $messageID = $hiddenID = '';
        $completion = new CompletionTally;
        // Initialize foundPAR2 based on settings; if lookupPar2 is false, we don't need to find one.
        $foundPAR2 = $this->lookupPar2 === false;
        // Initialize NFO flags based on whether we are checking for NFOs.
        $foundNFO = $hiddenNFO = $nfoCheck === false;
        $nfoMessageId = null; // Store potential NFO message ID here

        foreach ($nzbFile->file as $nzbContents) {
            $segmentCountInFile = 0;
            $firstSegmentId = null; // Initialize here for each file
            foreach ($nzbContents->segments->segment as $segment) {
                $segmentCountInFile++;
                // Store the first segment ID of the current file, potentially useful for NFO/PAR2
                if ($segmentCountInFile === 1) {
                    $firstSegmentId = (string) $segment;
                }
            }

            $subject = (string) $nzbContents->attributes()->subject;

            $completion->addFile(
                $segmentCountInFile,
                $this->parserService->extractPartsTotal($subject),
                $this->parserService->extractFilesTotal($subject),
            );

            // --- NFO Detection ---
            // Check for explicit NFO files first (with enhanced patterns)
            if ($nfoCheck && ! $foundNFO && isset($firstSegmentId)) {
                $nfoDetection = $this->parserService->detectNfoFile($subject);
                if ($nfoDetection !== false) {
                    $nfoMessageId = ['hidden' => $nfoDetection['hidden'], 'id' => $firstSegmentId, 'priority' => $nfoDetection['priority']];
                    $foundNFO = true;
                }
            }

            // Check for potential "hidden" NFOs with improved detection
            // Only consider this if an explicit NFO wasn't found yet
            if ($nfoCheck && ! $foundNFO && ! $hiddenNFO && isset($firstSegmentId)) {
                $hiddenNfoDetection = $this->parserService->detectHiddenNfoFile($subject, $segmentCountInFile);
                if ($hiddenNfoDetection !== false) {
                    $nfoMessageId = ['hidden' => $hiddenNfoDetection['hidden'], 'id' => $firstSegmentId, 'priority' => $hiddenNfoDetection['priority']];
                    $hiddenNFO = true;
                }
            }

            // --- PAR2 Detection ---
            // Look specifically for the .par2 index file (often small, but not always 1/1)
            if ($this->lookupPar2 && ! $foundPAR2 && isset($firstSegmentId) && $this->parserService->detectPar2IndexFile($subject)) {
                // Attempt to parse the PAR2 file using its first segment ID
                if ($this->postProcessService->parsePAR2($firstSegmentId, $relID, $groupID, $this->nntp, 1) === true) {
                    Release::query()->where('id', $relID)->update(['proc_par2' => 1]);
                    $foundPAR2 = true;
                }
            }
        } // End foreach $nzbFile->file

        $this->recordFallbackCompletion($relID, $completion->signals());

        // If NFO check was requested, return the found message ID (prioritizing explicit)
        if ($nfoCheck && $nfoMessageId !== null && \strlen($nfoMessageId['id']) > 1) {
            return $nfoMessageId;
        }

        // If NFO check was requested but nothing suitable was found
        if ($nfoCheck && $nfoMessageId === null) {
            // Update status to indicate no NFO was found in the NZB structure
            Release::query()->where('id', $relID)->update(['nfostatus' => NfoService::NFO_NONFO]);

            return false;
        }

        // If NFO check was not requested, the function's primary goal might be just completion/PAR2 update.
        return false;
    }

    /**
     * Loads and parses an NZB file based on a GUID.
     *
     * @param  string  $guid  The release GUID to locate the NZB file
     * @return \SimpleXMLElement|false The parsed NZB file as SimpleXMLElement or false on failure
     */
    public function loadNzb(string $guid): \SimpleXMLElement|false
    {
        // Fetch the NZB file path using the GUID
        $nzbPath = $this->nzbService->nzbPath($guid);
        if ($nzbPath === false) {
            return false;
        }

        // Attempt to decompress the NZB file
        $nzbContents = unzipGzipFile($nzbPath);
        if (empty($nzbContents)) {
            if ($this->echoOutput) {
                $perms = fileperms($nzbPath);
                $formattedPerms = $perms !== false ? decoct($perms & 0777) : 'unknown';
                echo PHP_EOL."Unable to decompress: {$nzbPath} - {$formattedPerms} - may have bad file permissions, skipping.".PHP_EOL;
            }

            return false;
        }

        return $this->parserService->parseNzbXml($nzbContents, $this->echoOutput, $guid);
    }

    /**
     * Attempts to get the release name from a par2 file.
     *
     * Only files whose subject matches {@see self::PAR2_SUBJECT_PATTERN} are
     * fetched, at most {@see self::MAX_PAR2_FETCH_ATTEMPTS} of them, and the
     * first successful parse wins. `parsePAR2()` returns a plain bool, so a
     * missing article and a PAR2 that parsed but yielded no name both count
     * as one attempt.
     *
     * @throws \Exception
     */
    public function checkPar2(string $guid, int $relID, int $groupID, int $nameStatus, int $show): bool
    {
        $this->lastPar2Stats = ['files' => 0, 'attempts' => 0];

        if ($nameStatus !== 1) {
            return false;
        }

        $nzbFile = $this->loadNzb($guid);
        if ($nzbFile === false) {
            return false;
        }

        foreach ($nzbFile->file as $nzbContents) {
            $this->lastPar2Stats['files']++;

            if (preg_match(self::PAR2_SUBJECT_PATTERN, (string) $nzbContents->attributes()->subject) !== 1) {
                continue;
            }
            // Keep counting files past the cap so the --show line reports
            // the NZB's real size; only the fetches stop.
            if ($this->lastPar2Stats['attempts'] >= self::MAX_PAR2_FETCH_ATTEMPTS) {
                continue;
            }

            $this->lastPar2Stats['attempts']++;
            if ($this->postProcessService->parsePAR2((string) $nzbContents->segments->segment, $relID, $groupID, $this->nntp, $show)) {
                Release::query()->where('id', $relID)->update(['proc_par2' => 1]);

                return true;
            }
        }

        Release::query()->where('id', $relID)->update(['proc_par2' => 1]);

        return false;
    }

    /**
     * How much work the last {@see self::checkPar2()} call did: NZB files
     * considered and PAR2 candidates actually fetched.
     *
     * @return array{files: int, attempts: int}
     */
    public function lastPar2Stats(): array
    {
        return $this->lastPar2Stats;
    }

    /**
     * Record completion for a release that has never had it measured.
     *
     * Releases formed from headers get `completion` at creation time, from the CBP rows
     * themselves. This path exists for the ones that never had CBP rows -- imported NZBs -- and
     * so must never overwrite a creation-time value: it writes only while the release still
     * carries the `0` sentinel, the same way {@see NfoService::addAlternateNfo()} gates itself.
     */
    protected function recordFallbackCompletion(int $relID, CompletionSignals $signals): void
    {
        if (! $signals->isMeasurable()) {
            return;
        }

        Release::query()
            ->where('id', $relID)
            ->where('completion', '=', 0)
            ->update(['completion' => $signals->percentage()]);
    }

    /**
     * Set NNTP service instance.
     */
    public function setNntp(NNTPService $nntp): void
    {
        $this->nntp = $nntp;
    }

    /**
     * Set NFO handler instance.
     */
    public function setNfo(NfoService $nfo): void
    {
        $this->nfo = $nfo;
    }

    /**
     * Set echo output setting.
     */
    public function setEchoOutput(bool $echo): void
    {
        $this->echoOutput = $echo;
    }

    /**
     * Get echo output setting.
     */
    public function getEchoOutput(): bool
    {
        return $this->echoOutput;
    }
}
