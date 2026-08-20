<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * `parseNzb()` measuring completion is a fallback, not the measurement.
 *
 * Releases formed from headers get `completion` at creation time, from the CBP rows themselves.
 * Imported NZBs never had CBP rows, so this path is the only measurement they will ever get --
 * but it must never overwrite a creation-time value with what it can regex back out of an NZB.
 */
class NzbContentsCompletionFallbackTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbRoot = $this->makeTempDirectory('nntmux-parse-nzb-completion');
        config(['nntmux_settings.path_to_nzbs' => $this->nzbRoot]);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'nzbsplitlevel' => '1',
            'lookuppar2' => '0',
            'nntpretries' => '0',
        ];
    }

    #[Test]
    public function an_imported_nzb_gets_its_only_measurement_here(): void
    {
        $this->releaseWithNzb(1, ['Imported.part01.rar yEnc (1/10)'], segmentsPerFile: 5, completion: 0.0);

        $this->service()->parseNzb($this->guid(1), 1, 1);

        $this->assertSame(50.0, $this->completionOf(1));
    }

    #[Test]
    public function it_never_overwrites_a_creation_time_measurement(): void
    {
        $this->releaseWithNzb(1, ['Imported.part01.rar yEnc (1/10)'], segmentsPerFile: 5, completion: 91.67);

        $this->service()->parseNzb($this->guid(1), 1, 1);

        $this->assertSame(91.67, $this->completionOf(1));
    }

    #[Test]
    public function an_imported_obfuscated_nzb_is_measured_in_files(): void
    {
        $subjects = [];
        for ($file = 1; $file <= 220; $file++) {
            $subjects[] = sprintf('[%d/240] - "9f2c1b%03d" yEnc (1/240)', $file, $file);
        }

        $this->releaseWithNzb(1, $subjects, segmentsPerFile: 1, completion: 0.0);

        $this->service()->parseNzb($this->guid(1), 1, 1);

        $this->assertEqualsWithDelta(91.67, $this->completionOf(1), 0.01);
    }

    #[Test]
    public function subjects_declaring_no_totals_keep_the_never_measured_sentinel(): void
    {
        $this->releaseWithNzb(1, ['Imported.part01.rar yEnc'], segmentsPerFile: 4, completion: 0.0);

        $this->service()->parseNzb($this->guid(1), 1, 1);

        $this->assertSame(0.0, $this->completionOf(1));
    }

    private function service(): NzbContentsService
    {
        // The NNTP/NFO/PAR2 collaborators stay out of it: `lookuppar2` is off and no NFO check is
        // requested, so nothing in this path reaches the network.
        return new NzbContentsService(app(NzbService::class), new NzbParserService);
    }

    private function guid(int $id): string
    {
        return sprintf('%032x', $id);
    }

    /**
     * @param  list<string>  $subjects  One subject per `<file>` element.
     */
    private function releaseWithNzb(int $id, array $subjects, int $segmentsPerFile, float $completion): void
    {
        $guid = $this->guid($id);

        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $guid,
            'nzbstatus' => 1,
            'nfostatus' => -1,
            'completion' => $completion,
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";

        foreach ($subjects as $index => $subject) {
            $xml .= '  <file poster="p@example.org" date="1700000000" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'">'."\n"
                .'    <groups><group>alt.binaries.test</group></groups>'."\n    <segments>\n";

            for ($number = 1; $number <= $segmentsPerFile; $number++) {
                $xml .= '      <segment bytes="900" number="'.$number.'">file'.$index.'part'.$number.'@host</segment>'."\n";
            }

            $xml .= "    </segments>\n  </file>\n";
        }

        $xml .= '</nzb>'."\n";

        file_put_contents(app(NzbService::class)->getNzbPath($guid, 0, true), gzencode($xml));
    }

    private function completionOf(int $id): float
    {
        return (float) DB::table('releases')->where('id', $id)->value('completion');
    }

    private function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT -1,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            completion DOUBLE NOT NULL DEFAULT 0
        )');
    }
}
