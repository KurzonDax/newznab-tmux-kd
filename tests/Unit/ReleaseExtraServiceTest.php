<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facades\Search;
use App\Models\MediaInfo as MediaInfoRecord;
use App\Services\ReleaseExtraService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\Type\General;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseExtraServiceTest extends TestCase
{
    #[Test]
    public function it_reindexes_the_release_once_after_media_info_persistence(): void
    {
        Search::shouldReceive('updateRelease')
            ->once()
            ->with(42);

        (new ReleaseExtraService)->addFromXml(42, new MediaInfoContainer);
    }

    #[Test]
    #[DataProvider('mediaNames')]
    public function it_persists_media_names_without_invalid_media_unique_id_sentinels(
        mixed $movieName,
        mixed $fileName,
        ?string $expectedMovieName,
        ?string $expectedFileName,
    ): void {
        Schema::create('media_infos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->string('movie_name')->nullable();
            $table->string('file_name')->nullable();
            $table->string('unique_id')->nullable();
            $table->timestamps();
        });

        $general = new General;
        $general->set('movie_name', $movieName);
        $general->set('file_name', $fileName);
        $general->set('unique_id', '0x0');

        $mediaInfo = new MediaInfoContainer;
        $mediaInfo->setGeneral($general);

        try {
            MediaInfoRecord::addData(43, $mediaInfo);
            $record = MediaInfoRecord::query()->where('releases_id', 43)->firstOrFail();
        } finally {
            Schema::drop('media_infos');
        }

        $this->assertSame($expectedMovieName, $record->movie_name);
        $this->assertSame($expectedFileName, $record->file_name);
        $this->assertNull($record->unique_id);
    }

    /** @return array<string, array{mixed, mixed, ?string, ?string}> */
    public static function mediaNames(): array
    {
        return [
            'ordinary title' => ['Example Movie', null, 'Example Movie', null],
            'multiple titles' => [['', 'Example Movie', 'Other Title'], 'movie.mkv', 'Example Movie', 'movie.mkv'],
            'multiple filenames' => ['Example Movie', ['', 'movie.mkv', 'other.mkv'], 'Example Movie', 'movie.mkv'],
            'both arrays' => [['Example Movie'], ['movie.mkv'], 'Example Movie', 'movie.mkv'],
            'empty arrays' => [[], [], null, null],
            'blank arrays' => [['', " \t"], ["\n", ''], null, null],
            'mixed arrays' => [[null, 3, false, [], 'Example Movie'], [null, [], 'movie.mkv'], 'Example Movie', 'movie.mkv'],
            'no usable strings' => [[null, 3, false, []], [true, 7], null, null],
            'null fields' => [null, null, null, null],
            'scalar strings unchanged' => [' Example Movie ', '', ' Example Movie ', ''],
        ];
    }
}
