<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionsCleaningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TokenPrefixedArchiveTestCase;

class TokenPrefixedArchiveRegexTest extends TokenPrefixedArchiveTestCase
{
    public function test_archive_payload_and_recovery_volumes_share_the_exact_stem(): void
    {
        $cleaner = new CollectionsCleaningService;
        foreach (['.part1.rar', '.7z.001', '.par2', '.vol000+01.par2'] as $suffix) {
            $result = $cleaner->collectionsCleaner('Sample-A [01/12] - "Sample-A'.$suffix.'" yEnc', 'alt.binaries.erotica');
            $this->assertSame(284, $result['id']);
            $this->assertSame('12] - "Sample-A', $result['name']);
        }
    }

    public function test_supported_suffixes_preserve_distinct_stems_and_textual_totals(): void
    {
        $stems = ['Sample-A1', 'Sample-A2', 'Sample_1', 'Sample_11', 'Sample.7z', 'Sample.2026',
            'Sample.1080p', 'Sample-vol1', 'Sample-par2', 'A', str_repeat('Z', 200)];
        for ($i = 0; $i < 100; $i++) {
            $stems[] = 'Fixture-'.substr(hash('sha256', 'scope-'.$i), 0, 18).($i % 10);
        }
        $cleaner = new CollectionsCleaningService;
        $keys = [];
        foreach ($stems as $stem) {
            foreach (['19', '019', '20'] as $total) {
                foreach (['.part1.rar', '.PART99999.RAR', '.7z.001', '.7Z.999', '.vol0+193.par2',
                    '.VOL99999+99999.PAR2', '.rar', '.7z', '.par2', '.nfo', '.sfv', '.nzb'] as $suffix) {
                    $subject = $stem.' [01/'.$total.'] - "'.$stem.$suffix.'" yEnc';
                    $result = $cleaner->collectionsCleaner($subject, 'alt.binaries.erotica');
                    $this->assertSame(['id' => 284, 'name' => $total.'] - "'.$stem], $result, $subject);
                    $keys[$stem.'/'.$total] = $result['name'];
                }
            }
        }
        $this->assertCount(count($stems) * 3, array_unique($keys));
    }

    public function test_outside_the_guard_return_values_and_winning_rules_are_unchanged(): void
    {
        $updated = DB::table('collection_regexes')->where('id', 284)->value('regex');
        $cases = [];
        for ($i = 0; $i < 100; $i++) {
            $stem = 'Fixture-'.substr(hash('sha256', 'scope-'.$i), 0, 18).($i % 10);
            foreach (['.mkv', '.mp4', '.mp3', '.jpg', '.exe', '.zip', '.7z.0001', '.tar.zst', '.part01.rar.par2',
                '.part123456.rar', '.vol123456+1.par2', '.vol1+123456.par2', '.7z.01'] as $suffix) {
                $cases[] = [$stem.' [01/19] - "'.$stem.$suffix.'" yEnc', 'alt.binaries.erotica'];
            }
            $base = $stem.' [01/19] - "'.$stem.'.7z.001" yEnc';
            foreach ([
                str_replace('"'.$stem, '"Different-'.$stem, $base),
                str_replace('"'.$stem, '"'.strtolower($stem), $base),
                'Additional prose '.$base, str_replace(' [', ' - [', $base),
                str_replace('"', '#34;', $base), str_replace('" yEnc', '" - 1 GB yEnc', $base),
                str_replace('"'.$stem, '"path/'.$stem, $base),
                str_replace(' [', "\t[", $base), str_replace('[01/', '[123456/', $base),
                str_replace('/19]', '/123456]', $base), $base.' trailing',
                str_replace('Fixture-', '_Fixture-', $base),
                str_replace($stem, str_repeat('A', 201), $base),
            ] as $negative) {
                $cases[] = [$negative, 'alt.binaries.erotica'];
            }
            foreach (['alt.binaries.boneless', 'alt.binaries.multimedia.erotica',
                'alt.binaries.multimedia.erotica.amateur', 'alt.binaries.sounds.mp3',
                'alt.binaries.movies', 'alt.binaries.erotica.extra', 'alt.binaries.erotica2'] as $group) {
                $cases[] = [$base, $group];
            }
        }
        foreach (DB::table('collection_regexes')->pluck('description') as $description) {
            foreach (['alt.binaries.erotica', 'alt.binaries.boneless', 'alt.binaries.multimedia.erotica',
                'alt.binaries.multimedia.erotica.amateur', 'alt.binaries.sounds.mp3', 'alt.binaries.movies'] as $group) {
                $cases[] = [$description, $group];
            }
        }
        $this->installPrevious();
        $before = new CollectionsCleaningService;
        $expected = array_map(static fn (array $case): array => $before->collectionsCleaner(...$case), $cases);
        DB::table('collection_regexes')->where('id', 284)->update(['regex' => $updated]);
        Cache::flush();
        $after = new CollectionsCleaningService;
        foreach ($cases as $index => $case) {
            $this->assertSame($expected[$index], $after->collectionsCleaner(...$case), implode(' / ', $case));
        }
    }

