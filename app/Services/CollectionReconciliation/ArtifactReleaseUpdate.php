<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Enums\ReleaseRepairOutcome;
use App\Models\Category;
use App\Models\Release;
use App\Services\ReleaseRepair\EvidenceChangedTransition;
use App\Services\ReleaseRepair\NzbRepairDocument;
use InvalidArgumentException;

/** A writer can persist only the fields it owns, never a stale release snapshot. */
final readonly class ArtifactReleaseUpdate
{
    private const array FIELDS = [
        'ordinary' => [],
        'duplicate' => ['size', 'declaredfiles'],
        'repair' => ['repair_attempted_at', 'repair_outcome', 'repair_target_completion', 'repair_evaluated_target_completion'],
        'rescan' => ['rescan_attempted_at', 'rescan_outcome', 'rescan_target_completion', 'rescan_evaluated_target_completion'],
        'reconciliation' => ['size', 'declaredfiles', 'nzbstatus'],
    ];

    /**
     * @param  array<string, int|float|string|bool|null>  $values
     * @param  array<string, mixed>  $result
     */
    public function __construct(public string $kind = 'ordinary', public array $values = [],
        public bool $evidenceChanged = false, public ?int $declaredFiles = null, public array $result = [], public ?string $label = null, public bool $independentVideos = false)
    {
        if (! isset(self::FIELDS[$kind]) || array_diff(array_keys($values), self::FIELDS[$kind]) !== []) {
            throw new InvalidArgumentException('unsupported_artifact_update');
        }
    }

    public function encode(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    public static function decode(string $encoded): self
    {
        $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        return new self($data['kind'] ?? 'ordinary', $data['values'] ?? [], $data['evidenceChanged'] ?? false,
            $data['declaredFiles'] ?? null, $data['result'] ?? [], $data['label'] ?? null, $data['independentVideos'] ?? false);
    }

    public function allows(Release $release): bool
    {
        return $this->kind !== 'reconciliation' || ! $this->independentVideos || ! PostingPublication::hasTrustedIdentity($release);
    }

    /** @return array<string, mixed> */
    public function apply(Release $release, string $xml): array
    {
        $document = NzbRepairDocument::load($xml);
        if ($document === null) {
            throw new InvalidArgumentException('unmeasurable_artifact');
        }
        $requeued = $this->evidenceChanged && (new EvidenceChangedTransition)->apply($release, $document, $this->declaredFiles, false);
        $completion = $document->measure($this->declaredFiles)->percentage();
        $values = $this->values;
        if ($this->kind === 'reconciliation' && $this->allows($release)) {
            if ($this->label !== null && ! $release->is_trusted_name) {
                $values += ['name' => $this->label, ...Release::searchNameValues($this->label), 'is_trusted_name' => false, 'isrenamed' => 0];
            }
            if ($this->independentVideos) {
                $values['categories_id'] = Category::OTHER_MISC;
            }
        }
        $result = $this->result;
        if (in_array($this->kind, ['repair', 'rescan'], true)) {
            $prefix = $this->kind;
            $outcome = $values[$prefix.'_outcome'] ?? null;
            $target = $values[$prefix.'_evaluated_target_completion'] ?? null;
            if ($outcome !== null && $release->getRawOriginal($prefix.'_outcome') === ReleaseRepairOutcome::Repaired->value) {
                $values[$prefix.'_outcome'] = ReleaseRepairOutcome::Repaired->value;
                $result['outcome'] = ReleaseRepairOutcome::Repaired->value;
                if ($target !== null && $completion < $target) {
                    $values[$prefix.'_target_completion'] = $release->getRawOriginal($prefix.'_target_completion');
                }
            }
            if ($prefix === 'rescan' && $target !== null && $completion >= $target
                && $release->getRawOriginal('repair_outcome') === ReleaseRepairOutcome::RetryPending->value) {
                $values['repair_outcome'] = ReleaseRepairOutcome::Repaired->value;
                $values['repair_target_completion'] = $target;
                $values['repair_evaluated_target_completion'] = $target;
            }
        }
        $release->newQuery()->whereKey($release->id)->update($values + [
            'totalpart' => $document->fileCount(), 'completion' => $completion,
        ]);

        return array_replace($result, ['success' => true, 'requeuedForAdditionalProcessing' => $requeued]);
    }
}
