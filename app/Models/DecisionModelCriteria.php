<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of the criteria a decision-model use case asks its questions with.
 *
 * @property int $id
 * @property string $use_case
 * @property string $version
 * @property Carbon|null $created_at
 *
 * @mixin \Eloquent
 */
class DecisionModelCriteria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'decision_model_criteria';

    /** @var array<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
