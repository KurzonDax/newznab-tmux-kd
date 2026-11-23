<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupQuery;
use App\Services\MusicIdentity\DTO\ReleaseNameAlbumMatch;
use App\Services\MusicIdentity\DTO\ReleaseQuery;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Matching\ReleaseNameAlbumParser;
use App\Services\MusicIdentity\ReleaseNameAlbumCandidates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1033: which MusicBrainz release group a release name identifies.
 *
 * @phpstan-import-type MusicReleaseGroup from CandidateMetadata
 */
final class ReleaseNameAlbumCandidatesTest extends TestCase
{
    private const string GROUP = '15e65e08-85d1-3145-85b2-e18b9fbd4cba';

    private const string OTHER_GROUP = '7bfae355-44ea-3be6-89de-40147131be51';

    private const string THIRD_GROUP = '2c5e4a0e-6f0b-4b0e-8d43-1b1f6b6f0a11';

    private const string RELEASE = '9d1c4b7a-3e2f-4a6b-8c5d-0e1f2a3b4c5d';

    #[Test]
    #[DataProvider('agreements')]
    public function two_names_agree_by_their_shared_words(string $left, string $right, float $minimum, float $maximum): void
    {
        $agreement = $this->candidates(new ScriptedReleaseGroupGateway)->agreement($left, $right);

        $this->assertGreaterThanOrEqual($minimum, $agreement);
        $this->assertLessThanOrEqual($maximum, $agreement);
    }

    /** @return iterable<string, array{string, string, float, float}> */
    public static function agreements(): iterable
    {
        yield 'a leading article' => ['The Bee Gees', 'Bee Gees', 1.0, 1.0];
        yield 'punctuation and dashes' => ['Stars- The Best of 1992-2002', 'Stars: The Best of 1992–2002', 1.0, 1.0];
        yield 'apostrophes' => ["Don't Click Play", 'Don’t Click Play', 1.0, 1.0];
        yield 'an ampersand' => ['Snakes & Arrows', 'Snakes and Arrows', 1.0, 1.0];
        yield 'a name made of articles' => ['The The', 'The The', 1.0, 1.0];
        yield 'a sequel' => ['Greatest Hits', 'Greatest Hits II', 0.0, 0.8499];
        yield 'one word of seven apart' => ['a b c d e f g', 'a b c d e f', 0.85, 0.9999];
        yield 'one word of five apart' => ['a b c d e', 'a b c d', 0.8, 0.8];
        yield 'a remix collection' => ['Love Minus 80 (The Remixes)(Electronic)', 'Love Minus 80', 0.0, 0.8499];
    }

    #[Test]
    public function a_single_passing_group_is_a_unique_match(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Candy Dulfer|Crazy' => [$this->group(self::GROUP, 'Crazy', 'Candy Dulfer', 'Album', [], '2011-09-30')],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence('Candy Dulfer - Crazy (2011)(flac)'));

        $this->assertNotNull($match);
        $this->assertSame(self::GROUP, $match->releaseGroupId);
        $this->assertSame('unique', $match->rule);
        $this->assertSame(['Candy Dulfer', 'Crazy', 2011], [$match->artist, $match->title, $match->year]);
        $this->assertSame(['cache:Candy Dulfer|Crazy'], $match->responseCacheKeys);
    }

