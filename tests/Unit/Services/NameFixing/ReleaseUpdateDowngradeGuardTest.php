<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Category;
use App\Services\NameFixing\NameRejection;
use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\ReleaseUpdateService;
use Tests\TestCase;

class ReleaseUpdateDowngradeGuardTest extends TestCase
{
    public function test_untrusted_filename_cannot_replace_a_more_informative_scene_name(): void
    {
        $updates = new ReleaseUpdateService;

        $result = $updates->updateRelease(
            $this->release('The.Odd.Life.of.Timothy.Green.2012.NTSC.DVDR-SCREAM'),
            'Tolotg-Scream',
            'fileCheck: Scene RAR release',
            false,
            NameSource::FileName,
            true,
            false,
        );

        $this->assertFalse($result->renamed);
        $this->assertSame(NameRejection::LessInformative, $result->rejection);
    }

    public function test_untrusted_filename_can_replace_an_obfuscated_name(): void
    {
        $updates = new ReleaseUpdateService;

        $result = $updates->updateRelease(
            $this->release('d41d8cd98f00b204e9800998ecf8427e'),
            'Tolotg-Scream',
            'fileCheck: Scene RAR release',
            false,
            NameSource::FileName,
            true,
            false,
        );

        $this->assertTrue($result->renamed);
    }

    public function test_predb_candidate_bypasses_the_downgrade_guard(): void
    {
        $updates = new ReleaseUpdateService;

        $result = $updates->updateRelease(
            $this->release('The.Odd.Life.of.Timothy.Green.2012.NTSC.DVDR-SCREAM'),
            'Tolotg-Scream',
            'preDB: Match',
            false,
            NameSource::FilePreDbFilenameMatch,
            true,
            false,
            123,
        );

        $this->assertTrue($result->renamed);
    }

    private function release(string $searchName): object
    {
        return (object) [
            'releases_id' => 229,
            'searchname' => $searchName,
            'categories_id' => Category::MOVIE_OTHER,
        ];
    }
}
