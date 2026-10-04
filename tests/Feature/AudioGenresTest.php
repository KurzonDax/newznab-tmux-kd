<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AudioProcessing\AudioGenres;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * An audio release's tag genres are `release_audio_genres` rows, one per genre in the tag
 * value's order, each an `audio_genres` row kept apart from the shared `genres` table. The fill
 * migration splits the genre values of existing tag rows.
 */
final class AudioGenresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProductionTables::fromAuthority()->create('releases', ['id', 'categories_id']);
        foreach (['genres', 'release_audio_tags', 'audio_genres', 'release_audio_genres'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
    }

    /**
     * @return array<string, array{?string, list<string>}>
     */
    public static function splitValues(): array
    {
        return [
            'semicolon' => ['Rock; Pop', ['Rock', 'Pop']],
            'empty parts, padding and a repeat' => [' Rock ;; Pop ; rock ', ['Rock', 'Pop']],
            'unknown in any case' => ['Rock; UNKNOWN; unknown', ['Rock']],
            'only unknown' => ['Unknown', []],
            'comma is part of a name' => ['Synth-pop, Disco', ['Synth-pop, Disco']],
            'unspaced slash' => ['Pop/Rock', ['Pop/Rock']],
            'unspaced slash with hyphen' => ['Hip-Hop/Rap', ['Hip-Hop/Rap']],
            'spaced slash' => ['Pop / Rock', ['Pop', 'Rock']],
            'several spaced slashes' => ['Rock / Folk Rock / Psychedelic Rock', ['Rock', 'Folk Rock', 'Psychedelic Rock']],
            'both separators and a repeat' => ['Rock / Pop; Jazz / rock', ['Rock', 'Pop', 'Jazz']],
            'unspaced then spaced slash' => ['Pop/Rock / Jazz', ['Pop/Rock', 'Jazz']],
            'null' => [null, []],
            'blank' => ['  ', []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('splitValues')]
    public function test_split_reads_a_tag_value_as_genre_names(?string $value, array $expected): void
    {
        $this->assertSame($expected, AudioGenres::split($value));
    }

    public function test_ids_finds_a_name_without_regard_to_case(): void
    {
        $rock = (int) DB::table('audio_genres')->insertGetId(['name' => 'rock']);

        $this->assertSame([$rock], (new AudioGenres)->ids(['Rock']));
        $this->assertSame([$rock], (new AudioGenres)->ids(['ROCK']));
        $this->assertSame(1, DB::table('audio_genres')->count());
    }

    public function test_ids_stores_an_unknown_name_once_as_written(): void
    {
        $genres = new AudioGenres;

        $first = $genres->ids(['Folk Rock']);
        $second = $genres->ids(['Folk Rock']);

        $this->assertSame($first, $second);
        $this->assertSame(['Folk Rock'], DB::table('audio_genres')->pluck('name')->all());
    }

    public function test_ids_takes_the_lowest_id_when_several_rows_match(): void
    {
        $upper = (int) DB::table('audio_genres')->insertGetId(['name' => 'Rock']);
        DB::table('audio_genres')->insert(['name' => 'rock']);

        $this->assertSame([$upper], (new AudioGenres)->ids(['ROCK']));
    }

    public function test_ids_keeps_each_row_once(): void
    {
        $ids = (new AudioGenres)->ids(['Rock', 'rock']);

        $this->assertCount(1, $ids);
        $this->assertSame(1, DB::table('audio_genres')->count());
    }

    public function test_ids_never_reads_or_writes_the_shared_genres_table(): void
    {
        DB::table('genres')->insert(['id' => 7, 'title' => 'Jazz', 'type' => 3000, 'disabled' => 0]);

        $ids = (new AudioGenres)->ids(['Jazz']);

        $this->assertSame([(int) DB::table('audio_genres')->where('name', 'Jazz')->value('id')], $ids);
        $this->assertSame([['id' => 7, 'title' => 'Jazz']], DB::table('genres')->get(['id', 'title'])->map(static fn (object $row): array => (array) $row)->all());
    }

    public function test_replace_writes_the_genres_in_order(): void
    {
        $this->release(1);
        [$rock, $pop, $jazz] = $this->genreIds('Rock', 'Pop', 'Jazz');
        $genres = new AudioGenres;

        $genres->replace(1, [$pop, $jazz, $rock]);

        $this->assertSame([$pop, $jazz, $rock], $genres->stored(1));
        $this->assertSame(
            [[$pop, 0], [$jazz, 1], [$rock, 2]],
            DB::table('release_audio_genres')->where('releases_id', 1)->orderBy('position')->get()
                ->map(static fn (object $row): array => [(int) $row->audio_genres_id, (int) $row->position])->all(),
        );
    }

    public function test_replace_leaves_only_the_new_rows_and_other_releases_alone(): void
    {
        $this->release(1);
        $this->release(2);
        [$rock, $pop, $jazz] = $this->genreIds('Rock', 'Pop', 'Jazz');
        $genres = new AudioGenres;
        $genres->replace(1, [$rock, $pop]);
        $genres->replace(2, [$rock]);

        $genres->replace(1, [$jazz]);
        $this->assertSame([$jazz], $genres->stored(1));

        $genres->replace(1, []);
        $this->assertSame([], $genres->stored(1));
        $this->assertSame([$rock], $genres->stored(2));
    }

    public function test_a_failed_replace_writes_nothing_from_its_transaction(): void
    {
        $this->release(1);
        [$rock, $pop] = $this->genreIds('Rock', 'Pop');
        $genres = new AudioGenres;
        $genres->replace(1, [$rock]);

        try {
            $genres->replace(1, [$pop, $pop], static function (): void {
                DB::table('release_audio_tags')->insert(['releases_id' => 1, 'genre' => 'Pop']);
            });
            $this->fail('A repeated genre must break the primary key.');
        } catch (QueryException) {
        }

        $this->assertSame(0, DB::table('release_audio_tags')->count());
        $this->assertSame([$rock], $genres->stored(1));
    }

    public function test_the_fill_writes_one_row_per_genre_for_tag_rows_with_genres(): void
    {
        $this->tagRow(1, 'Rock; Pop');
        $this->tagRow(2, 'Unknown');
        $this->tagRow(3, null);
        $this->tagRow(4, 'Synth-pop, Disco');
        $this->tagRow(5, 'Jazz', 2040);
        $this->tagRow(6, 'Americana / Country');
        DB::table('genres')->insert(['id' => 1, 'title' => 'Rock', 'type' => 3000, 'disabled' => 0]);

        $this->fill();

        $this->assertSame(['Rock', 'Pop'], $this->storedNames(1));
        $this->assertSame([], $this->storedNames(2));
        $this->assertSame([], $this->storedNames(3));
        $this->assertSame(['Synth-pop, Disco'], $this->storedNames(4));
        $this->assertSame(['Jazz'], $this->storedNames(5));
        $this->assertSame(['Americana', 'Country'], $this->storedNames(6));
        $this->assertSame(
            [[0, 'Americana'], [1, 'Country']],
            DB::table('release_audio_genres')->join('audio_genres', 'audio_genres.id', '=', 'release_audio_genres.audio_genres_id')
                ->where('releases_id', 6)->orderBy('position')->get(['position', 'name'])
                ->map(static fn (object $row): array => [(int) $row->position, $row->name])->all(),
        );
        $this->assertSame(['Rock'], DB::table('genres')->pluck('title')->all());
    }

    public function test_the_fill_rewrites_stale_rows_and_a_second_run_changes_nothing(): void
    {
        $this->tagRow(1, 'Rock; Pop');
        [$jazz] = $this->genreIds('Jazz');
        (new AudioGenres)->replace(1, [$jazz]);

        $this->fill();
        $this->assertSame(['Rock', 'Pop'], $this->storedNames(1));

        $links = DB::table('release_audio_genres')->orderBy('releases_id')->orderBy('position')->get()->map(static fn (object $row): array => (array) $row)->all();
        $names = DB::table('audio_genres')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();

        $this->fill();

        $this->assertSame($links, DB::table('release_audio_genres')->orderBy('releases_id')->orderBy('position')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame($names, DB::table('audio_genres')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
    }

    private function fill(): void
    {
        (require database_path('migrations/2026_10_04_000100_fill_release_audio_genres.php'))->up();
    }

    private function release(int $id, int $categoriesId = 3010): void
    {
        DB::table('releases')->insert(['id' => $id, 'categories_id' => $categoriesId]);
    }

    private function tagRow(int $releasesId, ?string $genre, int $categoriesId = 3040): void
    {
        $this->release($releasesId, $categoriesId);
        DB::table('release_audio_tags')->insert(['releases_id' => $releasesId, 'genre' => $genre]);
    }

    /**
     * @return list<int>
     */
    private function genreIds(string ...$names): array
    {
        return array_map(static fn (string $name): int => (int) DB::table('audio_genres')->insertGetId(['name' => $name]), $names);
    }

    /**
     * @return list<string>
     */
    private function storedNames(int $releasesId): array
    {
        return DB::table('release_audio_genres')->join('audio_genres', 'audio_genres.id', '=', 'release_audio_genres.audio_genres_id')
            ->where('releases_id', $releasesId)->orderBy('position')->pluck('name')->all();
    }
}
