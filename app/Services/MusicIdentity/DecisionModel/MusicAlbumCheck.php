<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DecisionModel;

use App\Models\DecisionModelCriteria;
use App\Models\ReleaseMusicIdentification;
use App\Models\Settings;
use App\Services\DecisionModel\DecisionLog;
use App\Services\DecisionModel\DecisionMode;
use App\Services\DecisionModel\DecisionModelClient;
use App\Services\DecisionModel\DecisionRunner;
use App\Services\MusicIdentity\CurrentMusicIdentityReader;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Enums\MusicRenameOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The `mus` pass's shadow check of the music matcher's album choices (issue
 * #1140): every current completed decision with a titled stored candidate is
 * put to the decision model once per criteria version, newest first, at most
 * BATCH per pass, and the answer is written to the decision log. Nothing else
 * changes: no album link, no name, no category, no cover.
 *
 * A decision whose answer the service rejected outright (a malformed answer,
 * a 4xx other than a refused key) is logged with that reason and not asked
 * again, so it cannot block the newest-first queue. When the service is down,
 * busy or refusing the key, nothing is logged and the pass ends; the next pass
 * retries the same decision.
 *
 * Not final so a test can stand in for it; the music identity worker holds one.
 */
class MusicAlbumCheck
{
    public const string SETTING = 'decision_model_music_album_mode';

    /** What `decision_model_decisions.subject_type` holds for these rows. */
    public const string SUBJECT_TYPE = 'release_music_identification';

    /** Decisions logged per pass; about 10 new matcher decisions a day carry candidates. */
    public const int BATCH = 50;

    private const int CHUNK = 100;

    public function __construct(
        private readonly DecisionModelClient $client,
        private readonly DecisionRunner $runner,
        private readonly DecisionLog $log,
        private readonly MusicAlbumCheckUseCase $useCase,
        private readonly CurrentMusicIdentityReader $identities,
    ) {}

    /**
     * @return int the decisions logged
     */
    public function catchUp(): int
    {
        if (DecisionMode::fromSetting(Settings::settingValue(self::SETTING)) !== DecisionMode::Shadow || ! $this->client->isUsable()) {
            return 0;
        }

        // Not created here: a missing row means nothing has been logged under this version yet.
        $criteriaId = DecisionModelCriteria::query()
            ->where('use_case', MusicAlbumCheckUseCase::NAME)
            ->where('version', $this->useCase->criteriaVersion())
            ->value('id');
        $criteriaId = $criteriaId === null ? null : (int) $criteriaId;

        $logged = 0;
        $before = null;

        do {
            $rows = $this->unchecked($criteriaId, $before, self::CHUNK);
            if ($rows->isEmpty()) {
                break;
            }
            $before = (int) $rows->last()->id;

            foreach ($this->currentOf($rows) as $identification) {
                $subject = $this->subjectOf($identification);
                if ($subject === null) {
                    continue;
                }

                $run = $this->runner->run(MusicAlbumCheckUseCase::NAME, $subject);
                if ($run->failed() && self::serviceUnavailable((string) $run->failureReason)) {
                    Log::warning('music_identity.album_check_unavailable', [
                        'identification_id' => $identification->id,
                        'reason' => $run->failureReason,
                    ]);

                    return $logged;
                }

                $this->log->record($run, self::SUBJECT_TYPE, (int) $identification->id, $subject->pipelineAnswer($this->matcherRenamed($identification)), DecisionMode::Shadow, false);
                if (++$logged >= self::BATCH) {
                    return $logged;
                }
            }
        } while ($rows->count() === self::CHUNK);

        return $logged;
    }

    /**
     * Completed decisions with a titled stored candidate, not yet logged under
     * the current criteria, newest first, below the id $before.
     *
     * @return Collection<int, \stdClass>
     */
    private function unchecked(?int $criteriaId, ?int $before, int $chunk): Collection
    {
        return DB::table('release_music_identifications as i')
            ->whereIn('i.state', self::checkedStates())
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')->from('release_music_candidate_attempts as c')
                    ->whereColumn('c.release_music_identification_id', 'i.id')
                    ->whereNotNull('c.display_snapshot->title')->where('c.display_snapshot->title', '!=', '')
                    ->whereNotNull('c.display_snapshot->artistCredit')->where('c.display_snapshot->artistCredit', '!=', '');
            })
            ->when($criteriaId !== null, static fn ($query) => $query->whereNotExists(static function ($logged) use ($criteriaId): void {
                $logged->selectRaw('1')->from('decision_model_decisions as d')
                    ->where('d.subject_type', self::SUBJECT_TYPE)
                    ->whereColumn('d.subject_id', 'i.id')
                    ->where('d.decision_model_criteria_id', $criteriaId);
            }))
            ->when($before !== null, static fn ($query) => $query->where('i.id', '<', $before))
            ->orderByDesc('i.id')
            ->limit($chunk)
            ->get(['i.id', 'i.releases_id']);
    }

    /**
     * The chunk's rows that are their release's current decision, with their
     * stored candidates, newest first. An older or withdrawn decision is
     * skipped; the current one is a row of its own when it is unchecked.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return Collection<int, ReleaseMusicIdentification>
     */
    private function currentOf(Collection $rows): Collection
    {
        $current = $this->identities->forReleases($rows->pluck('releases_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all());
        $ids = [];
        foreach ($rows as $row) {
            $identity = $current[(int) $row->releases_id] ?? null;
            if ($identity !== null && $identity->identificationId === (int) $row->id) {
                $ids[] = (int) $row->id;
            }
        }
        if ($ids === []) {
            return new Collection;
        }

        return ReleaseMusicIdentification::query()->with('candidateAttempts')->whereIn('id', $ids)->orderByDesc('id')->get()->toBase();
    }

    /**
     * The subject of one decision, or null when none of its candidates can be labelled.
     */
    private function subjectOf(ReleaseMusicIdentification $identification): ?MusicAlbumCheckSubject
    {
        $releaseName = (string) DB::table('releases')->where('id', $identification->releases_id)->value('searchname');
        $fileNames = DB::table('release_files')
            ->where('releases_id', $identification->releases_id)
            ->orderBy('name')
            ->limit(MusicAlbumCheckSubject::FILE_LIMIT)
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        $subject = MusicAlbumCheckSubject::build($identification, $releaseName, $fileNames, $identification->candidateAttempts);

        return $subject->candidates === [] ? null : $subject;
    }

    /**
     * Whether the release's search name was written from this decision's own
     * answer: an applied, unreverted canonical rename.
     */
    private function matcherRenamed(ReleaseMusicIdentification $identification): bool
    {
        return DB::table('release_music_renames')
            ->where('release_music_identification_id', $identification->id)
            ->where('outcome', MusicRenameOutcome::Applied->value)
            ->whereNull('reverted_at')
            ->exists();
    }

    /**
     * The failure reasons that say the service is down, busy or refusing the
     * key rather than rejecting this decision: nothing is logged and the pass
     * ends, so the next pass retries the same decision.
     */
    private static function serviceUnavailable(string $reason): bool
    {
        return in_array($reason, ['disabled', 'circuit_open', 'connection_failed', 'http_401', 'http_403', 'http_429'], true)
            || preg_match('/^http_5\d\d$/', $reason) === 1;
    }

    /**
     * The completed states the check asks about: the two accepted album states,
     * where the matcher's answer is known, and the three where it gave none.
     * An accepted recording was not part of the spike.
     *
     * @return list<string>
     */
    private static function checkedStates(): array
    {
        return [
            IdentificationStatus::AcceptedReleaseGroup->value,
            IdentificationStatus::AcceptedEdition->value,
            IdentificationStatus::NeedsReview->value,
            IdentificationStatus::Unresolved->value,
            IdentificationStatus::Conflicted->value,
        ];
    }
}