    #[Test]
    #[DataProvider('splitArchiveNames')]
    public function a_name_matches_its_album_with_or_without_a_split_7z_volume(string $name): void
    {
        $album = $this->group(self::GROUP, 'A Man From the Future', 'Pet Shop Boys', 'Album', [], '2026-10-09');
        $gateway = new ScriptedReleaseGroupGateway([
            'Pet Shop Boys|A man from the future   [FLAC].7z.001' => [$album],
            'Pet Shop Boys|A man from the future.7z.001' => [$album],
            'Pet Shop Boys|A man from the future' => [$album],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence($name));

        $this->assertNotNull($match);
        $this->assertSame(self::GROUP, $match->releaseGroupId);
    }

    /** @return iterable<string, array{string}> */
    public static function splitArchiveNames(): iterable
    {
        yield 'a split 7z volume' => ['Pet Shop Boys - A man from the future (2026) [FLAC].7z.001'];
        yield 'the name without the volume suffix' => ['Pet Shop Boys - A man from the future (2026) [FLAC]'];
    }

    #[Test]
    public function the_year_in_the_name_decides_between_passing_groups(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Toto|Toto' => [
                $this->group(self::GROUP, 'Toto', 'Toto', 'Album', [], '1978-10-10'),
                $this->group(self::OTHER_GROUP, 'Toto', 'Toto', 'Album', ['Live'], '1996'),
            ],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence('Toto-Toto-LP-24BIT-FLAC-1978-REETKEVER'));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'year'], [$match->releaseGroupId, $match->rule]);
    }

    #[Test]
    public function the_one_plain_album_decides_between_groups_of_the_same_year(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Rammstein|Rosenrot' => [
                $this->group(self::OTHER_GROUP, 'Rosenrot', 'Rammstein', 'Single', [], '2005-12-09'),
                $this->group(self::GROUP, 'Rosenrot', 'Rammstein', 'Album', [], '2005-10-14'),
            ],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence('Rammstein-Rosenrot-2LP-24BIT-FLAC-2005-REETKEVER'));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'album_type'], [$match->releaseGroupId, $match->rule]);
    }

    #[Test]
    public function two_plain_albums_of_one_name_identify_nothing(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Phenomena|Phenomena' => [
                $this->group(self::GROUP, 'Phenomena', 'Phenomena', 'Album', [], '1985'),
                $this->group(self::OTHER_GROUP, 'Phenomena', 'Phenomena', 'Album', [], '1997'),
            ],
        ]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence('Phenomena - Phenomena [FLAC]')));
    }

    #[Test]
    public function a_reading_whose_artist_disagrees_does_not_pass_and_the_next_reading_is_searched(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'WhiteFang|Bon Jovi' => [$this->group(self::OTHER_GROUP, 'Bon Jovi', 'Bon Jovi', 'Album', [], '1984-01-21')],
            'Bon Jovi|This Left Feels Right' => [$this->group(self::GROUP, 'This Left Feels Right', 'Bon Jovi', 'Album', [], '2003-11-04')],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence('WhiteFang - Bon Jovi - 2003 - This Left Feels Right (FLAC)'));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'unique', 'Bon Jovi'], [$match->releaseGroupId, $match->rule, $match->artist]);
        $this->assertSame(
            ['WhiteFang|Bon Jovi - 2003 - This Left Feels Right', 'WhiteFang|Bon Jovi', 'Bon Jovi|This Left Feels Right'],
            $gateway->searches,
        );
        $this->assertSame(['cache:Bon Jovi|This Left Feels Right'], $match->responseCacheKeys);
    }

    #[Test]
    public function an_ambiguous_reading_ends_the_match_before_a_later_reading_is_searched(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Taylor Swift|1989' => [
                $this->group(self::GROUP, '1989', 'Taylor Swift', 'Album', [], '2014-10-27'),
                $this->group(self::OTHER_GROUP, '1989', 'Taylor Swift', 'Album', [], '2014-10-27'),
            ],
            'Taylor Swift|2LP' => [$this->group(self::THIRD_GROUP, '2LP', 'Taylor Swift', 'Album', [], '1989')],
        ]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence('Taylor_Swift-1989-2LP-24BIT-FLAC-2014-REETKEVER')));
        $this->assertSame(['Taylor Swift|1989'], $gateway->searches);
    }

    #[Test]
    public function every_title_variant_of_a_reading_is_searched_and_each_group_counts_once(): void
    {
        $doolittle = $this->group(self::GROUP, 'Doolittle', 'Pixies', 'Album', [], '1989-04-17');
        $gateway = new ScriptedReleaseGroupGateway([
            'Pixies|Doolittle [GAD 905 CD]' => [$doolittle],
            'Pixies|Doolittle' => [$doolittle],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence('Pixies-1989-Doolittle [GAD 905 CD]'));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'unique', 'Doolittle [GAD 905 CD]'], [$match->releaseGroupId, $match->rule, $match->title]);
        $this->assertSame(['Pixies|Doolittle [GAD 905 CD]', 'Pixies|Doolittle'], $gateway->searches);
        $this->assertSame(['cache:Pixies|Doolittle [GAD 905 CD]', 'cache:Pixies|Doolittle'], $match->responseCacheKeys);
    }

    #[Test]
    public function a_search_musicbrainz_answered_only_in_part_identifies_nothing(): void
    {
        // One passing group among those returned; the groups held back could hold another.
        $returned = [$this->group(self::GROUP, 'Crazy', 'Candy Dulfer', 'Album', [], '2011-09-30')];
        $gateway = new ScriptedReleaseGroupGateway(['Candy Dulfer|Crazy' => $returned], ['Candy Dulfer|Crazy' => 101]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence('Candy Dulfer - Crazy (2011)(flac)')));
        $this->assertSame([100], $gateway->limits, 'the most MusicBrainz returns for one search is asked for');
    }

    #[Test]
    public function a_partly_answered_search_without_a_passing_group_ends_the_match_before_a_later_reading(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'WhiteFang|Bon Jovi - 2003 - This Left Feels Right' => [$this->group(self::OTHER_GROUP, 'Unrelated', 'Someone Else', 'Album', [], '1990')],
            'Bon Jovi|This Left Feels Right' => [$this->group(self::GROUP, 'This Left Feels Right', 'Bon Jovi', 'Album', [], '2003-11-04')],
        ], ['WhiteFang|Bon Jovi - 2003 - This Left Feels Right' => 140]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence('WhiteFang - Bon Jovi - 2003 - This Left Feels Right (FLAC)')));
        $this->assertSame(['WhiteFang|Bon Jovi - 2003 - This Left Feels Right'], $gateway->searches);
    }

    /**
     * @param  list<string>  $secondaryTypes
     */
    #[Test]
    #[DataProvider('workQualifiers')]
    public function a_group_must_answer_every_different_work_word_in_the_name(
        string $name,
        string $search,
        string $groupTitle,
        string $primaryType,
        array $secondaryTypes,
        bool $matches,
    ): void {
        $gateway = new ScriptedReleaseGroupGateway([
            $search => [$this->group(self::GROUP, $groupTitle, 'Example Artist', $primaryType, $secondaryTypes, '2001-05-01')],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence($name));

        $this->assertSame($matches ? self::GROUP : null, $match?->releaseGroupId);
    }

    /** @return iterable<string, array{string, string, string, string, list<string>, bool}> */
    public static function workQualifiers(): iterable
    {
        $live = 'Example Artist - One Two Three Four Five Six (Live)';
        $liveSearch = 'Example Artist|One Two Three Four Five Six (Live)';
        yield 'a live name and the studio album' => [$live, $liveSearch, 'One Two Three Four Five Six', 'Album', [], false];
        yield 'a live name and a group typed live' => [$live, $liveSearch, 'One Two Three Four Five Six', 'Album', ['Live'], true];
        yield 'a live name and a group titled live' => [$live, $liveSearch, 'One Two Three Four Five Six: Live', 'Album', [], true];
        yield 'a remixes name and a group typed remix' => [
            'Example Artist - One Two Three Four Five Six (Remixes)', 'Example Artist|One Two Three Four Five Six (Remixes)',
            'One Two Three Four Five Six', 'Album', ['Remix'], true,
        ];
        yield 'a remixes name and a compilation' => [
            'Example Artist - One Two Three Four Five Six (Remixes)', 'Example Artist|One Two Three Four Five Six (Remixes)',
            'One Two Three Four Five Six', 'Album', ['Compilation'], false,
        ];
        yield 'an EP name and an EP' => [
            'Example Artist - One Two Three Four Five Six EP', 'Example Artist|One Two Three Four Five Six EP',
            'One Two Three Four Five Six', 'EP', [], true,
        ];
        yield 'an EP name and an album' => [
            'Example Artist - One Two Three Four Five Six EP', 'Example Artist|One Two Three Four Five Six EP',
            'One Two Three Four Five Six', 'Album', [], false,
        ];
        yield 'a demos name and a group typed demo' => [
            'Example Artist - One Two Three Four Five Six Seven Demos', 'Example Artist|One Two Three Four Five Six Seven Demos',
            'One Two Three Four Five Six Seven', 'Album', ['Demo'], true,
        ];
        yield 'a mix name and a DJ mix' => [
            'Example Artist - One Two Three Four Five Six (DJ Mix)', 'Example Artist|One Two Three Four Five Six (DJ Mix)',
            'One Two Three Four Five Six DJ', 'Album', ['DJ-mix'], true,
        ];
        yield 'a mixes name and a mixtape' => [
            'Example Artist - One Two Three Four Five Six Mixes', 'Example Artist|One Two Three Four Five Six Mixes',
            'One Two Three Four Five Six', 'Album', ['Mixtape/Street'], true,
        ];
        yield 'a single name and a single' => [
            'Example Artist - One Two Three Four Five Six (Single)', 'Example Artist|One Two Three Four Five Six (Single)',
            'One Two Three Four Five Six', 'Single', [], true,
        ];
        yield 'a single name and an album' => [
            'Example Artist - One Two Three Four Five Six (Single)', 'Example Artist|One Two Three Four Five Six (Single)',
            'One Two Three Four Five Six', 'Album', [], false,
        ];
        yield 'a word with no type, answered only by the title' => [
            'Example Artist - One Two Three Four Five Six (Acoustic)', 'Example Artist|One Two Three Four Five Six (Acoustic)',
            'One Two Three Four Five Six', 'Album', ['Live'], false,
        ];
        yield 'a title that is such a word' => ['Example Artist - Live', 'Example Artist|Live', 'Live', 'Album', [], true];
    }

    #[Test]
    public function no_name_and_a_name_that_does_not_parse_search_nothing(): void
    {
        $gateway = new ScriptedReleaseGroupGateway;

        $this->assertNull($this->candidates($gateway)->match($this->evidence(null)));
        $this->assertNull($this->candidates($gateway)->match($this->evidence('6nrUSrcwHgGoYcrXIyeKP')));
        $this->assertSame([], $gateway->searches);
    }

    /**
     * Issue #1276: the album tags are one more reading, after the name's.
     *
     * @param  list<string>  $searches
     */
    #[Test]
    #[DataProvider('namesTheTagsFollow')]
    public function the_album_tags_identify_the_group_when_the_name_has_no_reading(?string $name, array $searches): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Down|Volume V' => [$this->group(self::GROUP, 'Volume V', 'Down', 'Album', [], '2026-11-14')],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence($name, 'Down', 'Volume V', 2026));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'unique', 'tags'], [$match->releaseGroupId, $match->rule, $match->source]);
        $this->assertSame(['Down', 'Volume V', 2026], [$match->artist, $match->title, $match->year]);
        $this->assertSame(['cache:Down|Volume V'], $match->responseCacheKeys);
        $this->assertSame($searches, $gateway->searches);
    }

    /** @return iterable<string, array{string|null, list<string>}> */
    public static function namesTheTagsFollow(): iterable
    {
        yield 'no release name' => [null, ['Down|Volume V']];
        yield 'a release name with no reading' => ['Volume V', ['Down|Volume V']];
    }

    #[Test]
    public function a_name_reading_that_identifies_one_group_decides_before_the_album_tags(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Candy Dulfer|Crazy' => [$this->group(self::GROUP, 'Crazy', 'Candy Dulfer', 'Album', [], '2011-09-30')],
            'Down|Volume V' => [$this->group(self::OTHER_GROUP, 'Volume V', 'Down', 'Album', [], '2026-11-14')],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence('Candy Dulfer - Crazy (2011)(flac)', 'Down', 'Volume V', 2026));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'name'], [$match->releaseGroupId, $match->source]);
        $this->assertSame(['Candy Dulfer|Crazy'], $gateway->searches);
    }

    #[Test]
    public function the_album_tags_are_searched_after_a_name_reading_without_a_passing_group(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Candy Dulfer|Crazy' => [$this->group(self::OTHER_GROUP, 'Unrelated', 'Someone Else', 'Album', [], '1990')],
            'Down|Volume V' => [$this->group(self::GROUP, 'Volume V', 'Down', 'Album', [], '2026-11-14')],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence('Candy Dulfer - Crazy (2011)(flac)', 'Down', 'Volume V', 2026));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'tags'], [$match->releaseGroupId, $match->source]);
        $this->assertSame(['Candy Dulfer|Crazy', 'Down|Volume V'], $gateway->searches);
    }

    #[Test]
    public function an_ambiguous_name_reading_ends_the_match_before_the_album_tags(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Phenomena|Phenomena' => [
                $this->group(self::GROUP, 'Phenomena', 'Phenomena', 'Album', [], '1985'),
                $this->group(self::OTHER_GROUP, 'Phenomena', 'Phenomena', 'Album', [], '1997'),
            ],
            'Down|Volume V' => [$this->group(self::THIRD_GROUP, 'Volume V', 'Down', 'Album', [], '2026-11-14')],
        ]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence('Phenomena - Phenomena [FLAC]', 'Down', 'Volume V', 2026)));
        $this->assertSame(['Phenomena|Phenomena'], $gateway->searches);
    }

    #[Test]
    public function a_name_search_answered_only_in_part_ends_the_match_before_the_album_tags(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Candy Dulfer|Crazy' => [$this->group(self::OTHER_GROUP, 'Unrelated', 'Someone Else', 'Album', [], '1990')],
            'Down|Volume V' => [$this->group(self::GROUP, 'Volume V', 'Down', 'Album', [], '2026-11-14')],
        ], ['Candy Dulfer|Crazy' => 140]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence('Candy Dulfer - Crazy (2011)(flac)', 'Down', 'Volume V', 2026)));
        $this->assertSame(['Candy Dulfer|Crazy'], $gateway->searches);
    }

    #[Test]
    public function an_album_tagged_without_its_artist_or_title_searches_nothing(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Down|Volume V' => [$this->group(self::GROUP, 'Volume V', 'Down', 'Album', [], '2026-11-14')],
        ]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence(null, null, 'Volume V', 2026)));
        $this->assertNull($this->candidates($gateway)->match($this->evidence(null, 'Down', null, 2026)));
        $this->assertSame([], $gateway->searches);
    }

    #[Test]
    public function a_various_artists_name_has_no_album_tags_reading_unless_the_tags_are_various_artists_too(): void
    {
        $name = '000-va-italy_top_100_single_charts_15.11.2026-audial_int.nfo';
        $gateway = new ScriptedReleaseGroupGateway([
            'Keshi|Requiem' => [$this->group(self::GROUP, 'Requiem', 'Keshi', 'Album', [], '2024-09-13')],
        ]);

        $this->assertNull($this->candidates($gateway)->match($this->evidence($name, 'Keshi', 'Requiem', 2024)));
        $this->assertSame(['000|va'], $gateway->searches);

        $gateway = new ScriptedReleaseGroupGateway;
        $this->candidates($gateway)->match($this->evidence($name, 'VA', 'Italy Top 100', 2026));
        $this->assertSame(['000|va', 'Various Artists|Italy Top 100'], $gateway->searches);
    }

    #[Test]
    public function the_tagged_year_decides_between_passing_groups(): void
    {
        $gateway = new ScriptedReleaseGroupGateway([
            'Toto|Toto' => [
                $this->group(self::OTHER_GROUP, 'Toto', 'Toto', 'Album', ['Live'], '1996'),
                $this->group(self::GROUP, 'Toto', 'Toto', 'Album', [], '1978-10-10'),
            ],
        ]);

        $match = $this->candidates($gateway)->match($this->evidence(null, 'Toto', 'Toto', 1978));

        $this->assertNotNull($match);
        $this->assertSame([self::GROUP, 'year', 'tags'], [$match->releaseGroupId, $match->rule, $match->source]);
    }

    #[Test]
    public function the_matched_group_is_appended_hydrated_when_the_pool_has_no_candidate_for_it(): void
    {
        $gateway = new ScriptedReleaseGroupGateway;
        $existing = new CandidateHypothesis(new CandidateIdentity(releaseId: self::RELEASE, releaseGroupId: self::OTHER_GROUP), CandidateMetadata::empty(), []);

        $pool = $this->candidates($gateway)->supplement($this->evidence('Candy Dulfer - Crazy (2011)(flac)'), new CandidatePool([$existing]), $this->nameMatch());

        $this->assertCount(2, $pool->candidates);
        $this->assertSame($existing, $pool->candidates[0]);
        $added = $pool->candidates[1];
        $this->assertSame('release-group:'.self::GROUP, $added->identity->key());
        $this->assertSame([self::GROUP], array_column($added->metadata->releaseGroups, 'releaseGroupId'));
        $this->assertSame([self::GROUP], array_map(static fn (CandidateIdentifiers $identifiers): ?string => $identifiers->releaseGroupId, $gateway->hydrated));
        $this->assertCount(1, $added->signals);
        $this->assertNameSignal($added->signals[0]);
    }

    #[Test]
    public function the_matched_groups_existing_candidate_gains_the_signal_without_a_hydration(): void
    {
        $gateway = new ScriptedReleaseGroupGateway;
        $fileSignal = new CandidateSignal(CandidateSignalKind::ReleaseSearch, 'crazy', 'evidence:7:album', exact: false);
        $other = new CandidateHypothesis(new CandidateIdentity(releaseGroupId: self::OTHER_GROUP), CandidateMetadata::empty(), []);
        $existing = new CandidateHypothesis(new CandidateIdentity(releaseGroupId: self::GROUP), CandidateMetadata::empty(), [$fileSignal]);

        $pool = $this->candidates($gateway)->supplement($this->evidence('Candy Dulfer - Crazy (2011)(flac)'), new CandidatePool([$other, $existing]), $this->nameMatch());

        $this->assertCount(2, $pool->candidates);
        $this->assertSame($other, $pool->candidates[0]);
        $this->assertSame('release-group:'.self::GROUP, $pool->candidates[1]->identity->key());
        $this->assertSame($fileSignal, $pool->candidates[1]->signals[0]);
        $this->assertCount(2, $pool->candidates[1]->signals);
        $this->assertNameSignal($pool->candidates[1]->signals[1]);
        $this->assertSame([], $gateway->hydrated);
    }

    #[Test]
    public function a_match_through_the_album_tags_adds_an_album_tags_signal_of_the_tags_family(): void
    {
        $gateway = new ScriptedReleaseGroupGateway;
        $evidence = $this->evidence(null, 'Down', 'Volume V', 2026);
        $match = new ReleaseNameAlbumMatch(self::GROUP, 'Down', 'Volume V', 2026, 'unique', ['cache:Down|Volume V'], 'tags');

        $pool = $this->candidates($gateway)->supplement($evidence, new CandidatePool([]), $match);

        $this->assertCount(1, $pool->candidates);
        $this->assertCount(1, $pool->candidates[0]->signals);
        $signal = $pool->candidates[0]->signals[0];
        $this->assertSame(CandidateSignalKind::AlbumTags, $signal->kind);
        $this->assertSame('Down - Volume V', $signal->value);
        $this->assertSame('album-tags:7', $evidence->albumProvenanceFamily());
        $this->assertSame($evidence->albumProvenanceFamily(), $signal->provenanceFamily);
        $this->assertFalse($signal->exact);
        $this->assertSame('release-group:'.self::GROUP, $signal->identity->key());
        $this->assertSame(['cache:Down|Volume V'], $signal->responseCacheKeys);
    }

    private function assertNameSignal(CandidateSignal $signal): void
    {
        $this->assertSame(CandidateSignalKind::ReleaseName, $signal->kind);
        $this->assertSame('Candy Dulfer - Crazy', $signal->value);
        $this->assertSame('evidence:7:release-name', $signal->provenanceFamily);
        $this->assertFalse($signal->exact);
        $this->assertSame('release-group:'.self::GROUP, $signal->identity->key());
        $this->assertNull($signal->identity->recordingId);
        $this->assertSame(['cache:Candy Dulfer|Crazy'], $signal->responseCacheKeys);
    }

    private function nameMatch(): ReleaseNameAlbumMatch
    {
        return new ReleaseNameAlbumMatch(self::GROUP, 'Candy Dulfer', 'Crazy', 2011, 'unique', ['cache:Candy Dulfer|Crazy']);
    }

    private function candidates(MusicBrainzGateway $gateway): ReleaseNameAlbumCandidates
    {
        return new ReleaseNameAlbumCandidates($gateway, new ReleaseNameAlbumParser);
    }

    private function evidence(?string $releaseName, ?string $albumArtist = null, ?string $albumTitle = null, ?int $releaseYear = null): AudioEvidenceSet
    {
        return new AudioEvidenceSet(
            evidenceId: 7,
            evidenceHash: str_repeat('e', 64),
            releaseTitle: $releaseName,
            albumTitle: $albumTitle,
            albumArtist: $albumArtist,
            releaseYear: $releaseYear,
            trackEvidence: [],
            albumProvenanceFamily: $albumTitle === null ? null : 'album-tags:7',
        );
    }

    /**
     * @param  list<string>  $secondaryTypes
     * @return MusicReleaseGroup
     */
    private function group(string $id, string $title, string $artist, string $primaryType, array $secondaryTypes, string $firstReleaseDate): array
    {
        return [
            'releaseGroupId' => $id,
            'title' => $title,
            'artistCredit' => $artist,
            'primaryType' => $primaryType,
            'secondaryTypes' => $secondaryTypes,
            'firstReleaseDate' => $firstReleaseDate,
            'artists' => [['artistId' => 'b2d122f9-eadb-4930-a196-8f221eeb0c66', 'name' => $artist, 'aliases' => []]],
        ];
    }
}

