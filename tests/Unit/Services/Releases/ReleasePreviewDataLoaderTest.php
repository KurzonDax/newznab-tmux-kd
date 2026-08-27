<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Releases;

use App\Services\Releases\ReleasePreviewDataLoader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleasePreviewDataLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('release_audio_tags');
        Schema::create('release_audio_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id')->unique();
            $table->string('audio_format', 50)->nullable();
            $table->unsignedTinyInteger('has_preview')->default(0);
            $table->string('preview_extension', 8)->nullable();
            $table->string('preview_mime', 32)->nullable();
            $table->unsignedSmallInteger('preview_seconds')->nullable();
            $table->unsignedTinyInteger('has_spectrogram')->default(0);
            $table->timestamps();
        });

        Schema::dropIfExists('release_video_clips');
        Schema::create('release_video_clips', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id')->unique();
            $table->string('extension', 8);
            $table->string('mime', 32);
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('videostatus')->default(0);
        });
    }

    public function test_it_populates_preview_fields_for_many_rows_in_one_query(): void
    {
        DB::table('release_audio_tags')->insert([
            [
                'releases_id' => 10,
                'audio_format' => 'MPEG Audio',
                'has_preview' => 1,
                'preview_extension' => 'mp3',
                'preview_mime' => 'audio/mpeg',
                'preview_seconds' => 30,
                'has_spectrogram' => 1,
            ],
            [
                'releases_id' => 20,
                'audio_format' => null,
                'has_preview' => 0,
                'preview_extension' => null,
                'preview_mime' => null,
                'preview_seconds' => null,
                'has_spectrogram' => 1,
            ],
        ]);

        $playable = (object) ['id' => 10];
        $spectrogramOnly = (object) ['id' => 20];
        $withoutTags = (object) ['id' => 30];

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new ReleasePreviewDataLoader)->load([$playable, $spectrogramOnly, $withoutTags]);

        $audioTagQueries = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'release_audio_tags'),
        );

        $this->assertCount(1, $audioTagQueries);
        $this->assertTrue($playable->has_audio_preview);
        $this->assertSame('audio/mpeg', $playable->audio_preview_mime);
        $this->assertSame('30s · MP3 · stream copy', $playable->audio_preview_meta);
        $this->assertTrue($playable->has_spectrogram);

        $this->assertFalse($spectrogramOnly->has_audio_preview);
        $this->assertNull($spectrogramOnly->audio_preview_mime);
        $this->assertNull($spectrogramOnly->audio_preview_meta);
        $this->assertTrue($spectrogramOnly->has_spectrogram);

        $this->assertFalse($withoutTags->has_audio_preview);
        $this->assertNull($withoutTags->audio_preview_mime);
        $this->assertNull($withoutTags->audio_preview_meta);
        $this->assertFalse($withoutTags->has_spectrogram);

        $this->assertFalse($playable->has_video_preview);
        $this->assertNull($playable->video_preview_mime);
    }

    public function test_it_reports_clips_and_legacy_transcodes_as_the_one_video_artifact(): void
    {
        DB::table('releases')->insert([
            ['id' => 10, 'videostatus' => 1],
            ['id' => 20, 'videostatus' => 1],
            ['id' => 30, 'videostatus' => 0],
        ]);
        DB::table('release_video_clips')->insert([
            [
                'releases_id' => 10,
                'extension' => 'mp4',
                'mime' => 'video/mp4',
                'duration_seconds' => 30,
                'bytes' => 12345,
            ],
        ]);

        $withClip = (object) ['id' => 10];
        $legacyTranscode = (object) ['id' => 20];
        $withoutVideo = (object) ['id' => 30];

        (new ReleasePreviewDataLoader)->load([$withClip, $legacyTranscode, $withoutVideo]);

        $this->assertTrue($withClip->has_video_preview);
        $this->assertSame('video/mp4', $withClip->video_preview_mime);

        $this->assertTrue($legacyTranscode->has_video_preview, 'A legacy OGV sample is still the video artifact.');
        $this->assertSame('video/ogg', $legacyTranscode->video_preview_mime);

        $this->assertFalse($withoutVideo->has_video_preview);
        $this->assertNull($withoutVideo->video_preview_mime);
    }
}
