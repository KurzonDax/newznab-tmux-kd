<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use UnexpectedValueException;

/** Late evidence is checked against the adopted artifact, never an old rendering of proof. */
final class CurrentPostingReconciler
{
    public function __construct(private readonly PostingEvidence $evidence) {}

    public function reconcile(int $collectionId, int $quietHours, ?float $cycleDeadline = null): ?string
    {
        if (! Schema::hasTable('reconciled_artifacts')) {
            return null;
        }
        $source = DB::table('collections')->where('id', $collectionId)->first();
        if ($source === null) {
            return null;
        }
        $queries = new PopulationQuery;
        $envelope = $queries->sourceWindow($source, 1800);
        if ($envelope === null) {
            return null;
        }
        $window = [$envelope['from'], $envelope['until']];
        $matches = DB::table('reconciled_artifacts as a')->join('reconciled_postings as p', 'p.release_id', '=', 'a.release_id')
            ->where('p.state', 'published')->where(static function ($query) use ($source, $window): void {
                $query->where(static fn ($envelope) => $envelope->where('a.discovery_group_id', $source->groups_id)
                    ->where('a.discovery_count', $source->declaredfiles)->whereBetween('a.discovery_postdate', $window)
                    ->where('a.discovery_poster', $source->fromname));
                $query->orWhereExists(static function ($aliases) use ($source, $window): void {
                    $aliases->selectRaw('1')->from('reconciled_sources as s')->whereColumn('s.posting_id', 'p.id')
                        ->whereColumn('s.epoch', 'a.epoch')->where('s.group_id', $source->groups_id)->whereBetween('s.postdate', $window);
                });
            })->orderBy('a.release_id')->limit(257)->get(['a.*', 'p.id as journal_id']);
        if ($matches->isEmpty()) {
            return null;
        }
        if ($matches->count() !== 1) {
            return 'late_competing_artifacts';
        }
        $artifact = $matches->first();
        if ($artifact->pending_operation !== null) {
            return 'late_artifact_operation_pending';
        }
        $release = Release::query()->find($artifact->release_id);
        if ($release === null || $release->guid !== $artifact->guid || (int) $release->declaredfiles !== (int) $source->declaredfiles) {
            return null;
        }
        $owner = (string) Str::uuid();
        $pending = (new PendingInventory)->load([$collectionId]);
        $evidenceContext = $artifact->release_id.':'.$artifact->version.':'.$artifact->epoch.':'.$artifact->proof_revision;
        $deadline = app(CollectionClaims::class)->claim([$collectionId], $owner, PendingInventory::digest($pending), $quietHours, $evidenceContext);
        if ($deadline === null) {
            return 'late_claim_unavailable';
        }
        $lease = RecoveryLease::acquire($release);
        if ($lease === null) {
            app(CollectionClaims::class)->retry($owner, 'cycle_yield');

            return 'late_anchor_busy';
        }
        try {
            $revision = DB::transaction(function () use ($collectionId, $owner, $pending, $evidenceContext): ?string {
                DB::table('collections')->where('id', $collectionId)->lockForUpdate()->first();
                if (! DB::table('reconciliation_claims')->where('collection_id', $collectionId)->where('owner', $owner)
                    ->where('revision', CollectionClaims::revision(PendingInventory::digest($pending), $evidenceContext))->exists()
                    || PendingInventory::digest((new PendingInventory)->load([$collectionId])) !== PendingInventory::digest($pending)) {
                    return null;
                }

                return ArtifactSourceRevision::capture($collectionId);
            }, 3);
            if ($revision === null) {
                return 'late_source_changed';
            }
            $xml = app(NzbService::class)->readNzbContents($release->guid);
            if ($xml === false || hash('sha256', $xml) !== $artifact->digest) {
                return 'late_artifact_conflict';
            }
            $current = ArtifactInventory::load($xml);
            $targetXml = $current->append($pending);
            $target = ArtifactInventory::load($targetXml);
            $changed = $target->classifyAgainst($current) !== 'serialization';
            $proof = null;
            $expectedSources = [$collectionId => $revision];
            $populationSnapshot = [];
            $update = new ArtifactReleaseUpdate;
            if ($changed) {
                $provenance = json_decode($artifact->provenance, true, flags: JSON_THROW_ON_ERROR);
                $incoming = [];
                $incomingKeys = [];
                foreach ($pending as $file) {
                    $key = array_key_first(ArtifactInventory::load((new PostingNzb)->render([$file]))->files());
                    $groups = array_values(array_unique([...($provenance['files'][$key] ?? []), 'source:'.$file->sourceId]));
                    $incoming[$key] = $groups;
                    $incomingKeys[] = 'current:'.$key;
                    $provenance['files'][$key] = $groups;
                    $provenance['components'][] = $groups;
                }
                $candidates = $target->proofCandidates($provenance);
                $populationWindow = $queries->sourceWindow($source);
                if ($populationWindow === null) {
                    app(CollectionClaims::class)->retry($owner, 'cycle_yield');

                    return 'late_source_population';
                }
                $population = $queries->readWindow($populationWindow);
                if (! $population['complete']) {
                    app(CollectionClaims::class)->retry($owner, 'cycle_yield');

                    return 'late_source_population';
                }
                $nearby = DB::table('collections')->whereIn('id', $population['rows']->pluck('id'))
                    ->where('id', '!=', $collectionId)->tap(static fn ($query) => RecoveryCollectionOwnership::exclude($query))
                    ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $eligible = app(CollectionAdmission::class)->eligibleIds($nearby, $quietHours);
                if ($eligible === null) {
                    return 'late_file_population';
                }
                $frozen = DB::transaction(static function () use ($nearby, $eligible): array {
                    DB::table('collections')->whereIn('id', $nearby)->orderBy('id')->lockForUpdate()->get();
                    $revisions = [];
                    foreach ($nearby as $id) {
                        $revisions[$id] = ArtifactSourceRevision::capture($id);
                    }

                    return ['files' => (new PendingInventory)->load($eligible), 'revisions' => $revisions];
                }, 3);
                $competitors = $frozen['files'];
                $expectedSources += $frozen['revisions'];
                $populationSnapshot = ['population' => $populationWindow];
                foreach (DB::table('binaries')->whereIn('collections_id', array_diff($nearby, $eligible))->limit(1025)->pluck('name') as $subject) {
                    if (preg_match('/"([^"\r\n]+)"/', $subject, $name) !== 1 || preg_match('/\.par2$/iD', $name[1]) === 1
                        || in_array($name[1], array_column($pending, 'filename'), true)
                        || (preg_match('/\[([0-9]+)\/[0-9]+\]/', $subject, $ordinal) === 1 && in_array((int) $ordinal[1], array_column($pending, 'ordinal'), true))) {
                        return 'late_unsupported_competitor';
                    }
                }
                $decisions = (new PostingHypotheses)->resolve([...$candidates, ...$competitors], function (array $hypothesis) use ($artifact, $deadline, $cycleDeadline): PostingDecision {
                    $base = array_values(array_filter($hypothesis, static fn (PostingFile $file): bool => $file->isBasePar2()))[0];
                    $budget = str_starts_with($base->sourceId, 'component:') ? 'late:'.$artifact->release_id.':'.$artifact->epoch
                        : app(CollectionAdmission::class)->decisionId((int) $base->sourceId, $base->firstArticle());

                    return $this->evidence->resolve($hypothesis, $budget,
                        min($cycleDeadline ?? INF, microtime(true) + max(0, $deadline - now()->timestamp)));
                });
                $decision = null;
                foreach ($decisions as $candidate) {
                    if (array_diff($incomingKeys, array_column($candidate->accepted, 'fileId')) === []
                        && array_any($candidate->accepted, static fn (PostingFile $file): bool => $file->isBasePar2() && str_starts_with($file->fileId, 'current:'))) {
                        $decision = new PostingDecision(array_values(array_filter($candidate->accepted, static fn (PostingFile $file): bool => str_starts_with($file->fileId, 'current:'))),
                            $candidate->declaredTotal, $candidate->setId, $candidate->label, $candidate->unresolved, $candidate->evidence, $candidate->reason);
                        break;
                    }
                }
                if ($decision === null) {
                    return 'late_unverified';
                }
                if ($decision->independentVideos() && PostingPublication::hasTrustedIdentity($release)) {
                    return 'trusted_bundle_identity_conflict';
                }
                $proof = ['journal_id' => $artifact->journal_id, 'digest' => PendingInventory::digest($decision->accepted),
                    'inventory' => PendingInventory::encode($decision->accepted), 'decision' => json_encode($decision, JSON_THROW_ON_ERROR),
                    'preserve_provenance' => true, 'incoming_groups' => $incoming];
                $update = new ArtifactReleaseUpdate('reconciliation', ['size' => $target->bytes()], declaredFiles: (int) $release->declaredfiles,
                    independentVideos: $decision->independentVideos());
            } else {
                $targetXml = $xml;
            }
            $result = app(ArtifactPublication::class)->replace($release->guid, $targetXml, $lease, $artifact->digest, $update,
                [$collectionId], ['version' => (int) $artifact->version, 'epoch' => (int) $artifact->epoch, 'proof_revision' => (int) $artifact->proof_revision, ...$populationSnapshot],
                $expectedSources, $proof);
            if (str_contains($result->reason, 'changed_during_verification')) {
                app(CollectionClaims::class)->retry($owner, 'cycle_yield');
            } else {
                app(CollectionClaims::class)->settle($owner, $result->operationId !== null ? 'associated' : 'late_checked');
            }

            return $result->success ? ($changed ? 'late_added' : 'replayed') : $result->reason;
        } catch (UnexpectedValueException $exception) {
            app(CollectionClaims::class)->settle($owner, 'late_unverified');

            return $exception->getMessage();
        } catch (RuntimeException $exception) {
            app(CollectionClaims::class)->retry($owner, $cycleDeadline !== null && microtime(true) >= $cycleDeadline ? 'cycle_yield' : $exception->getMessage());

            return $exception->getMessage();
        } finally {
            $lease->release();
            app(CollectionClaims::class)->settle($owner, 'late_checked');
        }
    }
}