    public function test_a_higher_priority_rule_keeps_ownership(): void
    {
        DB::table('collection_regexes')->insert(['id' => 99999, 'group_regex' => '^alt\\.binaries\\.erotica$',
            'regex' => '~^(?P<match0>Sample-A) .*yEnc$~', 'status' => 1, 'ordinal' => 1]);
        $result = (new CollectionsCleaningService)->collectionsCleaner('Sample-A [01/12] - "Sample-A.7z.001" yEnc', 'alt.binaries.erotica');
        $this->assertSame(['id' => 99999, 'name' => 'Sample-A'], $result);
    }

    public function test_stock_upgrade_and_rollback_are_idempotent_and_refresh_cached_readers(): void
    {
        $seeded = DB::table('collection_regexes')->orderBy('id')->get()->toJson();
        $this->installPrevious();
        $original = DB::table('collection_regexes')->orderBy('id')->get()->toJson();
        $subject = 'Sample-B [01/19] - "Sample-B.7z.001" yEnc';
        $reader = new CollectionsCleaningService;
        $this->assertSame('19] - "Sample-B.7z', $reader->collectionsCleaner($subject, 'alt.binaries.erotica')['name']);
        $this->assertSame('19] - "Sample-B.7z', (new CollectionsCleaningService)->collectionsCleaner($subject, 'alt.binaries.erotica')['name']);
        $this->migration()->up();
        $revision = Cache::get('collection_regexes_revision');
        $this->assertNotNull($revision);
        $this->assertSame($seeded, DB::table('collection_regexes')->orderBy('id')->get()->toJson());
        $this->assertSame('19] - "Sample-B', $reader->collectionsCleaner($subject, 'alt.binaries.erotica')['name']);
        $this->assertSame('19] - "Sample-B', (new CollectionsCleaningService)->collectionsCleaner($subject, 'alt.binaries.erotica')['name']);
        $this->migration()->up();
        $this->assertSame($revision, Cache::get('collection_regexes_revision'));
        $this->migration()->down();
        $this->assertNotSame($revision, Cache::get('collection_regexes_revision'));
        $this->assertSame($original, DB::table('collection_regexes')->orderBy('id')->get()->toJson());
        $this->assertSame('19] - "Sample-B.7z', $reader->collectionsCleaner($subject, 'alt.binaries.erotica')['name']);
        $revision = Cache::get('collection_regexes_revision');
        $this->migration()->down();
        $this->assertSame($revision, Cache::get('collection_regexes_revision'));
    }

    public function test_distinct_stems_and_totals_remain_distinct_through_collection_storage(): void
    {
        $stems = ['Sample-A1', 'Sample-A2', 'Sample_1', 'Sample_11', 'Sample.7z', 'Sample.2026',
            'Sample.1080p', 'Sample-vol1', 'Sample-par2'];
        for ($i = 0; $i < 100; $i++) {
            $stems[] = 'Fixture-'.substr(hash('sha256', 'scope-'.$i), 0, 18).($i % 10);
        }
        $article = 10000;
        foreach ($stems as $stem) {
            foreach ([19, 20] as $total) {
                $headers = [];
                foreach (['.7z.001', '.par2'] as $index => $suffix) {
                    $header = $this->archiveHeaders('7z')[0];
                    $header['Number'] = ++$article;
                    $header['Message-ID'] = '<distinct-'.$article.'@example.invalid>';
                    $header['Subject'] = sprintf('%s [%02d/%d] - "%s%s" yEnc (1/2)', $stem, $index + 1, $total, $stem, $suffix);
                    $headers[] = $header;
                }
                $this->ingest($headers);
                $collection = DB::table('collections')->where('collectionhash', sha1($total.'] - "'.$stem.$total, true))->sole();
                $this->assertSame(284, (int) $collection->collection_regexes_id);
                $this->assertSame($total, (int) $collection->declaredfiles);
                $this->assertSame(2, DB::table('binaries')->where('collections_id', $collection->id)->count());
            }
        }
        $this->assertSame(count($stems) * 2, DB::table('collections')->count());
    }
}
