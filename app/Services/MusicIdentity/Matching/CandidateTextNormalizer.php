<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Matching;

final class CandidateTextNormalizer
{
    public function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\.[a-z0-9]{2,5}$/iu', '', $value) ?? $value;
        $value = preg_replace('/^(?:(?:cd|disc)[ _.-]*)?\d{1,3}(?:[ ._-]+|$)/iu', '', $value) ?? $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }
}
