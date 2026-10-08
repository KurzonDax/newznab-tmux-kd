<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

use App\Enums\ImageAssetProfile;
use App\Services\MusicIdentity\CurrentMusicIdentity;
use App\Services\MusicIdentity\CurrentMusicIdentityReader;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\ReleaseImageService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Fetches and stores the Cover Art Archive front of an accepted album (issue #1015), keeping one
 * outcome per lookup in `music_cover_art_lookups`:
 *
 * - an accepted edition asks for its release's front and, when the release has none, its release
 *   group's front through the release group's own lookup, so the releases of one album share one
 *   file and one download;
 * - an accepted release group asks for its own front.
 *
 * A stored image is named after the MusicBrainz id of the image actually stored, under the covers
 * root's `audio/` folder. "No front image" is never checked again; "failed" retries with backoff.
 * A lookup that waits too long for the shared pacing lock is deferred with no outcome. Nothing
 * here touches the identity decision.
 */
final readonly class AlbumCoverFetcher
{
    public function __construct(
        private CoverArtPacer $pacer,
        private CurrentMusicIdentityReader $identities = new CurrentMusicIdentityReader,
        private CoverArtArchiveClient $client = new CoverArtArchiveClient,
        private ReleaseImageService $images = new ReleaseImageService,
    ) {}

    /** Looks up the album's cover unless its outcome is settled; false when the pacing lock deferred it. */
    public function fetchFor(CurrentMusicIdentity $identity): bool
    {
        $lookup = $identity->coverLookup();
        if ($lookup === null) {
            return true;
        }

        try {
            [$kind, $musicBrainzId] = $lookup;
            $kind === CoverArtKind::Release
                ? $this->fetchEditionFront($musicBrainzId, $identity->musicBrainzReleaseGroupId)
                : $this->fetchFront(CoverArtKind::ReleaseGroup, $musicBrainzId);

            return true;
        } catch (LockTimeoutException) {
            Log::info('Album cover lookup deferred: the Cover Art Archive pacing lock is busy.', ['identification_id' => $identity->identificationId]);

            return false;
        }
    }

    /**
     * The `mus` pass's catch-up: accepted album decisions whose lookup has no outcome yet, or a
     * failed one now due, newest first, kept only when CurrentMusicIdentityReader (the one rule
     * the pages use) says the decision is the release's current one; at most
     * `backfill_batch_size` lookups, stopping at the first pacing deferral.
     *
     * @return int the decisions looked up
     */
    public function backfill(): int
    {
        $limit = max(1, (int) config('music-identity.cover_art.backfill_batch_size', 10));
        $chunk = max(100, $limit);
        $looked = 0;
        $before = null;

        do {
            $candidates = $this->awaitingLookup($before, $chunk);
            if ($candidates->isEmpty()) {
                break;
            }
            $before = (int) $candidates->last()->id;
            $current = $this->identities->forReleases($candidates->pluck('releases_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all());

            foreach ($candidates as $candidate) {
                $identity = $current[(int) $candidate->releases_id] ?? null;
                // An older or withdrawn decision is skipped; the current one is a candidate of its own when it awaits a lookup.
                if ($identity === null || $identity->identificationId !== (int) $candidate->id) {
                    continue;
                }
                if (! $this->fetchFor($identity)) {
                    return $looked;
                }
                if (++$looked >= $limit) {
                    return $looked;
                }
            }
        } while ($candidates->count() === $chunk);

        return $looked;
    }

    /**
     * Accepted album decisions whose own lookup has no outcome or a failed one now due, newest
     * first, below the id $before.
     *
     * @return Collection<int, \stdClass>
     */
    private function awaitingLookup(?int $before, int $chunk): Collection
    {
        $now = now();
        $awaiting = static function (string $alias) use ($now): \Closure {
            return static function ($query) use ($alias, $now): void {
                $query->whereNull($alias.'.id')->orWhere(static function ($failed) use ($alias, $now): void {
                    $failed->where($alias.'.outcome', CoverArtOutcome::Failed->value)
                        ->where(static fn ($at) => $at->whereNull($alias.'.next_attempt_at')->orWhere($alias.'.next_attempt_at', '<=', $now));
                });
            };
        };

        return DB::table('release_music_identifications as i')
            ->leftJoin('music_cover_art_lookups as cr', static function ($join): void {
                $join->on('cr.musicbrainz_id', '=', 'i.musicbrainz_release_id')->where('cr.kind', CoverArtKind::Release->value);
            })
            ->leftJoin('music_cover_art_lookups as cg', static function ($join): void {
                $join->on('cg.musicbrainz_id', '=', 'i.musicbrainz_release_group_id')->where('cg.kind', CoverArtKind::ReleaseGroup->value);
            })
            ->where(static function ($query) use ($awaiting): void {
                $query->where(static function ($edition) use ($awaiting): void {
                    $edition->where('i.state', IdentificationStatus::AcceptedEdition->value)->whereNotNull('i.musicbrainz_release_id')->where($awaiting('cr'));
                })->orWhere(static function ($group) use ($awaiting): void {
                    $group->where('i.state', IdentificationStatus::AcceptedReleaseGroup->value)->whereNotNull('i.musicbrainz_release_group_id')->where($awaiting('cg'));
                });
            })
            ->when($before !== null, static fn ($query) => $query->where('i.id', '<', $before))
            ->orderByDesc('i.id')
            ->limit($chunk)
            ->get(['i.id', 'i.releases_id']);
    }

    /** @throws LockTimeoutException */
    private function fetchEditionFront(string $releaseId, ?string $groupId): void
    {
        $existing = $this->existing(CoverArtKind::Release, $releaseId);
        if (! $this->due($existing)) {
            return;
        }

        $front = $this->pacer->run(fn (): CoverArtFront => $this->client->front(CoverArtKind::Release, $releaseId));
        if ($front->outcome !== CoverArtOutcome::NoFrontImage || $groupId === null) {
            $this->record(CoverArtKind::Release, $releaseId, $existing, ...$this->store($front, $releaseId));

            return;
        }

        // The release has no front: the edition shows its release group's, through that group's own lookup.
        $this->fetchFront(CoverArtKind::ReleaseGroup, $groupId);
        $group = $this->existing(CoverArtKind::ReleaseGroup, $groupId);
        $outcome = $group === null ? CoverArtOutcome::Failed : CoverArtOutcome::from((string) $group->outcome);
        $this->record(CoverArtKind::Release, $releaseId, $existing, $outcome,
            $outcome === CoverArtOutcome::Stored ? (string) $group?->image_musicbrainz_id : null,
            $outcome === CoverArtOutcome::Failed ? 'Release group front: '.($group->last_error ?? 'no outcome') : null);
    }

    /** @throws LockTimeoutException */
    private function fetchFront(CoverArtKind $kind, string $musicBrainzId): void
    {
        $existing = $this->existing($kind, $musicBrainzId);
        if (! $this->due($existing)) {
            return;
        }

        $front = $this->pacer->run(fn (): CoverArtFront => $this->client->front($kind, $musicBrainzId));
        $this->record($kind, $musicBrainzId, $existing, ...$this->store($front, $musicBrainzId));
    }

    /**
     * Saves a downloaded front through ReleaseImageService (validated, kept at its own size).
     *
     * @return array{0: CoverArtOutcome, 1: ?string, 2: ?string} the outcome, the stored image's id, the error
     */
    private function store(CoverArtFront $front, string $musicBrainzId): array
    {
        if ($front->outcome !== CoverArtOutcome::Stored) {
            return [$front->outcome, null, $front->error];
        }

        $source = tempnam(sys_get_temp_dir(), 'cover-art-');
        if ($source === false) {
            return [CoverArtOutcome::Failed, null, 'Unable to stage the downloaded image.'];
        }
        try {
            File::put($source, $front->bytes);
            $root = rtrim((string) config('nntmux_settings.covers_path', storage_path('covers')), '/');
            $saved = $this->images->saveLocalImage($musicBrainzId, $source, $root.'/audio/', ImageAssetProfile::Original);
        } finally {
            File::delete($source);
        }

        return $saved->success
            ? [CoverArtOutcome::Stored, $musicBrainzId, null]
            : [CoverArtOutcome::Failed, null, $saved->failureReason ?? 'The image could not be stored.'];
    }

    private function existing(CoverArtKind $kind, string $musicBrainzId): ?object
    {
        return DB::table('music_cover_art_lookups')->where('kind', $kind->value)->where('musicbrainz_id', $musicBrainzId)->first();
    }

    /** No outcome yet, or a failed lookup whose retry time has come. */
    private function due(?object $existing): bool
    {
        if ($existing === null) {
            return true;
        }

        return $existing->outcome === CoverArtOutcome::Failed->value
            && ($existing->next_attempt_at === null || now()->greaterThanOrEqualTo($existing->next_attempt_at));
    }

    private function record(CoverArtKind $kind, string $musicBrainzId, ?object $existing, CoverArtOutcome $outcome, ?string $imageId, ?string $error): void
    {
        $attempts = (int) ($existing->attempt_count ?? 0) + 1;
        $initial = max(1, (int) config('music-identity.cover_art.retry.initial_seconds', 3_600));
        $maximum = max($initial, (int) config('music-identity.cover_art.retry.maximum_seconds', 604_800));
        $now = now();

        DB::table('music_cover_art_lookups')->updateOrInsert(
            ['kind' => $kind->value, 'musicbrainz_id' => $musicBrainzId],
            [
                'outcome' => $outcome->value,
                'image_musicbrainz_id' => $outcome === CoverArtOutcome::Stored ? $imageId : null,
                'attempt_count' => $attempts,
                'next_attempt_at' => $outcome === CoverArtOutcome::Failed
                    ? $now->copy()->addSeconds((int) min($maximum, $initial * (2 ** min(30, $attempts - 1))))
                    : null,
                'last_error' => $outcome === CoverArtOutcome::Failed ? $error : null,
                'checked_at' => $now,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ],
        );
    }
}
