<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Only ReleaseNameWriter writes an existing release's searchname, display_name, is_trusted_name,
 * name_source or isrenamed (#1120). Release creation and the admin edit are the exceptions.
 * Every other mention of those columns in app/ is a read, a search query or another table's
 * column, counted here per file so a new write fails this test.
 */
class NameWriteOwnershipGuardTest extends TestCase
{
    private const string PATTERN = "/searchNameValues\\(|'(?:searchname|display_name|is_trusted_name|name_source|isrenamed)'\\s*=>|->(?:searchname|display_name|is_trusted_name|name_source|isrenamed)\\s*=(?![=>])/";

    /** File => [mentions, why they are not a write outside the writer]. */
    private const array ALLOWED = [
        'app/Services/NameFixing/ReleaseNameWriter.php' => [7, 'the writer'],
        'app/Models/Release.php' => [9, 'searchNameValues(), creation (insertRelease) and the admin edit (updateRelease)'],
        'app/Services/ReleaseCreationService.php' => [3, 'creation'],
        'app/Services/Nzb/NzbImportService.php' => [3, 'creation'],
        'app/Services/CollectionReconciliation/PendingReconciler.php' => [3, 'creation'],
        'app/Services/NameFixing/NameSource.php' => [1, 'the status columns the writer writes'],
        'app/Services/AdditionalProcessing/ReleaseFileManager.php' => [1, 'the in-memory name after an NZBSPLIT rename'],
        'app/Services/Par2Sidecar/SidecarCombiner.php' => [3, 'job state'],
        'app/Console/Commands/RefreshAnimeData.php' => [3, 'report rows'],
        'app/Services/AudioProcessing/AudioEvidenceRecorder.php' => [1, 'evidence rows'],
        'app/Services/AudioProcessing/AudioTagRenamer.php' => [1, 'a read for the tag rename'],
        'app/Models/DownloadStat.php' => [1, 'download statistics rows'],
        'app/Services/ObfuscationRecovery/RecoveryFilePlan.php' => [1, 'recovery file rows'],
        'app/Services/ObfuscationRecovery/RecoveryInventoryPolicy.php' => [2, 'recovery file rows'],
        'app/Services/Par2Processor.php' => [1, 'a query condition'],
        'app/Http/Controllers/Admin/AdminReleasesController.php' => [1, 'request validation'],
        'app/Http/Controllers/ReleaseFilesController.php' => [1, 'a response'],
        'app/Services/MusicIdentity/Rename/MusicRenameProjection.php' => [1, 'a comment'],
        'app/Support/ReleaseDisplayNameFormatter.php' => [1, 'a comment'],
        'app/Support/ReleaseSearchIndexDocument.php' => [1, 'a search document'],
        'app/Services/NameFixing/NameFixingService.php' => [1, 'a search query'],
        'app/Services/Releases/ReleaseBrowseService.php' => [2, 'search queries'],
        'app/Services/Releases/ReleaseSearchService.php' => [11, 'search queries'],
        'app/Services/Releases/WebSearchIndex.php' => [1, 'a search query'],
        'app/Services/Search/Drivers/ElasticSearchDriver.php' => [5, 'search queries'],
        'app/Services/Search/Drivers/ManticoreSearchDriver.php' => [6, 'search queries'],
        'app/Services/Search/Support/ManticoreIndexRegistry.php' => [2, 'index schema'],
        'app/Console/Commands/DeleteReleases.php' => [1, 'a search query'],
        'app/Console/Commands/NntmuxCreateESIndexes.php' => [1, 'index schema'],
        'app/Console/Commands/NntmuxOffsetPopulate.php' => [1, 'index schema'],
        'app/Console/Commands/NntmuxResetDb.php' => [1, 'index schema'],
        'app/Console/Commands/UpdateReleasesIndexSchema.php' => [2, 'index schema and documents'],
        'app/Console/Commands/UpdateReleasesIndexSchemaES.php' => [1, 'index schema'],
    ];

    public function test_only_the_name_writer_writes_a_releases_name_columns(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $mentions = preg_match_all(self::PATTERN, (string) file_get_contents($file->getPathname()));
            if ($mentions > 0) {
                $found[substr($file->getPathname(), strlen($root) + 1)] = $mentions;
            }
        }
        ksort($found);
        $allowed = array_map(static fn (array $entry): int => $entry[0], self::ALLOWED);
        ksort($allowed);

        $this->assertSame($allowed, $found, 'A release name column is written outside ReleaseNameWriter. Rename through '
            .'ReleaseNameWriter, or, for a read or another table, update ALLOWED with the reason.');
    }
}
