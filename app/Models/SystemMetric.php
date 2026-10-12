<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemMetric extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    protected $fillable = [
        'metric_type',
        'value',
        'recorded_at',
    ];

    protected $casts = [
        'value' => 'float',
        'recorded_at' => 'datetime',
    ];

    /**
     * Scope to get CPU metrics
     */
    public function scopeCpu(mixed $query): mixed
    {
        return $query->where('metric_type', 'cpu');
    }

    /**
     * Scope to get RAM metrics
     */
    public function scopeRam(mixed $query): mixed
    {
        return $query->where('metric_type', 'ram');
    }

    /**
     * Clean up old metrics (older than specified days)
     */
    public static function cleanupOldMetrics(int $days = 60): int
    {
        return static::where('recorded_at', '<', now()->subDays($days))->delete();
    }
}
