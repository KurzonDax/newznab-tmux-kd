<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TvProcessing\Providers\LocalDbProvider;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class TvProviderAddEpisodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProductionTables::fromAuthority()->create('tv_episodes');
    }

    #[Test]
    public function it_returns_the_id_of_the_inserted_episode_row(): void
    {
        $this->insertEpisode(videoId: 7, series: 1, episode: 1);
        $this->insertEpisode(videoId: 7, series: 1, episode: 2);

        $episodeId = (new LocalDbProvider)->addEpisode(7, $this->episode(series: 1, episode: 3, firstaired: '2024-01-15'));

        $this->assertSame($this->episodeIdFor(7, 1, 3), $episodeId);
        $this->assertNotSame(1, $episodeId);
    }

    #[Test]
    public function a_repeated_call_returns_the_same_row_without_inserting_another(): void
    {
        $this->insertEpisode(videoId: 7, series: 1, episode: 1);
        $provider = new LocalDbProvider;
        $episode = $this->episode(series: 2, episode: 4, firstaired: '2025-03-01');

        $firstId = $provider->addEpisode(7, $episode);
        $secondId = $provider->addEpisode(7, $episode);

        $this->assertSame($this->episodeIdFor(7, 2, 4), $firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, DB::table('tv_episodes')->where(['videos_id' => 7, 'series' => 2, 'episode' => 4])->count());
    }

    #[Test]
    public function a_special_without_an_air_date_resolves_to_one_row(): void
    {
        $this->insertEpisode(videoId: 7, series: 1, episode: 1);
        $provider = new LocalDbProvider;
        $episode = $this->episode(series: 0, episode: 5, firstaired: '');

        $firstId = $provider->addEpisode(7, $episode);
        $secondId = $provider->addEpisode(7, $episode);

        $this->assertSame($this->episodeIdFor(7, 0, 5), $firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, DB::table('tv_episodes')->where(['videos_id' => 7, 'series' => 0, 'episode' => 5])->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function episode(int $series, int $episode, string $firstaired): array
    {
        return [
            'series' => $series,
            'episode' => $episode,
            'se_complete' => sprintf('S%02dE%02d', $series, $episode),
            'title' => 'Episode '.$episode,
            'firstaired' => $firstaired,
            'summary' => '',
        ];
    }

    private function insertEpisode(int $videoId, int $series, int $episode): void
    {
        DB::table('tv_episodes')->insert([
            'videos_id' => $videoId,
            'series' => $series,
            'episode' => $episode,
            'se_complete' => sprintf('S%02dE%02d', $series, $episode),
            'title' => 'Episode '.$episode,
            'firstaired' => null,
            'summary' => '',
        ]);
    }

    private function episodeIdFor(int $videoId, int $series, int $episode): int
    {
        return (int) DB::table('tv_episodes')
            ->where(['videos_id' => $videoId, 'series' => $series, 'episode' => $episode])
            ->value('id');
    }
}
