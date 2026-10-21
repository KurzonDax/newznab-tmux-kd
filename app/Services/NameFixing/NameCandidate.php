<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Services\ObfuscationRecovery\RecoveryNameEvidence;

/** A name some evidence offers for a release, with what its rename should carry along. */
final readonly class NameCandidate
{
    /**
     * @param  string  $method  the CLI label of the method that found the name
     * @param  ?int  $categoryId  the category the rename files the release under; null lets the pipeline decide
     * @param  bool  $descriptiveTitle  whether the name came from a guarded descriptive-title fallback
     * @param  list<string>  $keepLinks  metadata link columns the rename leaves as they are
     */
    public function __construct(
        public int $releaseId,
        public string $title,
        public NameSource $source,
        public string $method,
        public int $preDbId = 0,
        public ?string $imdbId = null,
        public ?int $categoryId = null,
        public bool $descriptiveTitle = false,
        public ?RecoveryNameEvidence $recoveryEvidence = null,
        public array $keepLinks = [],
    ) {}
}