/**
 * Answers release-group searches from a script keyed "artist|title" and records what was asked.
 *
 * @phpstan-import-type MusicReleaseGroup from CandidateMetadata
 */
final class ScriptedReleaseGroupGateway implements MusicBrainzGateway
{
    /** @var list<string> every search made, as "artist|title" */
    public array $searches = [];

    /** @var list<int|null> the limit each search asked for */
    public array $limits = [];

    /** @var list<CandidateIdentifiers> */
    public array $hydrated = [];

    /**
     * @param  array<string, list<MusicReleaseGroup>>  $answers
     * @param  array<string, int>  $totals  how many groups MusicBrainz holds for a search, when more than it returns
     */
    public function __construct(private readonly array $answers = [], private readonly array $totals = []) {}

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        return RecordingCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        return ReleaseCandidates::empty();
    }

    public function releaseGroupCandidatesFor(ReleaseGroupQuery $query): ReleaseGroupCandidates
    {
        $key = $query->artist.'|'.$query->title;
        $this->searches[] = $key;
        $this->limits[] = $query->limit;
        $releaseGroups = $this->answers[$key] ?? [];

        return new ReleaseGroupCandidates($releaseGroups, $this->totals[$key] ?? count($releaseGroups), ['cache:'.$key]);
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        $this->hydrated[] = $identifiers;

        return new CandidateMetadata([], [], [[
            'releaseGroupId' => (string) $identifiers->releaseGroupId,
            'title' => 'Crazy',
            'artistCredit' => 'Candy Dulfer',
            'primaryType' => 'Album',
            'secondaryTypes' => [],
            'firstReleaseDate' => '2011-09-30',
        ]]);
    }

    public function releaseGroup(string $releaseGroupId): ?array
    {
        return null;
    }
}
