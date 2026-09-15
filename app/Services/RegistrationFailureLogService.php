<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ReverseLineReader;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

class RegistrationFailureLogService
{
    private string $logsDirectory;

    public function __construct(?string $logsDirectory = null)
    {
        $this->logsDirectory = rtrim($logsDirectory ?? storage_path('logs'), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{entries: list<array<string, mixed>>, skipped_oversized: int, skipped_malformed: int}
     */
    public function recentFailures(int $limit = 10): array
    {
        $result = ['entries' => [], 'skipped_oversized' => 0, 'skipped_malformed' => 0];
        if ($limit <= 0) {
            return $result;
        }

        $reader = new ReverseLineReader;
        foreach ($this->registrationLogFiles() as $path) {
            foreach ($reader->lines($path) as $line) {
                if ($line === null) {
                    $result['skipped_oversized']++;

                    continue;
                }
                if (! str_contains($line, 'Registration attempt failed:')) {
                    continue;
                }
                $entry = $this->parseLine($line);
                if ($entry === null) {
                    $result['skipped_malformed']++;

                    continue;
                }
                $result['entries'][] = $entry;
                if (count($result['entries']) >= $limit) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function registrationLogFiles(): array
    {
        if (! is_dir($this->logsDirectory)) {
            return [];
        }

        $paths = glob($this->logsDirectory.DIRECTORY_SEPARATOR.'registration*.log*') ?: [];
        $paths = array_values(array_filter($paths, static fn (string $path): bool => is_file($path) && is_readable($path)));

        usort($paths, static function (string $left, string $right): int {
            return (@filemtime($right) <=> @filemtime($left)) ?: strcmp($left, $right);
        });

        return $paths;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseLine(string $line): ?array
    {
        if (! preg_match('/^\[(?<timestamp>[^\]]+)\]\s+[A-Za-z0-9_.-]+\.(?<level>[A-Z]+):\s+(?<message>.+)$/', $line, $matches)) {
            return null;
        }

        $message = $matches['message'];
        $context = [];
        if (preg_match('/\s\{/', $message, $contextMatch, PREG_OFFSET_CAPTURE)) {
            $contextStart = $contextMatch[0][1];
            $json = substr($message, $contextStart + 1);
            $json = preg_replace('/\s+\[\]$/', '', $json) ?? $json;
            $context = json_decode($json, true);
            if (! is_array($context) || json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            $message = rtrim(substr($message, 0, $contextStart));
        }

        try {
            $timestamp = CarbonImmutable::parse($matches['timestamp']);
            $errors = CarbonImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }
        } catch (InvalidFormatException) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'level' => strtolower($matches['level']),
            'message' => $message,
            'reason' => $context['reason'] ?? null,
            'username' => $context['username'] ?? null,
            'email' => $context['email'] ?? null,
            'ip' => $context['ip'] ?? null,
            'registration_status' => $context['registration_status'] ?? null,
            'manual_registration_status' => $context['manual_registration_status'] ?? null,
            'context' => $context,
        ];
    }
}
