<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Rename;

use App\Models\Release;
use App\Models\ReleaseMusicIdentification;
use App\Models\ReleaseMusicRename;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\MusicIdentity\CurrentMusicIdentityReader;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Enums\MusicRenameDeclineReason;
use App\Services\MusicIdentity\Enums\MusicRenameOutcome;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The required canonical rename of a release whose current music identity accepts an album
 * (issue #309), and its reversal.
 *
 * The release's current decision comes from the shared rule (CurrentMusicIdentityReader). An
 * accepted album decision is renamed once, through the existing name-fixing path, when it clears
 * the rename gate: a score and runner-up margin at the resolver's calibrated album thresholds, no
 * hard contradiction, no PreDB match, and a search name that only audio tags (or no trusted
 * source) set. Every decision gets one record: applied, with the prior and written search name and
 * name source and every other field the rename changed, or declined, with the reason; a declined
 * decision is not evaluated again, while a new decision (new evidence or an algorithm version
 * bump) gets its own evaluation. When an applied decision stops being current, the release gets
 * its prior values back only while its search name and name source are still the ones the rename
 * wrote; once a human or another source has renamed it, nothing is restored.
 */
final class MusicRenameProjection
{
    /** Always recorded: a reversal restores nothing unless the release still has both. */
    private const array NAME_FIELDS = ['searchname', 'name_source'];

    /** Recorded when the rename changed them. */
    private const array CHANGEABLE_FIELDS = [
        'searchname_normalized', 'display_name', 'is_trusted_name', 'isrenamed', 'iscategorized', 'proc_pp',
        'categories_id', 'predb_id', 'videos_id', 'tv_episodes_id', 'movieinfo_id', 'imdbid', 'musicinfo_id',
        'consoleinfo_id', 'bookinfo_id', 'anidbid', 'gamesinfo_id',
    ];

    /** Derived from the search name and rewritten with it (Release::searchNameValues()). */
    private const array DERIVED_NAME_FIELDS = ['searchname_normalized', 'display_name'];

    private readonly ReleaseSearchSyncCoordinator $searchSync;

    private readonly ReleaseUpdateService $nameWrites;

    public function __construct(
        private readonly CurrentMusicIdentityReader $identities,
        private readonly AcceptedEvaluationReader $evaluations,
        private readonly CanonicalAlbumName $canonicalNames,
    ) {
        // Private by design: the projection scopes this coordinator to one release per call, so the
        // name write's search re-sync runs once, after the projection's transaction commits.
        $this->searchSync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $this->nameWrites = new ReleaseUpdateService(searchSyncCoordinator: $this->searchSync);
    }

    /**
     * Bring the release's name in line with its current decision: revert an applied rename whose
     * decision is no longer current, then rename for a current accepted album with no record yet.
     * Running it again changes nothing.
     */
    public function project(int $releaseId): void
    {
        $this->searchSync->beginReleaseScope($releaseId);
        try {
            DB::transaction(fn () => $this->projectLocked($releaseId));
        } catch (\Throwable $exception) {
            $this->searchSync->discard($releaseId);
            $this->searchSync->finishReleaseScope();

            throw $exception;
        }

        try {
            $this->searchSync->finishReleaseScope();
        } catch (\Throwable $exception) {
            Log::warning('Music rename search re-sync failed.', ['release_id' => $releaseId, 'exception' => $exception]);
        }
    }

    /**
     * Once per `mus` pass: releases whose current decision accepts an album and has no rename
     * record (releases accepted before the rename existed included), and releases with an applied
     * rename whose decision is no longer current. Both use the shared current-decision rule, so a
     * pass with nothing changed selects nothing. Returns the number of releases projected.
     */
    public function catchUp(): int
    {
        $projected = 0;
        foreach (array_unique([...$this->currentAlbumsWithoutRecord(), ...$this->renamesNoLongerCurrent()]) as $releaseId) {
            try {
                $this->project($releaseId);
                $projected++;
            } catch (\Throwable $exception) {
                Log::warning('Music rename projection failed.', ['release_id' => $releaseId, 'exception' => $exception]);
            }
        }

        return $projected;
    }

    private function projectLocked(int $releaseId): void
    {
        if (Release::query()->whereKey($releaseId)->lockForUpdate()->value('id') === null) {
            return;
        }
        $current = $this->identities->forRelease($releaseId);
        $applied = ReleaseMusicRename::query()->where('releases_id', $releaseId)
            ->where('outcome', MusicRenameOutcome::Applied->value)->lockForUpdate()->get();
        foreach ($applied as $record) {
            if ($record->release_music_identification_id !== $current?->identificationId) {
                $this->revert($record);
            }
        }

        if ($current === null || ! $current->acceptsAlbum()
            || ReleaseMusicRename::query()->where('release_music_identification_id', $current->identificationId)->exists()) {
            return;
        }

        $decision = ReleaseMusicIdentification::query()->findOrFail($current->identificationId);
        $before = $this->releaseFields($releaseId);
        $name = $this->canonicalNames->for($decision);
        $reason = $this->declineReason($decision, $before) ?? ($name === null ? MusicRenameDeclineReason::NoCanonicalName : null);
        if ($reason === null && $name !== null) {
            $this->nameWrites->renameFromMusicIdentity($releaseId, $name);
            $after = $this->releaseFields($releaseId);
            $recorded = [...self::NAME_FIELDS, ...array_keys(array_filter(
                array_intersect_key($after, array_flip(self::CHANGEABLE_FIELDS)),
                fn (mixed $value, string $field): bool => ! $this->sameValue($value, $before[$field] ?? null),
                ARRAY_FILTER_USE_BOTH,
            ))];
            if (! $this->sameValue($after['searchname'] ?? null, $before['searchname'] ?? null)
                || ! $this->sameValue($after['name_source'] ?? null, $before['name_source'] ?? null)) {
                $this->record($decision, MusicRenameOutcome::Applied, null, $this->only($before, $recorded), $this->only($after, $recorded));

                return;
            }
            $reason = MusicRenameDeclineReason::RenameRefused;
        }

        $this->record($decision, MusicRenameOutcome::Declined, $reason);
    }

    /** @param  array<string, scalar|null>  $release */
    private function declineReason(ReleaseMusicIdentification $decision, array $release): ?MusicRenameDeclineReason
    {
        $evaluation = $this->evaluations->forDecision($decision);
        if ($evaluation === null) {
            return MusicRenameDeclineReason::NoAcceptedEvaluation;
        }
        if ($evaluation->contradictions !== []) {
            return MusicRenameDeclineReason::HardContradiction;
        }
        if ($evaluation->score < (int) config('music-identity.scoring.minimum_album_score')) {
            return MusicRenameDeclineReason::ScoreBelowMinimum;
        }
        if ($evaluation->runnerUpMargin !== null && $evaluation->runnerUpMargin < (int) config('music-identity.scoring.minimum_runner_up_margin')) {
            return MusicRenameDeclineReason::RunnerUpMarginBelowMinimum;
        }
        if ((int) ($release['predb_id'] ?? 0) > 0) {
            return MusicRenameDeclineReason::PredbMatch;
        }

        return $this->nameIsReplaceable($release) ? null : MusicRenameDeclineReason::ProtectedName;
    }

    /**
     * A name set by audio tags is provisional; an untrusted name is no protection. Any other
     * trusted name protects the release, including one whose source was never recorded, and so
     * does a name an admin typed.
     *
     * @param  array<string, scalar|null>  $release
     */
    private function nameIsReplaceable(array $release): bool
    {
        $source = $release['name_source'] ?? null;
        if ($source === Release::MANUAL_NAME_SOURCE) {
            return false;
        }
        if ($source === ReleaseUpdateService::nameSource(ReleaseUpdateService::AUDIO_TAGS_TYPE)) {
            return true;
        }

        return (int) ($release['is_trusted_name'] ?? 0) === 0;
    }

    /**
     * Restore the prior values through the guarded name write, but only while the release still
     * carries the search name and name source the rename wrote; each other field is restored when
     * it still holds the rename's value. A renamed-since release keeps everything it has.
     */
    private function revert(ReleaseMusicRename $record): void
    {
        $current = $this->releaseFields($record->releases_id);
        $before = $record->before ?? [];
        $after = $record->after ?? [];
        $restore = [];
        $nameIntact = array_key_exists('searchname', $before) && array_key_exists('name_source', $before);
        foreach (self::NAME_FIELDS as $field) {
            $nameIntact = $nameIntact && $this->sameValue($current[$field] ?? null, $after[$field] ?? null);
        }
        if ($nameIntact) {
            foreach ($after as $field => $written) {
                if (! in_array($field, self::DERIVED_NAME_FIELDS, true) && array_key_exists($field, $before)
                    && $this->sameValue($current[$field] ?? null, $written)) {
                    $restore[$field] = $before[$field];
                }
            }
            if (! $this->nameWrites->restoreFromMusicIdentity($record->releases_id, $restore)) {
                $restore = [];
            }
        }

        $record->forceFill([
            'outcome' => MusicRenameOutcome::Reverted,
            'restored' => array_keys($restore),
            'reverted_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, scalar|null>|null  $before
     * @param  array<string, scalar|null>|null  $after
     */
    private function record(ReleaseMusicIdentification $decision, MusicRenameOutcome $outcome, ?MusicRenameDeclineReason $reason, ?array $before = null, ?array $after = null): void
    {
        ReleaseMusicRename::query()->create([
            'releases_id' => $decision->releases_id,
            'release_music_identification_id' => $decision->id,
            'outcome' => $outcome,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'applied_at' => $outcome === MusicRenameOutcome::Applied ? now() : null,
        ]);
    }

    /** @return array<string, scalar|null> the release's name fields and every field a rename can change */
    private function releaseFields(int $releaseId): array
    {
        $row = DB::table('releases')->where('id', $releaseId)->first([...self::NAME_FIELDS, ...self::CHANGEABLE_FIELDS]);

        return $row === null ? [] : array_map(static fn (mixed $value): mixed => is_scalar($value) ? $value : null, (array) $row);
    }

    /**
     * @param  array<string, scalar|null>  $fields
     * @param  list<string>  $names
     * @return array<string, scalar|null>
     */
    private function only(array $fields, array $names): array
    {
        return array_intersect_key($fields, array_flip($names));
    }

    /** Equal as stored values: NULL only equals NULL, anything else compares as text (1 equals '1'). */
    private function sameValue(mixed $left, mixed $right): bool
    {
        return $left === null || $right === null ? $left === $right : (string) $left === (string) $right;
    }

    /** @return list<int> releases whose current decision accepts an album and has no rename record */
    private function currentAlbumsWithoutRecord(): array
    {
        [$currentSql, $bindings] = CurrentMusicIdentityReader::currentIdentificationSql('i.releases_id');

        return DB::table('release_music_identifications as i')
            ->leftJoin('release_music_renames as rn', 'rn.release_music_identification_id', '=', 'i.id')
            ->whereNull('rn.id')
            ->whereIn('i.state', [IdentificationStatus::AcceptedReleaseGroup->value, IdentificationStatus::AcceptedEdition->value])
            ->whereRaw("i.id = {$currentSql}", $bindings)
            ->orderBy('i.id')
            ->pluck('i.releases_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Releases whose applied rename's decision is no longer current. The worker reverts these as
     * it persists the replacing decision, so this only catches a pass that stopped in between.
     *
     * @return list<int>
     */
    private function renamesNoLongerCurrent(): array
    {
        [$currentSql, $bindings] = CurrentMusicIdentityReader::currentIdentificationSql('rn.releases_id');

        return DB::table('release_music_renames as rn')
            ->where('rn.outcome', MusicRenameOutcome::Applied->value)
            ->whereRaw("rn.release_music_identification_id <> {$currentSql}", $bindings)
            ->orderBy('rn.id')
            ->pluck('rn.releases_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
