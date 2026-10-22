<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Models\ReleaseMusicCandidateAttempt;
use App\Models\ReleaseMusicIdentification;
use App\Services\DecisionModel\DecisionAnswer;
use App\Services\DecisionModel\DecisionModelClient;
use App\Services\DecisionModel\DecisionResponse;
use App\Services\DecisionModel\DecisionRunner;
use App\Services\DecisionModel\DecisionUseCaseRegistry;
use App\Services\DecisionModel\Exceptions\MalformedDecisionAnswer;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheckSubject;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheckUseCase;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumVerdict;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * What the music album check sends for a matcher decision and what it makes
 * of the model's answer (issue #1140), against the criteria file in the
 * repository. Nothing here touches the database.
 */
final class MusicAlbumCheckUseCaseTest extends TestCase
{
    private const string ENDPOINT = 'https://decision.example.test/v1/systemone';

    private const string CRITERIA_VERSION = 'v1 2026-10-23: choose among the matcher\'s stored candidates from release name and up to 10 file names, with a none option';

    private const string GROUP_A = '11111111-1111-4111-8111-111111111111';

    private const string GROUP_B = '22222222-2222-4222-8222-222222222222';

    private const string GROUP_C = '33333333-3333-4333-8333-333333333333';

    private const string GROUP_D = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'decision-model.enabled' => true,
            'decision-model.base_url' => 'https://decision.example.test',
            'decision-model.api_key' => 'invented-token',
            'decision-model.retry_attempts' => 1,
        ]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_it_reports_the_criteria_file_version(): void
    {
        $useCase = new MusicAlbumCheckUseCase;

        $this->assertSame('music_album_check', $useCase->name());
        $this->assertSame(self::CRITERIA_VERSION, $useCase->criteriaVersion());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function brokenCriteriaFiles(): array
    {
        return [
            'a missing file' => [null],
            'invalid JSON' => ['{"version": "v1", "instructions": '],
            'an empty version' => ['{"version": "", "instructions": "Which album is this release?"}'],
            'missing instructions' => ['{"version": "v1 invented"}'],
        ];
    }

    #[DataProvider('brokenCriteriaFiles')]
    public function test_a_broken_criteria_file_is_refused(?string $contents): void
    {
        $directory = $this->makeTempDirectory('music-album-criteria');
        if ($contents !== null) {
            file_put_contents($directory.'/'.MusicAlbumCheckUseCase::NAME.'.json', $contents);
        }
        config(['decision-model.criteria_path' => $directory]);

        $this->expectException(RuntimeException::class);
        (new MusicAlbumCheckUseCase)->criteriaVersion();
    }

    public function test_the_request_carries_the_name_the_cut_file_names_and_the_de_duplicated_candidates_plus_none(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['answers' => ['album' => $this->albumAnswer('1', 0.9)]])]);
        $subject = $this->subject(
            IdentificationStatus::Unresolved,
            candidates: [
                [self::GROUP_A, 'Lenka', 'Good Days', '2026-03-13'],
                [self::GROUP_A, 'Lenka', 'Good Days', '2027-01-01'],
                [self::GROUP_B, 'Train', 'Mad Dog in the Fog', null],
            ],
            fileNames: ['MP3\\01 - Lenka - Good Days.mp3', 'MP3/02 - Lenka - Enjoy.mp3', 'cover.jpg'],
        );

        $run = $this->runner()->run(MusicAlbumCheckUseCase::NAME, $subject);

        $this->assertNull($run->failureReason);
        $request = Http::recorded()->map(static fn (array $pair): Request => $pair[0])->sole();
        $this->assertSame(self::ENDPOINT, $request->url());
        $this->assertSame([
            'state' => [
                'release_name' => 'Lenka - Good Days (2026) FLAC',
                'files' => ['01 - Lenka - Good Days.mp3', '02 - Lenka - Enjoy.mp3', 'cover.jpg'],
            ],
            'questions' => [
                'album' => [
                    'type' => 'choice',
                    'instructions' => 'Which album is this release?',
                    'criteria' => [1 => 'Lenka - Good Days (2026)', 2 => 'Train - Mad Dog in the Fog', 'none' => null],
                ],
            ],
        ], $request->data());

        $this->assertSame([
            'state' => 'unresolved',
            'band' => 'unresolved',
            'score' => 40,
            'accepted_release_group_id' => null,
            'candidate_count' => 2,
            'untitled_candidates' => 0,
            'options' => [
                1 => ['rank' => 1, 'release_group_id' => self::GROUP_A, 'label' => 'Lenka - Good Days (2026)'],
                2 => ['rank' => 3, 'release_group_id' => self::GROUP_B, 'label' => 'Train - Mad Dog in the Fog'],
            ],
            'matcher_renamed' => false,
            'releases_id' => 10,
        ], $subject->pipelineAnswer(false));
    }

    public function test_without_stored_files_the_state_is_the_bare_release_name(): void
    {
        $subject = $this->subject(IdentificationStatus::Unresolved, candidates: [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);

        $this->assertSame('Lenka - Good Days (2026) FLAC', (new MusicAlbumCheckUseCase)->state($subject));
    }

    public function test_at_most_ten_file_names_are_sent_in_the_order_given(): void
    {
        $names = array_map(static fn (int $i): string => sprintf('Disc 1\\%02d - Track.flac', $i), range(1, 12));

        $subject = $this->subject(IdentificationStatus::Unresolved, candidates: [[self::GROUP_A, 'Lenka', 'Good Days', '2026']], fileNames: $names);

        $this->assertSame(array_map(static fn (int $i): string => sprintf('%02d - Track.flac', $i), range(1, 10)), $subject->fileNames);
        $this->assertSame(
            ['release_name' => 'Lenka - Good Days (2026) FLAC', 'files' => $subject->fileNames],
            (new MusicAlbumCheckUseCase)->state($subject),
        );
    }

    public function test_an_untitled_candidate_is_not_offered_and_is_counted(): void
    {
        $subject = $this->subject(IdentificationStatus::AcceptedReleaseGroup, candidates: [
            [self::GROUP_A, null, null, null],
            [self::GROUP_B, 'Train', '', '2026'],
            [self::GROUP_C, 'Train', 'Mad Dog in the Fog', '2026-01-01'],
        ], acceptedGroup: self::GROUP_A);

        $this->assertSame([['key' => '1', 'rank' => 3, 'release_group_id' => self::GROUP_C, 'label' => 'Train - Mad Dog in the Fog (2026)']], $subject->candidates);
        $this->assertSame(2, $subject->untitledCandidates);
        $this->assertSame(2, $subject->pipelineAnswer(false)['untitled_candidates']);
        $this->assertSame(self::GROUP_A, $subject->pipelineAnswer(false)['accepted_release_group_id']);
        $this->assertSame([1 => 'Train - Mad Dog in the Fog (2026)', 'none' => null], (new MusicAlbumCheckUseCase)->questions($subject, [])['album']->toArray()['criteria']);
    }

    public function test_candidates_without_a_release_group_are_kept_apart_by_title_and_artist(): void
    {
        $subject = $this->subject(IdentificationStatus::NeedsReview, candidates: [
            [null, 'Lenka', 'Good Days', '2026'],
            [null, 'LENKA', 'good days', '2027'],
            [null, 'Lenka', 'Enjoy', null],
        ]);

        $this->assertSame(
            [[1, null, 'Lenka - Good Days (2026)'], [3, null, 'Lenka - Enjoy']],
            array_map(static fn (array $candidate): array => [$candidate['rank'], $candidate['release_group_id'], $candidate['label']], $subject->candidates),
        );
    }

    public function test_the_second_request_is_never_asked(): void
    {
        $subject = $this->subject(IdentificationStatus::Unresolved, candidates: [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);

        $this->assertSame([], (new MusicAlbumCheckUseCase)->questions($subject, [$this->response($this->albumAnswer('1', 0.9))]));
    }

    /**
     * @return array<string, array{float, bool}>
     */
    public static function linkProbabilities(): array
    {
        return ['at the threshold' => [0.80, true], 'just below it' => [0.79, false]];
    }

    #[DataProvider('linkProbabilities')]
    public function test_a_pick_links_on_its_own_probability_not_the_confidence(float $probability, bool $links): void
    {
        $subject = $this->subject(IdentificationStatus::Unresolved, candidates: [[self::GROUP_A, 'Lenka', 'Good Days', '2026-03-13']]);

        $verdict = $this->interpret($subject, $this->albumAnswer('1', $probability, confidence: 0.95));

        $this->assertSame([
            'choice' => '1',
            'probability' => $probability,
            'picked_rank' => 1,
            'picked_release_group_id' => self::GROUP_A,
            'would_link' => $links,
            'link_threshold' => 0.8,
            'agrees_with_accepted' => null,
        ], $verdict->toArray());
    }

    public function test_none_never_links(): void
    {
        $subject = $this->subject(IdentificationStatus::AcceptedReleaseGroup, candidates: [[self::GROUP_A, 'Lenka', 'Good Days', '2026']], acceptedGroup: self::GROUP_A);

        $verdict = $this->interpret($subject, $this->albumAnswer('none', 0.99));

        $this->assertSame(['none', 0.99, null, null, false, false], [
            $verdict->choice, $verdict->probability, $verdict->pickedRank, $verdict->pickedReleaseGroupId, $verdict->wouldLink, $verdict->agreesWithAccepted,
        ]);
    }

    /**
     * @return array<string, array{string, float, ?bool}>
     */
    public static function accordance(): array
    {
        return [
            'the accepted album, confidently' => ['1', 0.9, true],
            'the accepted album, unsurely' => ['1', 0.5, false],
            'another album' => ['2', 0.9, false],
            'another release group with the same label' => ['3', 0.9, true],
            'none of them' => ['none', 0.9, false],
        ];
    }

    #[DataProvider('accordance')]
    public function test_an_accepted_album_decision_records_whether_a_link_would_agree(string $choice, float $probability, ?bool $agrees): void
    {
        $subject = $this->subject(IdentificationStatus::AcceptedEdition, candidates: [
            [self::GROUP_A, 'Lenka', 'Good Days', '2026-03-13'],
            [self::GROUP_B, 'Train', 'Mad Dog in the Fog', '2026'],
            [self::GROUP_C, 'Lenka', 'Good Days', '2026-11-01'],
        ], acceptedGroup: self::GROUP_A);

        $this->assertSame($agrees, $this->interpret($subject, $this->albumAnswer($choice, $probability))->agreesWithAccepted);
    }

    public function test_agreement_is_null_without_an_accepted_album_or_when_its_row_was_untitled(): void
    {
        $unresolved = $this->subject(IdentificationStatus::Conflicted, candidates: [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $this->assertNull($this->interpret($unresolved, $this->albumAnswer('1', 0.9))->agreesWithAccepted);

        $untitledAccepted = $this->subject(IdentificationStatus::AcceptedReleaseGroup, candidates: [
            [self::GROUP_D, null, null, null],
            [self::GROUP_A, 'Lenka', 'Good Days', '2026'],
        ], acceptedGroup: self::GROUP_D);
        $this->assertNull($this->interpret($untitledAccepted, $this->albumAnswer('1', 0.9))->agreesWithAccepted);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableAnswers(): array
    {
        return [
            'a key the criteria never offered' => [['choice' => '7', 'probabilities' => ['7' => 0.9], 'confidence' => 0.9]],
            'a failed answer' => [['error' => 'too_long', 'message' => 'The question exceeds the token cap.']],
            'an answer without a choice' => [['probabilities' => ['1' => 0.9], 'confidence' => 0.9]],
        ];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    #[DataProvider('unusableAnswers')]
    public function test_an_unusable_album_answer_is_malformed(array $answer): void
    {
        $subject = $this->subject(IdentificationStatus::Unresolved, candidates: [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);

        try {
            $this->interpret($subject, $answer);
            $this->fail('The answer must be rejected.');
        } catch (MalformedDecisionAnswer $e) {
            $this->assertSame('album_answer_unusable', $e->reason);
        }

        Http::fake([self::ENDPOINT => Http::response(['answers' => ['album' => $answer]])]);
        $run = $this->runner()->run(MusicAlbumCheckUseCase::NAME, $subject);
        $this->assertSame(['album_answer_unusable', null], [$run->failureReason, $run->verdict]);
    }

    /**
     * @param  list<array{?string, ?string, ?string, ?string}>  $candidates  release group, artist credit, title, date, in rank order
     * @param  list<string>  $fileNames
     */
    private function subject(IdentificationStatus $state, array $candidates, array $fileNames = [], ?string $acceptedGroup = null): MusicAlbumCheckSubject
    {
        $identification = new ReleaseMusicIdentification([
            'id' => 501,
            'releases_id' => 10,
            'state' => $state->value,
            'band' => $state->acceptsAlbum() ? 'strong' : 'unresolved',
            'score' => $state->acceptsAlbum() ? 96 : 40,
            'musicbrainz_release_group_id' => $acceptedGroup,
        ]);
        $attempts = [];
        foreach ($candidates as $rank => [$group, $artistCredit, $title, $date]) {
            $attempts[] = new ReleaseMusicCandidateAttempt([
                'rank' => $rank + 1,
                'musicbrainz_release_group_id' => $group,
                'display_snapshot' => ['title' => $title, 'artistCredit' => $artistCredit, 'date' => $date, 'country' => null],
            ]);
        }

        return MusicAlbumCheckSubject::build($identification, 'Lenka - Good Days (2026) FLAC', $fileNames, $attempts);
    }

    /**
     * @return array<string, mixed>
     */
    private function albumAnswer(string $choice, float $probability, ?float $confidence = null): array
    {
        return ['choice' => $choice, 'probabilities' => [$choice => $probability], 'confidence' => $confidence ?? $probability];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function response(array $answer): DecisionResponse
    {
        return new DecisionResponse(['album' => DecisionAnswer::fromArray($answer)]);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function interpret(MusicAlbumCheckSubject $subject, array $answer): MusicAlbumVerdict
    {
        return (new MusicAlbumCheckUseCase)->interpret($subject, [$this->response($answer)]);
    }

    private function runner(): DecisionRunner
    {
        return new DecisionRunner(new DecisionModelClient, new DecisionUseCaseRegistry([new MusicAlbumCheckUseCase]));
    }
}
