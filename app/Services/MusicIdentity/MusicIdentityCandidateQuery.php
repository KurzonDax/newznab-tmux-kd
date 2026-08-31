<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Models\Category;
use App\Models\Release;
use App\Services\AudioProcessing\AudioRouting;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Single admission predicate for the evidence-based music identity worker.
 */
final class MusicIdentityCandidateQuery
{
    /** @return Builder<Release> */
    public static function query(
        int|string $groupId = '',
        string $guidChar = '',
        ?string $algorithmVersion = null,
    ): Builder {
        $algorithmVersion ??= (string) config('music-identity.algorithm_version', 'music-identity-v1');
        $query = Release::query()->from('releases as r');

        AudioRouting::applyAudioPath($query);
        $query->where('r.categories_id', '!=', Category::MUSIC_VIDEO);

        if ($groupId !== '') {
            $query->where('r.groups_id', $groupId);
        }
        if ($guidChar !== '') {
            $query->where('r.leftguid', 'like', $guidChar.'%');
        }

        return $query->where(function (Builder $eligibility) use ($algorithmVersion): void {
            $eligibility
                ->where(function (Builder $needsSynthesis) use ($algorithmVersion): void {
                    $needsSynthesis
                        ->whereNotExists(function (QueryBuilder $evidence): void {
                            $evidence
                                ->selectRaw('1')
                                ->from('release_audio_evidence as any_evidence')
                                ->whereColumn('any_evidence.releases_id', 'r.id');
                        })
                        ->where(function (Builder $synthesisState) use ($algorithmVersion): void {
                            $synthesisState
                                ->whereNotExists(function (QueryBuilder $attempt) use ($algorithmVersion): void {
                                    self::synthesisAttemptForRelease($attempt, $algorithmVersion);
                                })
                                ->orWhereExists(function (QueryBuilder $attempt) use ($algorithmVersion): void {
                                    self::synthesisAttemptForRelease($attempt, $algorithmVersion)
                                        ->where(function (QueryBuilder $due): void {
                                            $due
                                                ->whereNull('current_synthesis.next_attempt_at')
                                                ->orWhere('current_synthesis.next_attempt_at', '<=', now());
                                        })
                                        ->where(function (QueryBuilder $lease): void {
                                            $lease
                                                ->whereNull('current_synthesis.lease_expires_at')
                                                ->orWhere('current_synthesis.lease_expires_at', '<=', now());
                                        });
                                });
                        });
                })
                ->orWhereExists(function (QueryBuilder $latestEvidence) use ($algorithmVersion): void {
                    $latestEvidence
                        ->selectRaw('1')
                        ->from('release_audio_evidence as current_evidence')
                        ->whereColumn('current_evidence.releases_id', 'r.id')
                        ->whereNotExists(function (QueryBuilder $newerEvidence): void {
                            $newerEvidence
                                ->selectRaw('1')
                                ->from('release_audio_evidence as newer_evidence')
                                ->whereColumn('newer_evidence.releases_id', 'current_evidence.releases_id')
                                ->whereColumn('newer_evidence.revision', '>', 'current_evidence.revision');
                        })
                        ->where(function (QueryBuilder $decisionState) use ($algorithmVersion): void {
                            $decisionState
                                ->whereNotExists(function (QueryBuilder $decision) use ($algorithmVersion): void {
                                    self::identificationForEvidence($decision, $algorithmVersion);
                                })
                                ->orWhereExists(function (QueryBuilder $decision) use ($algorithmVersion): void {
                                    self::identificationForEvidence($decision, $algorithmVersion)
                                        ->where(function (QueryBuilder $retryable): void {
                                            $retryable
                                                ->where('current_identification.state', IdentificationStatus::Pending->value)
                                                ->orWhere(function (QueryBuilder $dueRetry): void {
                                                    $dueRetry
                                                        ->where('current_identification.state', IdentificationStatus::RetryableError->value)
                                                        ->where(function (QueryBuilder $due): void {
                                                            $due
                                                                ->whereNull('current_identification.next_attempt_at')
                                                                ->orWhere('current_identification.next_attempt_at', '<=', now());
                                                        });
                                                });
                                        })
                                        ->where(function (QueryBuilder $lease): void {
                                            $lease
                                                ->whereNull('current_identification.lease_expires_at')
                                                ->orWhere('current_identification.lease_expires_at', '<=', now());
                                        });
                                });
                        });
                });
        });
    }

    /**
     * @return list<object{id: string}>
     */
    public static function buckets(): array
    {
        $expression = DB::getDriverName() === 'sqlite'
            ? 'substr(r.leftguid, 1, 1)'
            : 'LEFT(r.leftguid, 1)';

        return self::query()
            ->selectRaw($expression.' AS id')
            ->distinct()
            ->limit(16)
            ->toBase()
            ->get()
            ->all();
    }

    private static function identificationForEvidence(
        QueryBuilder $decision,
        string $algorithmVersion,
    ): QueryBuilder {
        return $decision
            ->selectRaw('1')
            ->from('release_music_identifications as current_identification')
            ->whereColumn('current_identification.releases_id', 'r.id')
            ->whereColumn('current_identification.evidence_hash', 'current_evidence.evidence_hash')
            ->where('current_identification.algorithm_version', $algorithmVersion);
    }

    private static function synthesisAttemptForRelease(
        QueryBuilder $attempt,
        string $algorithmVersion,
    ): QueryBuilder {
        return $attempt
            ->selectRaw('1')
            ->from('release_music_synthesis_attempts as current_synthesis')
            ->whereColumn('current_synthesis.releases_id', 'r.id')
            ->where('current_synthesis.algorithm_version', $algorithmVersion);
    }
}
