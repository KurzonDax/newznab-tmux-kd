<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class NormalizeImportedSegmentMessageIdsMigrationTest extends TestCase
{
    private const string MIGRATION = 'migrations/2026_10_17_120000_normalize_imported_nzb_segment_message_ids.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('normalize-import-ids').DIRECTORY_SEPARATOR]);
        ProductionTables::fromAuthority()->create('settings', ['name', 'value']);
        DB::table('settings')->insert(['name' => 'nzbsplitlevel', 'value' => '1']);
        ProductionTables::fromAuthority()->create('releases', ['id', 'guid', 'is_imported', 'collectionhash']);
    }

    public function test_a_bracketed_stored_nzb_is_rewritten_bare_and_its_hash_recomputed(): void
    {
        $this->insertRelease(1, 'a', ['<b&c@example.com>', '<a@example.com>'], hashOf: ['<a@example.com>', '<b&c@example.com>']);

        $this->migrate();

        $this->assertSame(['b&c@example.com', 'a@example.com'], $this->storedSegmentIds('a'));
        $this->assertStringContainsString('>b&amp;c@example.com</segment>', $this->storedNzb('a'));
        $this->assertSame($this->hashOf(['a@example.com', 'b&c@example.com']), $this->hash(1));
    }

    public function test_a_bare_stored_nzb_keeps_its_file_and_gets_the_bare_hash(): void
    {
        $this->insertRelease(1, 'a', ['a@example.com', 'b@example.com'], hashOf: ['<a@example.com>', '<b@example.com>']);
        $before = $this->storedBytes('a');

        $this->migrate();

        $this->assertSame($before, $this->storedBytes('a'));
        $this->assertSame($this->hashOf(['a@example.com', 'b@example.com']), $this->hash(1));
    }

    public function test_an_already_bare_release_keeps_its_file_and_hash(): void
    {
        $this->insertRelease(1, 'a', ['a@example.com', 'b@example.com'], hashOf: ['a@example.com', 'b@example.com']);
        $before = $this->storedBytes('a');

        $this->migrate();

        $this->assertSame($before, $this->storedBytes('a'));
        $this->assertSame($this->hashOf(['a@example.com', 'b@example.com']), $this->hash(1));
    }

    public function test_a_recomputed_hash_held_by_another_release_leaves_the_row_and_warns(): void
    {
        Log::spy();
        $this->insertRelease(1, 'a', ['<a@example.com>'], hashOf: ['<a@example.com>']);
        DB::table('releases')->insert(['id' => 2, 'guid' => $this->guid('b'), 'is_imported' => 0, 'collectionhash' => $this->hashOf(['a@example.com'])]);

        $this->migrate();

        $this->assertSame($this->hashOf(['<a@example.com>']), $this->hash(1));
        $this->assertSame($this->hashOf(['a@example.com']), $this->hash(2));
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context = []): bool => ($context['release_id'] ?? null) === 1
                && ($context['conflicting_release_id'] ?? null) === 2
        )->once();
    }

    public function test_a_release_without_a_readable_nzb_is_skipped(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => $this->guid('a'), 'is_imported' => 1, 'collectionhash' => $this->hashOf(['<a@example.com>'])]);
        $this->insertRelease(2, 'b', ['<b@example.com>'], hashOf: ['<b@example.com>']);
        file_put_contents($this->nzbPath('b'), gzencode('<nzb><file>'));

        $this->migrate();

        $this->assertSame($this->hashOf(['<a@example.com>']), $this->hash(1));
        $this->assertSame($this->hashOf(['<b@example.com>']), $this->hash(2));
    }

    public function test_a_release_that_was_not_imported_is_left_alone(): void
    {
        $this->insertRelease(1, 'a', ['<a@example.com>'], hashOf: ['<a@example.com>'], imported: false);
        $before = $this->storedBytes('a');

        $this->migrate();

        $this->assertSame($before, $this->storedBytes('a'));
        $this->assertSame($this->hashOf(['<a@example.com>']), $this->hash(1));
    }

    private function migrate(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    /**
     * @param  list<string>  $segments  Message-IDs as the stored NZB holds them.
     * @param  list<string>  $hashOf  Message-IDs the row's current collectionhash was computed from.
     */
    private function insertRelease(int $id, string $seed, array $segments, array $hashOf, bool $imported = true): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $this->guid($seed),
            'is_imported' => $imported ? 1 : 0,
            'collectionhash' => $this->hashOf($hashOf),
        ]);

        $segmentXml = '';
        foreach ($segments as $number => $messageId) {
            $segmentXml .= '<segment bytes="1000" number="'.($number + 1).'">'.htmlspecialchars($messageId).'</segment>';
        }
        file_put_contents($this->nzbPath($seed), gzencode(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file poster="p@example.com" date="1700000000" subject="Release yEnc (1/1)">'
            .'<groups><group>alt.test</group></groups><segments>'.$segmentXml.'</segments></file></nzb>'."\n"
        ));
    }

    private function guid(string $seed): string
    {
        return sha1($seed);
    }

    private function nzbPath(string $seed): string
    {
        return app(NzbService::class)->getNzbPath($this->guid($seed), 0, true);
    }

    private function storedBytes(string $seed): string
    {
        return (string) file_get_contents($this->nzbPath($seed));
    }

    private function storedNzb(string $seed): string
    {
        $contents = gzdecode($this->storedBytes($seed));
        $this->assertIsString($contents);

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function storedSegmentIds(string $seed): array
    {
        $nzb = simplexml_load_string($this->storedNzb($seed));
        $this->assertInstanceOf(\SimpleXMLElement::class, $nzb);
        $ids = [];
        foreach ($nzb->file->segments->segment as $segment) {
            $ids[] = (string) $segment;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $messageIds
     */
    private function hashOf(array $messageIds): string
    {
        sort($messageIds);

        return sha1(implode("\n", $messageIds), true);
    }

    private function hash(int $id): ?string
    {
        $hash = DB::table('releases')->where('id', $id)->value('collectionhash');

        return $hash === null ? null : (string) $hash;
    }
}
