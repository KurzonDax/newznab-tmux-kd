<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\AudioTrack;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Release;
use App\Models\Settings;
use App\Models\User;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\Releases\ReleaseSearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\AssertsFollowWording;
use Tests\Support\AssertsNoRetiredAddress;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The Audio release details pages, GET /details/{guid} (issue #963; docs/proposals/audio-redesign/SPEC.md
 * 5A, 5B, 5C.1 and 5C.2; DATA-CONTRACT.md 4.5; the details checks of prototype/check.mjs): the album
 * page of a release whose tags name an album, the release-only page of any other, the preview in the
 * Overview, the Tracks tab, every release of the album and Similar releases.
 */
final class AudioReleasePageTest extends TestCase
{
    use AssertsFollowWording;
    use AssertsNoRetiredAddress;
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const MP3 = 3010;

    private const VIDEO = 3020;

    private const LOSSLESS = 3040;

    private const AUDIO_OTHER = 3999;

    /** A sub-category an admin added under Audio, which the Audio list's Category order does not list. */
    private const CUSTOM = 3070;

    private const EBOOK = 7020;

    private const ROCK = 11;

    private const METAL = 12;

    private const GB = 1073741824;

    private const GROUP = '0f8a3c49-6b3e-4b0e-9d1c-1f2e3d4c5b6a';

    private ?User $user = null;

    private int $nextRelease = 0;

    private int $nextEvidence = 0;

    private string $covers = '';

