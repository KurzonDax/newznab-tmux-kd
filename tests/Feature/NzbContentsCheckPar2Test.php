<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * `checkPar2()` must only fetch articles for files whose subject looks like a
 * PAR2 index, and only a bounded number of them: a 600-file NZB on a provider
 * that tarpits 430s used to cost 600 article requests and stall the Fix Names
 * pane for hours.
 */
class NzbContentsCheckPar2Test extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot = '';

    /** @var Par2Processor&MockInterface */
    private Par2Processor $par2Processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbRoot = $this->makeTempDirectory('nntmux-check-par2');
        config(['nntmux_settings.path_to_nzbs' => $this->nzbRoot]);

        $this->createSchema();
        $this->par2Processor = Mockery::mock(Par2Processor::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
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
    public function only_the_par2_looking_file_is_fetched(): void
    {
        $subjects = [];
        for ($file = 1; $file <= 40; $file++) {
            $subjects[] = sprintf('Some.Release.part%02d.rar yEnc (1/50)', $file);
        }
        $subjects[] = 'Some.Release.par2" yEnc (1/1)';
        $this->releaseWithNzb(1, $subjects);

        $this->par2Processor->shouldReceive('parseFromMessage')
            ->once()
            ->with('file40part1@host', 1, 7, Mockery::type(NNTPService::class), 0)
            ->andReturnTrue();

        $service = $this->service();
        $this->assertTrue($service->checkPar2($this->guid(1), 1, 7, 1, 0));
        $this->assertSame(1, $this->procPar2Of(1));
        $this->assertSame(['files' => 41, 'attempts' => 1], $service->lastPar2Stats());
    }

    #[Test]
    public function an_nzb_without_par2_subjects_fetches_nothing_and_is_marked_processed(): void
    {
        $this->releaseWithNzb(1, ['Some.Release.part01.rar yEnc (1/50)', 'Some.Release.part02.rar yEnc (1/50)']);

        $this->par2Processor->shouldNotReceive('parseFromMessage');

        $this->assertFalse($this->service()->checkPar2($this->guid(1), 1, 7, 1, 0));
        $this->assertSame(1, $this->procPar2Of(1));
    }

    #[Test]
    public function fetch_attempts_are_capped_per_release(): void
    {
        $subjects = [];
        for ($file = 1; $file <= 10; $file++) {
            $subjects[] = sprintf('Some.Release.vol%02d+01.par2" yEnc (1/1)', $file);
        }
        $this->releaseWithNzb(1, $subjects);

        $this->par2Processor->shouldReceive('parseFromMessage')
            ->times(NzbContentsService::MAX_PAR2_FETCH_ATTEMPTS)
            ->andReturnFalse();

        $service = $this->service();
        $this->assertFalse($service->checkPar2($this->guid(1), 1, 7, 1, 0));
        $this->assertSame(1, $this->procPar2Of(1));
        $this->assertSame(['files' => 10, 'attempts' => NzbContentsService::MAX_PAR2_FETCH_ATTEMPTS], $service->lastPar2Stats());
    }

    #[Test]
    public function the_first_successful_parse_wins(): void
    {
        $this->releaseWithNzb(1, [
            'Some.Release.vol00+01.par2" yEnc (1/1)',
            'Some.Release.vol01+02.par2" yEnc (1/1)',
            'Some.Release.vol03+04.par2" yEnc (1/1)',
        ]);

        $this->par2Processor->shouldReceive('parseFromMessage')->once()->with('file0part1@host', 1, 7, Mockery::any(), 1)->andReturnFalse();
        $this->par2Processor->shouldReceive('parseFromMessage')->once()->with('file1part1@host', 1, 7, Mockery::any(), 1)->andReturnTrue();

        $this->assertTrue($this->service()->checkPar2($this->guid(1), 1, 7, 1, 1));
        $this->assertSame(1, $this->procPar2Of(1));
    }

    #[Test]
    public function nothing_is_fetched_or_flagged_when_name_status_is_off(): void
    {
        $this->releaseWithNzb(1, ['Some.Release.par2" yEnc (1/1)']);

        $this->par2Processor->shouldNotReceive('parseFromMessage');

        $this->assertFalse($this->service()->checkPar2($this->guid(1), 1, 7, 0, 0));
        $this->assertSame(0, $this->procPar2Of(1));
    }

    private function service(): NzbContentsService
    {
        return new NzbContentsService(
            app(NzbService::class),
            new NzbParserService,
            Mockery::mock(NNTPService::class),
            postProcessService: new PostProcessService(par2Processor: $this->par2Processor),
        );
    }

    private function guid(int $id): string
    {
        return sprintf('%032x', $id);
    }

    /**
     * @param  list<string>  $subjects  One subject per `<file>` element.
     */
    private function releaseWithNzb(int $id, array $subjects): void
    {
        $guid = $this->guid($id);

        DB::table('releases')->insert(['id' => $id, 'guid' => $guid, 'nzbstatus' => 1]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";
        foreach ($subjects as $index => $subject) {
            $xml .= '  <file poster="p@example.org" date="1700000000" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'">'."\n"
                .'    <groups><group>alt.binaries.test</group></groups>'."\n    <segments>\n"
                .'      <segment bytes="900" number="1">file'.$index.'part1@host</segment>'."\n"
                ."    </segments>\n  </file>\n";
        }
        $xml .= '</nzb>'."\n";

        file_put_contents(app(NzbService::class)->getNzbPath($guid, 0, true), gzencode($xml));
    }

    private function procPar2Of(int $id): int
    {
        return (int) DB::table('releases')->where('id', $id)->value('proc_par2');
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
