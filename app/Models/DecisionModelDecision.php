<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One decision the decision model was asked for, whatever the use case.
 *
 * @property int $id
 * @property int $decision_model_criteria_id
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $mode
 * @property string|array<string, mixed> $state The state exactly as sent.
 * @property list<array<string, array<string, mixed>>>|null $answers One answers map per request, in the order sent.
 * @property array<string, mixed>|null $pipeline_answer What the application had decided on its own.
 * @property array<string, mixed>|null $interpretation What the use case made of the answers.
 * @property bool $applied
 * @property string|null $failure_reason
 * @property Carbon|null $created_at
 * @property-read DecisionModelCriteria|null $criteria
 *
 * @mixin \Eloquent
 */
class DecisionModelDecision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'decision_model_decisions';

    /** @var array<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision_model_criteria_id' => 'integer',
            'subject_id' => 'integer',
            'state' => 'json',
            'answers' => 'array',
            'pipeline_answer' => 'array',
            'interpretation' => 'array',
            'applied' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DecisionModelCriteria, $this> */
    public function criteria(): BelongsTo
    {
        return $this->belongsTo(DecisionModelCriteria::class, 'decision_model_criteria_id', 'id');
    }
}