    /** @var list<int> the ids of the rows searchSimilar answers with */
    private array $similarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        Carbon::setTestNow('2026-10-04 12:00:00');
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'name', 'searchname', 'guid', 'display_name', 'categories_id', 'category_band', 'size', 'totalpart',
            'adddate', 'postdate', 'grabs', 'comments', 'completion', 'declaredfiles', 'nzbstatus', 'passwordstatus', 'nfostatus',
            'haspreview', 'jpgstatus', 'videostatus', 'groups_id', 'fromname', 'isrenamed', 'additional_pp_claim_token', 'imdbid', 'movieinfo_id',
            'videos_id', 'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'predb_id', 'resolution', 'source']);
        foreach (['usenet_groups', 'users_releases', 'user_series', 'user_movies', 'videos', 'movieinfo', 'release_audio_tags', 'release_video_clips',
            'languages', 'release_audio_languages', 'releases_groups', 'release_regexes', 'release_comments', 'release_nfos', 'video_data', 'audio_data',
            'release_subtitles', 'media_infos', 'media_info_probes', 'media_info_tracks', 'predb', 'release_tv_episodes', 'tv_episodes', 'tv_info', 'networks',
            'video_genres', 'video_people', 'genres', 'audio_genres', 'release_audio_genres',
            'release_audio_evidence', 'release_audio_evidence_tracks', 'release_music_identifications', 'music_cover_art_lookups'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert([['id' => 3000, 'title' => 'Audio', 'status' => 1], ['id' => 7000, 'title' => 'Books', 'status' => 1]]);
        foreach ([self::MP3 => 'MP3', self::VIDEO => 'Video', self::LOSSLESS => 'Lossless', self::CUSTOM => 'Vinyl rips', self::AUDIO_OTHER => 'Other'] as $id => $title) {
            DB::table('categories')->insert(['id' => $id, 'title' => $title, 'root_categories_id' => 3000, 'status' => 1]);
        }
        DB::table('categories')->insert(['id' => self::EBOOK, 'title' => 'Ebook', 'root_categories_id' => 7000, 'status' => 1]);
        DB::table('usenet_groups')->insert(['id' => 99, 'name' => 'alt.binaries.sounds.mp3']);
        DB::table('audio_genres')->insert([['id' => self::ROCK, 'name' => 'Rock'], ['id' => self::METAL, 'name' => 'Heavy Metal']]);
        $this->covers = $this->makeTempDirectory('audio-release-covers');
        config(['nntmux_settings.covers_path' => $this->covers]);
        $search = Mockery::mock(ReleaseSearchService::class)->makePartial();
        $search->shouldReceive('searchSimilar')->andReturnUsing(fn (): array => Release::query()->whereIn('id', $this->similarIds)->get()->all());
        $this->app->instance(ReleaseSearchService::class, $search);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_release_with_an_album_gets_the_album_page_with_the_release_name_on_top_and_the_music_line_under_it(): void
    {
        $id = $this->album('Nama-Fibir-2021-MP3');

        $response = $this->details($id)->assertOk()->assertViewIs('details.audio.index');

        $crumbs = $this->between($response, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>');
        $this->assertSame('<a href="'.route('audio.releases').'">Audio releases</a><span aria-hidden="true">›</span><span>MP3</span>', (string) preg_replace('/>\s+</', '><', $crumbs));
        $art = $this->between($response, '<div class="tv-show-art is-square" data-part="album cover">', '<h1');
        $this->assertStringContainsString('<div class="tv-show-card is-film"><i class="fas fa-compact-disc" aria-hidden="true"></i><span class="tv-tile-card-title">Fibir</span><small>2021</small></div>', $art);
        $this->assertStringNotContainsString('<img', $art);
        $response->assertDontSee('No cover');
        $head = $this->between($response, '<div class="tv-show-head">', '<div class="tv-details-columns is-release-only">');
        $this->assertStringContainsString('<h1 class="is-release-name" data-part="details heading">Nama-Fibir-2021-MP3</h1>', $head);
        $this->assertSame('<span>Nama</span><span>–</span><b>Fibir</b><span aria-hidden="true">·</span><span>2021</span><span aria-hidden="true">·</span><span>MP3</span>', $this->musicLine($response));
        $this->assertSame(1, substr_count((string) $response->getContent(), '<h1'));
        $response->assertSee('x-data="movieReleaseDetails"', false)->assertSee('data-nzb-link-base="'.url('/api/v1/api').'"', false)
            ->assertSee('<div class="tv-chips tv-details-chips">', false)->assertSee('<div class="tv-chips tv-details-origin">', false);
        $this->assertNoRetiredAddress((string) $response->getContent(), 'Audio album page');
        $this->assertNoWatchWording((string) $response->getContent(), 'Audio album page');

        $noArtist = $this->album('Untitled.Album-MP3', ['album' => 'Untitled', 'album_performer' => null, 'performer' => null, 'recorded_year' => null]);
        $this->assertSame('<b>Untitled</b><span aria-hidden="true">·</span><span>MP3</span>', $this->musicLine($this->details($noArtist)));
        $this->assertStringContainsString('<span class="tv-tile-card-title">Untitled</span></div>', $this->between($this->details($noArtist), 'data-part="album cover">', '<h1'));
    }

    public function test_an_album_with_a_stored_cover_shows_it_in_the_square_in_place_of_the_placeholder(): void
    {
        $id = $this->album('Nama-Fibir-2021-MP3');
        $this->identification($id, $this->evidence($id, 1, null), IdentificationStatus::AcceptedReleaseGroup->value, self::GROUP);
        $cover = $this->storedCover(self::GROUP);

        $art = $this->between($this->details($id)->assertOk(), '<div class="tv-show-art is-square" data-part="album cover">', '<h1');

        $this->assertStringContainsString('<img src="'.e($cover).'" alt="Fibir cover">', $art);
        $this->assertStringNotContainsString('tv-show-card', $art);
    }

    public function test_the_details_tables_carry_each_row_s_cover_to_the_listen_dialog_in_queries_that_do_not_grow_with_the_rows(): void
    {
        $cover = $this->storedCover(self::GROUP);
        $current = $this->album('Nama-Fibir-2021-MP3', $this->preview(), [], ['postdate' => '2026-09-20 10:00:00']);
        $this->identification($current, $this->evidence($current, 1, null), IdentificationStatus::AcceptedReleaseGroup->value, self::GROUP);
        $sibling = $this->album('Nama-Fibir-2021-FLAC', $this->preview(), [], ['categories_id' => self::LOSSLESS, 'postdate' => '2026-09-21 10:00:00']);
        $this->identification($sibling, $this->evidence($sibling, 1, null), IdentificationStatus::AcceptedReleaseGroup->value, self::GROUP);
        $bare = $this->album('Nama-Fibir-2021-WEB', $this->preview(), [], ['postdate' => '2026-09-19 10:00:00']);

        $section = $this->between($this->details($current)->assertOk(), 'data-film-releases>', '</section>');
        $this->assertStringContainsString('data-audio-cover="'.e($cover).'"', $this->rowOf($section, $sibling));
        $this->assertStringNotContainsString('data-audio-cover', $this->rowOf($section, $bare), 'no cover, no attribute');

        $few = $this->queriesOf($current);
        foreach (range(1, 12) as $index) {
            $more = $this->album('Nama-Fibir-2021-Copy-'.$index, $this->preview(), [], ['postdate' => '2026-09-18 10:00:00']);
            $this->identification($more, $this->evidence($more, 1, null), IdentificationStatus::AcceptedReleaseGroup->value, self::GROUP);
        }
        $this->assertSame(15, substr_count($this->between($this->details($current), 'data-film-releases>', '</section>'), 'data-release-row'));
        $this->assertSame($few, $this->queriesOf($current));
    }

    public function test_genre_tags_link_to_the_list_with_that_genre_alone_and_the_format_tag_shows_only_when_it_adds(): void
    {
        $id = $this->album('Nama-Fibir-2021-MP3', [], [self::METAL, self::ROCK]);

        $tags = $this->between($this->details($id), '<div class="tv-show-tags">', '</div>');
        preg_match_all('/<a class="tv-tag" href="([^"]+)">([^<]+)<\/a>/', $tags, $genres);
        $this->assertSame(['Heavy Metal', 'Rock'], $genres[2]);
        $this->assertSame([e(route('audio.releases', ['genre' => [self::METAL]])), e(route('audio.releases', ['genre' => [self::ROCK]]))], $genres[1]);
        $this->assertSame([], $this->plainTags($tags), 'MP3 (stored as MPEG Audio) is the sub-category\'s name');

        $unknown = $this->album('Unknown.Genre-MP3', ['genre' => 'Unknown']);
        $tags = $this->between($this->details($unknown), '<div class="tv-show-tags">', '</div>');
        $this->assertSame('<a class="tv-tag" href="'.e(route('audio.releases', ['genre' => ['unknown']])).'">Unknown</a>', trim($tags));
        $twin = $this->audio('Unknown.Genre.No.Album-MP3');
        $this->tag($twin, ['genre' => 'Unknown']);
        $this->assertSame('Unknown', $this->facts((string) $this->details($twin)->getContent())['Genre']);

        $wavPack = $this->album('Nama-Fibir-2021-WV', ['audio_format' => 'WavPack'], [], ['categories_id' => self::LOSSLESS]);
        $this->assertSame(['WavPack'], $this->plainTags($this->between($this->details($wavPack), '<div class="tv-show-tags">', '</div>')));

        $flac = $this->album('Nama-Fibir-2021-FLAC', ['audio_format' => 'FLAC'], [self::ROCK], ['categories_id' => self::LOSSLESS]);
        DB::table('audio_data')->insert(['releases_id' => $flac, 'audioid' => 1, 'audioformat' => 'FLAC', 'audiochannels' => '2']);
        $response = $this->details($flac);
        $this->assertStringContainsString('FLAC 2.0', $this->between($response, '<div class="tv-chips tv-details-chips">', '</div>'));
        $this->assertSame([], $this->plainTags($this->between($response, '<div class="tv-show-tags">', '</div>')), 'the media info chip already starts with FLAC');

        $bare = $this->album('No.Genre.No.Format-MP3', ['audio_format' => null]);
        $this->assertStringNotContainsString('tv-show-tags', (string) $this->details($bare)->getContent());
    }

    public function test_the_tracks_and_performed_by_info_lines(): void
    {
        $id = $this->album('Nama-Fibir-2021-MP3', ['album_performer' => 'Nama', 'performer' => 'Nama feat. Guest']);
        $evidence = $this->evidence($id, 1, null);
        foreach (range(1, 12) as $number) {
            $this->track($evidence, 'nzb', $number, ['track_number' => $number, 'whole_duration_seconds' => 240]);
        }

        $lines = $this->infoLines($this->between($this->details($id), '<div class="tv-show-head">', '<div class="tv-details-actions tv-show-actions">'));
        $this->assertSame(['Tracks' => '12 · 48 min', 'Performed by' => 'Nama feat. Guest'], $lines);

        DB::table('release_audio_evidence_tracks')->where('source_ordinal', 3)->update(['whole_duration_seconds' => null]);
        DB::table('release_audio_tags')->where('releases_id', $id)->update(['performer' => 'Nama']);
        $this->assertSame(['Tracks' => '12'], $this->infoLines($this->between($this->details($id), '<div class="tv-show-head">', '<div class="tv-details-actions tv-show-actions">')));

        DB::table('release_audio_tags')->where('releases_id', $id)->update(['album_performer' => null, 'performer' => 'Solo']);
        DB::table('release_audio_evidence_tracks')->delete();
        $this->assertSame([], $this->infoLines($this->between($this->details($id), '<div class="tv-show-head">', '<div class="tv-details-actions tv-show-actions">')));

        $hour = $this->album('Long.Album-MP3');
        $long = $this->evidence($hour, 1, null);
        foreach (range(1, 3) as $number) {
            $this->track($long, 'nzb', $number, ['whole_duration_seconds' => 1500.4]);
        }
        $this->assertSame(['Tracks' => '3 · 1 h 15 min'], $this->infoLines($this->between($this->details($hour), '<div class="tv-show-head">', '<div class="tv-details-actions tv-show-actions">')));
    }

    public function test_the_musicbrainz_button_opens_the_accepted_release_group_of_the_newest_evidence_in_a_new_tab(): void
    {
        $id = $this->album('Nama-Fibir-2021-MP3', ['musicbrainz_release_group_id' => '11111111-1111-1111-1111-111111111111']);
        $old = $this->evidence($id, 1, null);
        $newest = $this->evidence($id, 2, null);
        $this->identification($id, $old, IdentificationStatus::AcceptedReleaseGroup->value, '22222222-2222-2222-2222-222222222222');
        $this->assertStringContainsString('href="https://musicbrainz.org/release-group/22222222-2222-2222-2222-222222222222"', $this->actions($id),
            'the newest evidence has no decision yet: the previous completed one stays; the tag\'s own id is never used');

        $recording = $this->identification($id, $newest, IdentificationStatus::AcceptedRecording->value, self::GROUP);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($this->actions($id)), 'an accepted recording names no album and withdraws the older one');

        DB::table('release_music_identifications')->where('id', $recording)->update(['state' => IdentificationStatus::AcceptedReleaseGroup->value]);
        $actions = $this->actions($id);
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart', 'MusicBrainz'], $this->buttons($actions));
        $this->assertStringContainsString('<a class="tv-details-button is-secondary" href="https://musicbrainz.org/release-group/'.self::GROUP.'" target="_blank" rel="noopener noreferrer">MusicBrainz<i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="sr-only"> (opens in a new tab)</span></a>', $actions);

        DB::table('release_music_identifications')->where('id', $recording)->update(['state' => IdentificationStatus::AcceptedEdition->value]);
        $this->assertStringContainsString('href="https://musicbrainz.org/release-group/'.self::GROUP.'"', $this->actions($id), 'an accepted edition names its release group too');

        // The configured algorithm version's row is the current one; another version's accepted row for the hash is never read.
        DB::table('release_music_identifications')->where('id', $recording)->update(['state' => IdentificationStatus::Unresolved->value, 'musicbrainz_release_group_id' => null]);
        $this->identification($id, $newest, IdentificationStatus::AcceptedReleaseGroup->value, '33333333-3333-3333-3333-333333333333', 'music-identity-v0');
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart'], $this->buttons($this->actions($id)));
        config(['music-identity.algorithm_version' => 'music-identity-v0']);
        $this->assertStringContainsString('musicbrainz.org/release-group/33333333-3333-3333-3333-333333333333', $this->actions($id), 'the configured version is read');
    }

    public function test_the_release_only_page_shows_the_button_too_behind_the_site_s_dereferrer(): void
    {
        Settings::query()->updateOrInsert(['name' => 'dereferrer_link'], ['value' => 'https://deref.example/?']);
        $id = $this->audio('No.Album.Release-MP3');
        $this->tag($id, ['performer' => 'Someone']);
        $evidence = $this->evidence($id, 1, null);
        $this->identification($id, $evidence, IdentificationStatus::AcceptedReleaseGroup->value, self::GROUP);

        $response = $this->details($id)->assertOk()->assertViewIs('details.shelf.index');
        $actions = $this->between($response, '<div class="tv-details-actions">', '<div class="tv-details-columns');
        $this->assertSame(['Download NZB', 'Copy NZB link', 'Add to cart', 'MusicBrainz'], $this->buttons($actions));
        $this->assertStringContainsString('<a class="tv-details-button is-secondary" href="https://deref.example/?https://musicbrainz.org/release-group/'.self::GROUP.'" target="_blank" rel="noopener noreferrer">MusicBrainz', $actions);

    }

    public function test_nothing_announces_the_tabs_and_only_the_release_only_page_has_a_genre_fact(): void
    {
        $id = $this->album('Nama-Fibir-2021-MP3', [], [self::ROCK]);

        $response = $this->details($id)->assertOk();
        $html = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/<h[1-6]/', $this->between($response, '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-tabs"'));
        foreach (['This release', 'About the album', 'tv-about', 'tv-details-aside'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertSame(['Category', 'Size', 'Files', 'Completion', 'Posted', 'Added', 'Grabs', 'Group', 'Poster', 'Password status'], array_keys($this->facts($html)));
        $this->assertSame('Audio &gt; MP3', $this->facts($html)['Category']);

        $single = $this->audio('Some.Single-MP3');
        $this->tag($single, ['performer' => 'Someone', 'genre' => 'Rock'], [self::ROCK, self::METAL]);
        $facts = $this->facts((string) $this->details($single)->getContent());
        $this->assertSame(['Category', 'Genre', 'Size', 'Files', 'Completion', 'Posted', 'Added', 'Grabs', 'Group', 'Poster', 'Password status'], array_keys($facts));
        $this->assertSame('Rock, Heavy Metal', $facts['Genre']);

        $bare = $this->audio('Untagged.Upload-MP3');
        $this->assertSame('—', $this->facts((string) $this->details($bare)->getContent())['Genre']);
    }

    public function test_a_release_with_no_album_gets_the_release_only_page_with_the_audio_breadcrumb(): void
    {
        $id = $this->audio('No.Album.Release-MP3');
        $this->tag($id, ['performer' => 'Someone', 'recorded_year' => 2020]);

        $response = $this->details($id)->assertOk()->assertViewIs('details.shelf.index');
        $crumbs = $this->between($response, '<nav class="tv-crumbs" aria-label="Breadcrumb">', '</nav>');
        $this->assertSame('<a href="'.route('audio.releases').'">Audio releases</a><span aria-hidden="true">›</span><span>MP3</span>', (string) preg_replace('/>\s+</', '><', $crumbs));
        $response->assertSee('<h1 class="is-release-name" data-part="details heading">No.Album.Release-MP3</h1>', false)
            ->assertDontSee('tv-show-art', false)->assertDontSee('tv-show-head', false)->assertDontSee('data-part="music line"', false)->assertDontSee('tv-game-line', false);
        $this->assertNoRetiredAddress((string) $response->getContent(), 'Audio release-only page');
    }

    public function test_audio_release_pages_render_no_retired_address(): void
    {
        $album = $this->album('Tagged.Album.Release-MP3');
        $untagged = $this->audio('Untagged.Release-MP3');

        $this->assertNoRetiredAddress((string) $this->details($album)->assertOk()->assertViewIs('details.audio.index')->getContent(), 'Audio album page');
        $this->assertNoRetiredAddress((string) $this->details($untagged)->assertOk()->assertViewIs('details.shelf.index')->getContent(), 'Audio page of a release with no tag row');
    }

    public function test_the_overview_opens_with_the_preview_player_above_the_spectrogram_and_before_the_facts(): void
    {
        $id = $this->album('Nama-Fibir-2021-MP3', ['track_name' => 'Badarzefol', ...$this->preview()]);
        $this->spectrogram($id);

        $overview = $this->between($this->details($id), '<section id="overview" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-overview" data-details-panel>', '</section>');
        $block = $this->betweenText($overview, '<div class="tv-audio-preview" data-part="audio preview">', '<dl class="tv-details-facts">');
        $this->assertStringContainsString('<div class="tv-audio-preview-line"><b>Badarzefol</b><span>30-second preview</span></div>', $block);
        $this->assertStringContainsString('<audio controls preload="metadata" src="'.route('preview.audio', $this->guid($id)).'" aria-label="30-second preview of Nama-Fibir-2021-MP3"></audio>', $block);
        $this->assertStringNotContainsString('autoplay', $block);
        $spectrogram = url('/covers/audiosample/'.$this->guid($id).'_spectrum.png');
        $this->assertMatchesRegularExpression('/<\/audio>\s*<button type="button" class="tv-details-preview preview-badge" data-guid="'.$this->guid($id).'" data-release-display-name="Nama-Fibir-2021-MP3" data-image-url="'.preg_quote($spectrogram, '/').'"\s+data-image-title="Spectrogram" aria-label="View the spectrogram"><img src="'.preg_quote($spectrogram, '/').'" alt=""><span class="tv-details-picture-label is-top">Spectrogram<\/span><\/button>/', $block);
        $this->assertLessThan(strpos($overview, '<dl class="tv-details-facts">'), strpos($overview, 'tv-audio-preview'));

        DB::table('release_audio_tags')->where('releases_id', $id)->update(['track_name' => null]);
        $this->assertStringContainsString('<div class="tv-audio-preview-line"><span>30-second preview</span></div>', (string) $this->details($id)->getContent());

        unlink($this->covers.'/audiosample/'.$this->guid($id).'_spectrum.png');
        $response = $this->details($id)->assertSee('<audio controls', false);
        $response->assertDontSee('data-image-title="Spectrogram"', false);
        $this->spectrogram($id);
        DB::table('release_audio_tags')->where('releases_id', $id)->update(['has_spectrogram' => 0]);
        $this->details($id)->assertSee('<audio controls', false)->assertDontSee('data-image-title="Spectrogram"', false);

        DB::table('release_audio_tags')->where('releases_id', $id)->update(['has_preview' => 0]);
        $this->details($id)->assertDontSee('tv-audio-preview', false)->assertDontSee('<audio', false);

        $single = $this->audio('Some.Single-MP3');
        $this->tag($single, ['performer' => 'Someone', ...$this->preview()]);
        $this->details($single)->assertViewIs('details.shelf.index')->assertSee('<div class="tv-audio-preview" data-part="audio preview">', false);
    }

    public function test_no_listen_chip_on_the_page_s_own_chip_line_while_table_rows_keep_it_and_a_video_clip_shows_the_preview_chip(): void
    {
        $current = $this->album('Nama-Fibir-2021-MP3', $this->preview(), [], ['postdate' => '2026-09-20 10:00:00']);
        $sibling = $this->album('Nama-Fibir-2021-FLAC', $this->preview(), [], ['categories_id' => self::LOSSLESS, 'postdate' => '2026-09-21 10:00:00']);
        $similar = $this->album('Nama-Other-2020-MP3', ['album' => 'Other', ...$this->preview()], [], ['postdate' => '2026-09-19 10:00:00']);
        $this->similarIds = [$similar, $sibling];

        $response = $this->details($current)->assertOk();
        $own = $this->between($response, '<div class="tv-chips tv-details-chips">', '<div class="tv-details-actions tv-show-actions">');
        $this->assertStringNotContainsString('listen-badge', $own);
        $this->assertStringNotContainsString('Listen', $own);
        $all = $this->between($response, 'data-film-releases>', '</section>');
        $similarSection = $this->between($response, 'data-similar-releases>', '</section>');
        foreach ([$all, $similarSection] as $section) {
            $this->assertMatchesRegularExpression('/class="release-chip chip-tone-clip listen-badge"[^>]*data-audio-url="[^"]+"[^>]*>\s*Listen\s*<\/button>/', $section);
            $this->assertStringContainsString('<span class="tv-game-line">Nama – ', $section);
        }
        $this->assertStringContainsString('<span class="tv-game-line">Nama – Fibir · 2021</span>', $all);
        $this->assertStringContainsString('<span class="tv-game-line">Nama – Other · 2021</span>', $similarSection);
        $response->assertSee('x-data="tvListenDialog"', false)->assertDontSee('clip-badge', false);

        // A music video's clip: the Preview chip with a play icon in Preview's slot, its poster read
        // from the files on disk though the shelf rows show no image chips.
        DB::table('releases')->where('id', $current)->update(['videostatus' => 1]);
        DB::table('release_video_clips')->insert(['releases_id' => $current, 'extension' => 'mp4', 'mime' => 'video/mp4']);
        if (! is_dir($this->covers.'/sample')) {
            mkdir($this->covers.'/sample', 0777, true);
        }
        file_put_contents($this->covers.'/sample/'.$this->guid($current).'_thumb.jpg', 'jpg');
        $response = $this->details($current);
        $own = $this->between($response, '<div class="tv-chips tv-details-chips">', '<div class="tv-details-actions tv-show-actions">');
        $this->assertMatchesRegularExpression('/class="release-chip chip-tone-preview preview-badge"[^>]*data-video-url="'.preg_quote(route('preview.video', $this->guid($current)), '/').'" data-video-type="video\/mp4"'
            .' data-poster-url="'.preg_quote(url('/covers/sample/'.$this->guid($current).'_thumb.jpg'), '/').'" data-image-title="Video preview"[^>]*title="Play the video preview"[^>]*>\s*<i class="fas fa-play" aria-hidden="true"><\/i>\s*Preview\s*<\/button>\s*<\/div>/', $own);
        $this->assertStringNotContainsString('listen-badge', $own);
        $this->assertStringNotContainsString('clip-badge', (string) $response->getContent());
        foreach (['data-film-releases>', 'data-similar-releases>'] as $section) {
            $this->assertStringNotContainsString('preview-badge', $this->between($response, $section, '</section>'), 'the page\'s clip stays on its own chip line');
        }

        $single = $this->audio('Some.Single-MP3');
        $this->tag($single, ['performer' => 'Someone', ...$this->preview()]);
        $own = $this->between($this->details($single)->assertViewIs('details.shelf.index'), '<div class="tv-chips tv-details-chips">', '<div class="tv-details-actions">');
        $this->assertStringNotContainsString('listen-badge', $own, 'the release-only page\'s own chip line has no Listen either');
        $this->assertStringNotContainsString('Listen', $own);
        $this->assertStringNotContainsString('preview-badge', $own);

        // A music video without an album: the release-only page's chip line plays its clip from the Preview chip.
        DB::table('releases')->where('id', $single)->update(['videostatus' => 1]);
        DB::table('release_video_clips')->insert(['releases_id' => $single, 'extension' => 'mp4', 'mime' => 'video/mp4']);
        $own = $this->between($this->details($single)->assertViewIs('details.shelf.index'), '<div class="tv-chips tv-details-chips">', '<div class="tv-details-actions">');
        $this->assertSame(1, substr_count($own, 'preview-badge'));
        $this->assertMatchesRegularExpression('/class="release-chip chip-tone-preview preview-badge"[^>]*data-video-url="'.preg_quote(route('preview.video', $this->guid($single)), '/').'" data-video-type="video\/mp4"'
            .' data-image-title="Video preview"[^>]*title="Play the video preview"[^>]*>\s*<i class="fas fa-play" aria-hidden="true"><\/i>\s*Preview\s*<\/button>/', $own);
    }

    public function test_the_tracks_tab_shows_only_a_complete_list_of_the_newest_revision(): void
    {
        $id = $this->album('Nama-Fibir-2021-FLAC');
        $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($this->details($id)));
        $this->details($id)->assertDontSee('id="tracks"', false);

        $old = $this->evidence($id, 1, true);
        $this->track($old, 'archive', 1, ['title' => 'Old revision']);
        foreach (['release_file', 'sampled'] as $revision => $kind) {
            $alone = $this->evidence($id, 2 + $revision, null);
            $this->track($alone, $kind, 1, ['title' => $kind]);
            $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($this->details($id)), 'only '.$kind.' tracks');
        }
        $newest = $this->evidence($id, 9, null);
        $this->track($newest, 'archive', 1, ['title' => 'Partial archive']);
        $this->track($newest, 'release_file', 1, ['title' => 'Release file']);
        $this->track($newest, 'sampled', 1, ['title' => 'Sampled']);
        $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($this->details($id)), 'a partial archive listing, release files and the sampled file are never shown');
        $this->assertSame([], $this->infoLines((string) $this->details($id)->getContent()));
        DB::table('release_audio_evidence')->where('id', $newest)->update(['archive_manifest_complete' => 0]);
        $this->assertSame(['Overview', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($this->details($id)));

        $this->track($newest, 'nzb', 1, ['title' => 'From the NZB']);
        $this->track($newest, 'nzb', 2, ['title' => 'Also from the NZB']);
        $response = $this->details($id);
        $this->assertSame(['Overview', 'Tracks (2)', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($response));
        $this->assertSame([['1', 'From the NZB'], ['2', 'Also from the NZB']], $this->trackRows($response));

        DB::table('release_audio_evidence')->where('id', $newest)->update(['archive_manifest_complete' => 1]);
        $response = $this->details($id);
        $this->assertSame(['Overview', 'Tracks (1)', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($response), 'a complete archive listing wins over the NZB\'s tracks');
        $this->assertSame([['1', 'Partial archive']], $this->trackRows($response));
        $this->assertSame(['Tracks' => '1'], $this->infoLines((string) $response->getContent()));
    }

    public function test_track_titles_lengths_and_disc_rows_follow_the_structural_rules(): void
    {
        $id = $this->album('Nama-Fibir-2021-FLAC');
        $evidence = $this->evidence($id, 1, null);
        $this->track($evidence, 'nzb', 1, ['raw_filename' => 'CD1/07 - Song.flac', 'track_number' => 7, 'disc_number' => 1, 'whole_duration_seconds' => 125.6]);
        $this->track($evidence, 'nzb', 2, ['raw_filename' => 'CD1\\104 - Catapult.flac', 'track_number' => 104, 'disc_number' => 1, 'whole_duration_seconds' => 59.4]);
        $this->track($evidence, 'nzb', 3, ['raw_filename' => 'CD2/01 - Tagged.flac', 'title' => 'The Tag Title', 'track_number' => 1, 'disc_number' => 2]);
        $this->track($evidence, 'nzb', 4, ['raw_filename' => '7 Days.flac', 'disc_number' => 2, 'whole_duration_seconds' => 61]);

        $response = $this->details($id);
        $this->assertSame(['Overview', 'Tracks (4)', 'Files (1)', 'Media info', 'NFO', 'Comments (0)'], $this->tabs($response));
        $panel = $this->between($response, '<section id="tracks" class="tv-details-panel" role="tabpanel" aria-labelledby="tab-tracks" data-details-panel hidden>', '</section>');
        $this->assertStringNotContainsString('tv-tracks-total', $panel, 'a track has no length');
        $this->assertSame(['#', 'Title', 'Length'], $this->headings($panel));
        $this->assertSame([['Disc 1'], ['7', 'Song', '2:06'], ['104', 'Catapult', '0:59'], ['Disc 2'], ['1', 'The Tag Title', ''], ['4', '7 Days', '1:01']], $this->cells($panel));

        DB::table('release_audio_evidence_tracks')->update(['disc_number' => 1, 'whole_duration_seconds' => null]);
        $panel = $this->between($this->details($id), 'data-details-panel hidden>', '</section>');
        $this->assertSame(['#', 'Title'], $this->headings($panel));
        $this->assertSame([['7', 'Song'], ['104', 'Catapult'], ['1', 'The Tag Title'], ['4', '7 Days']], $this->cells($panel));

        DB::table('release_audio_evidence_tracks')->update(['whole_duration_seconds' => 600]);
        $panel = $this->between($this->details($id), 'data-details-panel hidden>', '</section>');
        $this->assertStringContainsString('<p class="tv-tracks-total">40 min</p>', $panel);

        // Rounded to the minute before the hours: never "60 min" or "1 h 60 min".
        $track = static fn (int $seconds): AudioTrack => new AudioTrack(1, 'A', $seconds, null);
        $this->assertSame('1 h 0 min', AudioTrack::totalLength([$track(3590)]));
        $this->assertSame('2 h 0 min', AudioTrack::totalLength([$track(3600), $track(3590)]));
        $this->assertSame('59 min', AudioTrack::totalLength([$track(3529)]));
        $this->assertSame('1 h 1 min', AudioTrack::totalLength([$track(3660)]));
    }

    public function test_all_releases_of_the_album_list_the_visible_ones_matched_without_case_with_this_release_marked(): void
    {
        $current = $this->album('Nama-Fibir-2021-MP3', [], [], ['postdate' => '2026-09-20 10:00:00']);
        $otherCase = $this->album('NAMA-FIBIR-2021-FLAC', ['album' => 'FIBIR', 'album_performer' => 'NAMA'], [], ['categories_id' => self::LOSSLESS, 'postdate' => '2026-09-22 10:00:00']);
        $byPerformer = $this->album('Nama-Fibir-Performer-MP3', ['album_performer' => null, 'performer' => 'nama'], [], ['postdate' => '2026-09-21 10:00:00']);
        $emptyAlbumArtist = $this->album('Nama-Fibir-Empty-Album-Artist-MP3', ['album_performer' => '', 'performer' => 'Nama'], [], ['postdate' => '2026-09-20 09:00:00']);
        $this->album('Nama-Fibir-Hidden-Video', [], [], ['categories_id' => self::VIDEO, 'postdate' => '2026-09-23 10:00:00']);
        $this->album('Nama-Fibir-Passworded-MP3', [], [], ['passwordstatus' => 1, 'postdate' => '2026-09-24 10:00:00']);
        $this->album('Nama-Fibir-Ebook', [], [], ['categories_id' => self::EBOOK, 'postdate' => '2026-09-24 10:00:00']);
        $this->album('Other-Fibir-2021-MP3', ['album_performer' => 'Other Artist'], [], ['postdate' => '2026-09-24 10:00:00']);
        $this->album('Nama-Other-2021-MP3', ['album' => 'Another'], [], ['postdate' => '2026-09-24 10:00:00']);
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user()->id, 'categories_id' => self::VIDEO]);

        $response = $this->details($current)->assertOk();
        $section = $this->between($response, '<section class="tv-siblings tv-list-end" id="releases" aria-labelledby="album-releases-heading" x-ref="releases" data-film-releases>', '</section>');
        $this->assertStringContainsString('<h2 id="album-releases-heading">All 4 releases of this album</h2>', $section);
        $this->assertSame([$otherCase, $byPerformer, $current, $emptyAlbumArtist], $this->rowIds($section), 'an empty album artist falls back to the performer, as the music line does');
        $this->assertSame([$otherCase, $byPerformer, $current, $emptyAlbumArtist], $this->rowIds($this->between($this->details($emptyAlbumArtist), 'data-film-releases>', '</section>')));
        $this->assertMatchesRegularExpression('/<tr class="is-current"\s+aria-current="true"\s+data-release-row[^>]*>\s*<td class="tv-what">\s*<span class="tv-release-name" title="Nama-Fibir-2021-MP3">Nama-Fibir-2021-MP3<\/span>\s*<div class="tv-this-release">The release on this page<\/div>/', $section);
        $this->assertStringNotContainsString('href="'.route('details', $this->guid($current)).'"', $section);
        $this->assertSame(['Release', 'Category', 'Size', 'Files', 'Posted', 'Actions'], $this->headings($section));
        preg_match_all('/<button type="button" data-sort="([a-z]+)"/', $section, $sortable);
        $this->assertSame(['category', 'size', 'posted'], $sortable[1]);

        DB::table('releases')->whereIn('id', [$otherCase, $byPerformer, $emptyAlbumArtist])->delete();
        $this->assertStringContainsString('<h2 id="album-releases-heading">The only release of this album</h2>', (string) $this->details($current)->getContent());
    }

    public function test_a_release_whose_tags_name_no_artist_lists_only_the_album_s_releases_that_name_none(): void
    {
        $current = $this->album('Various-Fibir-MP3', ['album_performer' => null, 'performer' => null], [], ['postdate' => '2026-09-20 10:00:00']);
        $alsoNone = $this->album('Various-Fibir-FLAC', ['album' => 'fibir', 'album_performer' => '', 'performer' => null], [], ['postdate' => '2026-09-21 10:00:00']);
        $this->album('Nama-Fibir-2021-MP3', [], [], ['postdate' => '2026-09-22 10:00:00']);

        $section = $this->between($this->details($current), 'data-film-releases>', '</section>');
        $this->assertStringContainsString('All 2 releases of this album', $section);
        $this->assertSame([$alsoNone, $current], $this->rowIds($section));
    }

    public function test_the_table_pages_at_50_opens_on_the_page_holding_this_release_and_sorts_on_the_server(): void
    {
        $ids = [];
        foreach (range(1, 51) as $day) {
            // posted newest first puts day 1 last (page 2); size ascends with the day
            $ids[$day] = $this->album('Nama-Fibir-Day'.$day.'-MP3', [], [], ['postdate' => Carbon::parse('2026-07-01 10:00:00')->addDays($day)->toDateTimeString(), 'size' => $day * self::GB]);
        }
        $current = $ids[1];

        $opening = $this->between($this->details($current)->assertOk(), 'data-film-releases>', '</section>');
        $this->assertStringContainsString('All 51 releases of this album', $opening);
        $this->assertStringContainsString('Page 2 of 2', $opening);
        $this->assertSame([$current], $this->rowIds($opening));

        $first = $this->between($this->page($current, '?page=1'), 'data-film-releases>', '</section>');
        $this->assertCount(50, $this->rowIds($first));
        $this->assertStringNotContainsString('aria-current="true"', $first);
        $second = $this->between($this->page($current, '?page=2'), 'data-film-releases>', '</section>');
        $this->assertSame([$current], $this->rowIds($second));

        $smallest = $this->page($current, '?sort=size_asc&_fragment=releases')->assertOk()->assertViewIs('details.audio.releases');
        $fragment = (string) $smallest->getContent();
        $this->assertStringStartsWith('<h2 id="album-releases-heading">', trim($fragment));
        $this->assertStringNotContainsString('tv-show-head', $fragment);
        $this->assertSame(array_slice(array_values($ids), 0, 50), $this->rowIds($fragment));
        $this->assertSame(['size' => 'ascending'], $this->sortedHeadings($fragment));
        $this->assertStringContainsString('href="'.e(route('details', ['guid' => $this->guid($current), 'sort' => 'size_asc', 'page' => 2]).'#releases').'"', $fragment);
        $this->assertStringContainsString('href="'.e(route('details', ['guid' => $this->guid($current), 'page' => 1]).'#releases').'"', $opening);
        $this->assertStringContainsString('aria-current="true"', $fragment);

        $single = $this->audio('No.Album-MP3');
        $this->tag($single, ['performer' => 'Someone']);
        $this->page($single, '?_fragment=releases')->assertNotFound();
    }

    public function test_category_sorts_by_the_audio_list_s_category_order_with_an_admin_s_own_sub_category_last(): void
    {
        $current = $this->album('Nama-Fibir-MP3', [], [], ['postdate' => '2026-09-20 10:00:00']);
        $lossless = $this->album('Nama-Fibir-FLAC', [], [], ['categories_id' => self::LOSSLESS, 'postdate' => '2026-09-19 10:00:00']);
        $other = $this->album('Nama-Fibir-Other', [], [], ['categories_id' => self::AUDIO_OTHER, 'postdate' => '2026-09-21 10:00:00']);
        $custom = $this->album('Nama-Fibir-Vinyl', [], [], ['categories_id' => self::CUSTOM, 'postdate' => '2026-09-22 10:00:00']);
        $video = $this->album('Nama-Fibir-Video', [], [], ['categories_id' => self::VIDEO, 'postdate' => '2026-09-18 10:00:00']);

        $descending = $this->between($this->page($current, '?sort=category'), 'data-film-releases>', '</section>');
        $this->assertSame([$custom, $other, $lossless, $video, $current], $this->rowIds($descending));
        $this->assertSame(['category' => 'descending'], $this->sortedHeadings($descending));
        $ascending = $this->between($this->page($current, '?sort=category_asc'), 'data-film-releases>', '</section>');
        $this->assertSame([$current, $video, $lossless, $other, $custom], $this->rowIds($ascending));
        $this->assertSame(['category' => 'ascending'], $this->sortedHeadings($ascending));
    }

    public function test_similar_releases_leave_out_this_release_and_on_the_album_page_every_release_of_the_album(): void
    {
        $current = $this->album('Nama-Fibir-MP3');
        $sameAlbum = $this->album('Nama-Fibir-FLAC', ['album' => 'FIBIR'], [], ['categories_id' => self::LOSSLESS, 'postdate' => '2026-09-22 10:00:00']);
        $otherAlbum = $this->album('Nama-Other-MP3', ['album' => 'Other'], [], ['postdate' => '2026-09-21 10:00:00']);
        $noAlbum = $this->audio('Nama-Single-MP3', ['postdate' => '2026-09-23 10:00:00']);
        $this->similarIds = [$otherAlbum, $current, $sameAlbum, $noAlbum];

        $response = $this->details($current)->assertOk();
        $similar = $this->between($response, '<section class="tv-siblings tv-similar-releases" aria-labelledby="similar-releases-heading" data-similar-releases>', '</section>');
        $this->assertSame([$noAlbum, $otherAlbum], $this->rowIds($similar));
        $this->assertSame(['posted' => 'descending'], $this->sortedHeadings($similar, 'data-similar-sort'));
        preg_match_all('/data-category="(\d+)"/', $similar, $positions);
        $this->assertSame(['0', '0'], $positions[1], 'MP3 is first in the Audio list\'s Category order');
        $response->assertSee('<section class="tv-siblings" id="releases"', false);

        $this->similarIds = [$sameAlbum];
        $this->details($current)->assertOk()->assertDontSee('data-similar-releases', false)->assertSee('<section class="tv-siblings tv-list-end" id="releases"', false);

        // The release-only page leaves out only itself.
        $this->similarIds = [$sameAlbum, $noAlbum];
        $this->tag($noAlbum, ['performer' => 'Nama']);
        $this->similarIds = [$noAlbum, $current];
        $this->assertSame([$current], $this->rowIds($this->between($this->details($noAlbum), 'data-similar-releases>', '</section>')));
    }

    public function test_a_hidden_category_is_refused_and_a_comment_still_posts_to_the_details_url(): void
    {
        $hidden = $this->album('Nama-Fibir-Video', [], [], ['categories_id' => self::VIDEO]);
        DB::table('user_excluded_categories')->insert(['users_id' => $this->user()->id, 'categories_id' => self::VIDEO]);
        $this->details($hidden)->assertForbidden()->assertViewIs('errors.category-disabled')->assertDontSee('Nama-Fibir-Video');

        $id = $this->album('Nama-Fibir-MP3');
        $url = '/details/'.$this->guid($id);
        $this->actingAs($this->user())->post($url, ['txtAddComment' => 'Sounds great.'])->assertRedirect($url.'#comments');
        $response = $this->details($id)->assertOk()->assertSee('Sounds great.');
        $this->assertContains('Comments (1)', $this->tabs($response));
    }

    /**
     * A release with a tag row naming an album (by default "Nama – Fibir", 2021, MPEG Audio).
     *
     * @param  array<string, mixed>  $tag
     * @param  list<int>  $genres
     * @param  array<string, mixed>  $attributes
     */
    private function album(string $name, array $tag = [], array $genres = [], array $attributes = []): int
    {
        $id = $this->audio($name, $attributes);
        $this->tag($id, ['album' => 'Fibir', 'album_performer' => 'Nama', 'performer' => 'Nama', 'recorded_year' => 2021, 'genre' => null, 'audio_format' => 'MPEG Audio', ...$tag], $genres);

        return $id;
    }

    /** @param array<string, mixed> $attributes */
    private function audio(string $name, array $attributes = []): int
    {
        $number = ++$this->nextRelease;
        $posted = (string) ($attributes['postdate'] ?? '2026-09-20 10:00:00');

        return $this->release($name, ['categories_id' => self::MP3, 'passwordstatus' => 0, 'resolution' => 0, 'source' => 0, 'imdbid' => null,
            'movieinfo_id' => null, 'videos_id' => 0, 'tv_episodes_id' => 0, 'consoleinfo_id' => null, 'gamesinfo_id' => null, 'bookinfo_id' => null,
            'musicinfo_id' => null, 'anidbid' => null, 'completion' => 100, 'nfostatus' => 0, 'haspreview' => 0, 'jpgstatus' => 0, 'videostatus' => 0,
            'size' => self::GB, 'totalpart' => 1, 'groups_id' => 99, 'guid' => md5('audio release '.$number),
            'adddate' => Carbon::parse($posted)->addHour()->toDateTimeString(), ...$attributes, 'postdate' => $posted]);
    }

    /**
     * The release's tag row and its genre rows in order, as the audio processor writes them through AudioGenres.
     *
     * @param  array<string, mixed>  $tag
     * @param  list<int>  $genres
     */
    private function tag(int $releasesId, array $tag = [], array $genres = []): void
    {
        DB::table('release_audio_tags')->insert(['releases_id' => $releasesId, ...$tag]);
        foreach ($genres as $position => $genre) {
            DB::table('release_audio_genres')->insert(['releases_id' => $releasesId, 'audio_genres_id' => $genre, 'position' => $position]);
        }
    }

    /** @return array<string, mixed> a playable 30-second MP3 preview */
    private function preview(): array
    {
        return ['has_preview' => 1, 'preview_extension' => 'mp3', 'preview_mime' => 'audio/mpeg', 'preview_seconds' => 30];
    }

    private function spectrogram(int $id): void
    {
        DB::table('release_audio_tags')->where('releases_id', $id)->update(['has_spectrogram' => 1]);
        if (! is_dir($this->covers.'/audiosample')) {
            mkdir($this->covers.'/audiosample', 0777, true);
        }
        file_put_contents($this->covers.'/audiosample/'.$this->guid($id).'_spectrum.png', 'png');
    }

    private function evidence(int $releasesId, int $revision, ?bool $archiveComplete): int
    {
        $number = ++$this->nextEvidence;

        return DB::table('release_audio_evidence')->insertGetId([
            'releases_id' => $releasesId, 'revision' => $revision, 'evidence_hash' => hash('sha256', 'evidence '.$number), 'schema_version' => 1,
            'provenance' => 'test', 'release_snapshot' => '{}', 'archive_manifest_complete' => $archiveComplete, 'nzb_manifest' => '[]',
            'archive_manifest' => '[]', 'sidecar_manifest' => '[]', 'captured_at' => '2026-09-20 10:00:00',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function track(int $evidenceId, string $kind, int $ordinal, array $attributes = []): void
    {
        DB::table('release_audio_evidence_tracks')->insert(['release_audio_evidence_id' => $evidenceId, 'source_kind' => $kind, 'source_ordinal' => $ordinal,
            'raw_filename' => sprintf('Disc/%02d - Track %d.flac', $ordinal, $ordinal), ...$attributes]);
    }

    private function identification(int $releasesId, int $evidenceId, string $state, ?string $group, ?string $version = null): int
    {
        $version ??= (string) config('music-identity.algorithm_version');

        return DB::table('release_music_identifications')->insertGetId([
            'releases_id' => $releasesId, 'release_audio_evidence_id' => $evidenceId,
            'evidence_hash' => DB::table('release_audio_evidence')->where('id', $evidenceId)->value('evidence_hash'),
            'state' => $state, 'band' => 'high', 'musicbrainz_release_group_id' => $group, 'reasons' => '[]', 'feature_contributions' => '[]',
            'algorithm_version' => $version, 'resolver_version' => 'r1', 'normalizer_version' => 'n1', 'scorer_version' => 's1', 'policy_version' => 'p1',
        ]);
    }

    /** A stored Cover Art Archive front for the release group: its lookup row and its file under the covers root; returns its URL. */
    private function storedCover(string $groupId): string
    {
        DB::table('music_cover_art_lookups')->insert(['kind' => 'release-group', 'musicbrainz_id' => $groupId, 'outcome' => 'stored', 'image_musicbrainz_id' => $groupId,
            'attempt_count' => 1, 'checked_at' => now()]);
        if (! is_dir($this->covers.'/audio')) {
            mkdir($this->covers.'/audio', 0777, true);
        }
        file_put_contents($this->covers.'/audio/'.$groupId.'.jpg', 'jpg');

        return url('/covers/audio/'.$groupId.'.jpg');
    }

    /** The queries of the second open of a release's details page. */
    private function queriesOf(int $id): int
    {
        $this->details($id)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->details($id)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function rowOf(string $html, int $id): string
    {
        foreach (explode('<tr ', $html) as $row) {
            if (str_contains($row, 'value="'.$this->guid($id).'"') || str_contains($row, 'data-guid="'.$this->guid($id).'"')) {
                return strstr($row, '</tr>', true) ?: $row;
            }
        }
        $this->fail('No row for release '.$id);
    }

    private function user(): User
    {
        return $this->user ??= $this->browserUser();
    }

    private function guid(int $id): string
    {
        return (string) DB::table('releases')->where('id', $id)->value('guid');
    }

    private function details(int $id): TestResponse
    {
        return $this->page($id, '');
    }

    private function page(int $id, string $query): TestResponse
    {
        $this->resetGlobalComposerState();

        return $this->actingAs($this->user())->get('/details/'.$this->guid($id).$query);
    }

    private function actions(int $id): string
    {
        return $this->between($this->details($id), '<div class="tv-details-actions tv-show-actions">', '<div class="tv-details-columns');
    }

    private function musicLine(TestResponse $response): string
    {
        return $this->between($response, '<div class="tv-show-meta tv-film-meta tv-game-line" data-part="music line">', '</div>');
    }

    /** @return list<string> */
    private function tabs(TestResponse $response): array
    {
        preg_match_all('/<button type="button" role="tab"[^>]*>([^<]+)<\/button>/', (string) $response->getContent(), $tabs);

        return array_map('trim', $tabs[1]);
    }

    /** @return list<string> */
    private function plainTags(string $tags): array
    {
        preg_match_all('/<span class="tv-tag tv-tag-plain">([^<]+)<\/span>/', $tags, $plain);

        return $plain[1];
    }

    /** @return array<string, string> label => value */
    private function infoLines(string $html): array
    {
        preg_match_all('/<div class="tv-starring">([^<]+) <span class="tv-starring-value">([^<]+)<\/span><\/div>/', $html, $lines);

        return array_combine($lines[1], $lines[2]);
    }

    /** @return list<string> */
    private function buttons(string $html): array
    {
        preg_match_all('/<\/i>(?:<span[^>]*>)*([^<]+)|<a class="tv-details-button is-secondary" href="[^"]*" target="_blank" rel="noopener noreferrer">([^<]+)/', $html, $labels);

        return array_values(array_filter(array_map(static fn (string $a, string $b): string => trim($a.$b), $labels[1], $labels[2]), static fn (string $label): bool => $label !== '' && $label !== '(opens in a new tab)'));
    }

    /** @return array<string, string> the first facts grid's labels and values, in order */
    private function facts(string $html): array
    {
        preg_match('/<dl class="tv-details-facts">(.*?)<\/dl>/s', $html, $match);
        preg_match_all('/<dt[^>]*>([^<]+)<\/dt><dd[^>]*>([^<]*)<\/dd>/', $match[1] ?? '', $facts);

        return array_combine($facts[1], $facts[2]);
    }

    /** @return list<array{string, string}> the Tracks table's number and title cells */
    private function trackRows(TestResponse $response): array
    {
        $panel = $this->between($response, '<section id="tracks"', '</section>');

        return array_map(static fn (array $row): array => [$row[0], $row[1]], $this->cells($panel));
    }

    /** @return list<list<string>> each body row's cell texts */
    private function cells(string $html): array
    {
        preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $body[1] ?? '', $rows);

        return array_map(static function (string $row): array {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            return array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);
        }, $rows[1]);
    }

    /** @return list<int> release ids in table order */
    private function rowIds(string $html): array
    {
        preg_match_all('/data-copy-nzb="([0-9a-f]{32})"/', $html, $matches);
        $ids = DB::table('releases')->whereIn('guid', $matches[1])->pluck('id', 'guid');

        return array_map(static fn (string $guid): int => (int) $ids[$guid], $matches[1]);
    }

    /** @return list<string> */
    private function headings(string $html): array
    {
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $cells);

        return array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);
    }

    /** @return array<string, string> the sorted heading's key and direction */
    private function sortedHeadings(string $html, string $attribute = 'data-sort'): array
    {
        preg_match_all('/<th[^>]*aria-sort="([a-z]+)"[^>]*><button type="button" '.$attribute.'="([a-z]+)"/', $html, $sorted);

        return array_combine($sorted[2], $sorted[1]);
    }

    private function between(TestResponse $response, string $from, string $to): string
    {
        return $this->betweenText((string) $response->getContent(), $from, $to);
    }

    private function betweenText(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Missing '.$from);
        $start += strlen($from);

        return trim(substr($html, $start, strpos($html, $to, $start) - $start));
    }
}
