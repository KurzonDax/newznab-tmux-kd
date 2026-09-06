<?php

declare(strict_types=1);

namespace App\Services\MediaInfo;

use JsonSerializable;

final class MediaInfoDiagnosticNormalizer
{
    public const MAX_KEYS = 128;

    public const MAX_VALUE_LENGTH = 2048;

    public const MAX_DEPTH = 4;

    public const MAX_JSON_BYTES = 32768;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{raw: array<string, mixed>, filtered: bool, truncated: bool}
     */
    public function normalize(array $attributes): array
    {
        $raw = [];
        $filtered = false;
        $truncated = false;
        $remainingKeys = self::MAX_KEYS;

        foreach ($attributes as $key => $value) {
            if ($remainingKeys === 0) {
                $truncated = true;
                break;
            }
            $remainingKeys--;

            $normalizedKey = strtolower((string) $key);
            if ($this->isSensitiveKey($normalizedKey)) {
                $filtered = true;

                continue;
            }

            $normalized = $this->normalizeValue($value, $filtered, $truncated, $remainingKeys, 1);
            if ($normalized !== null) {
                $raw[$normalizedKey] = $normalized;
            }
        }

        while ($raw !== [] && strlen((string) json_encode($raw)) > self::MAX_JSON_BYTES) {
            array_pop($raw);
            $truncated = true;
        }

        return ['raw' => $raw, 'filtered' => $filtered, 'truncated' => $truncated];
    }

    private function isSensitiveKey(string $key): bool
    {
        return str_contains($key, 'cover')
            || str_contains($key, 'artwork')
            || str_contains($key, 'binary')
            || str_contains($key, 'encoded_library_settings')
            || in_array($key, ['complete_name', 'folder_name', 'file_name_extension'], true);
    }

    private function normalizeValue(
        mixed $value,
        bool &$filtered,
        bool &$truncated,
        int &$remainingKeys,
        int $depth,
    ): mixed {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                $truncated = true;

                return '[truncated]';
            }

            $values = [];
            foreach ($value as $key => $item) {
                if ($remainingKeys === 0) {
                    $truncated = true;
                    break;
                }
                $remainingKeys--;

                $normalizedKey = is_int($key) ? $key : strtolower((string) $key);
                if (is_string($normalizedKey) && $this->isSensitiveKey($normalizedKey)) {
                    $filtered = true;

                    continue;
                }

                $normalized = $this->normalizeValue($item, $filtered, $truncated, $remainingKeys, $depth + 1);
                if ($normalized !== null) {
                    $values[$normalizedKey] = $normalized;
                }
            }

            return $values;
        }

        if (is_string($value)) {
            if (str_starts_with($value, '/') || preg_match('/^[A-Za-z]:\\\\/', $value) === 1) {
                $filtered = true;

                return null;
            }
            if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $truncated = true;

                return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
            }

            return $value;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }
}
