<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RegistrationFailureLogService;
use PHPUnit\Framework\TestCase;

class RegistrationFailureLogServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/registration-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_returns_newest_failures_and_reports_malformed_candidates(): void
    {
        file_put_contents($this->directory.'/registration.log', implode("\n", [
            $this->failure('older'),
            'unrelated log line',
            '[invalid] testing.WARNING: Registration attempt failed: invalid {} []',
            '[2026-03-10 12:00:00] testing.WARNING: Registration attempt failed: invalid {broken} []',
            $this->failure('newer'),
        ]));

        $result = (new RegistrationFailureLogService($this->directory))->recentFailures();

        self::assertSame(['newer', 'older'], array_column($result['entries'], 'reason'));
        self::assertSame(2, $result['skipped_malformed']);
        self::assertSame(0, $result['skipped_oversized']);
        self::assertSame('2026-03-10 12:00:00', $result['entries'][0]['timestamp']->format('Y-m-d H:i:s'));
        self::assertSame('warning', $result['entries'][0]['level']);
        self::assertSame('person@example.invalid', $result['entries'][0]['email']);
    }

    public function test_tab_separated_context_preserves_all_parsed_fields(): void
    {
        $context = [
            'reason' => 'denied', 'username' => 'person', 'email' => 'person@example.invalid',
            'ip' => '192.0.2.1', 'registration_status' => 1, 'manual_registration_status' => 2,
        ];
        file_put_contents($this->directory.'/registration.log',
            "[2026-03-10 12:00:00] testing.WARNING: Registration attempt failed: denied\t".json_encode($context).' []'
        );
        $entry = (new RegistrationFailureLogService($this->directory))->recentFailures()['entries'][0];
        self::assertSame('2026-03-10 12:00:00', $entry['timestamp']->format('Y-m-d H:i:s'));
        unset($entry['timestamp']);
        self::assertSame([
            'level' => 'warning', 'message' => 'Registration attempt failed: denied',
            ...$context, 'context' => $context,
        ], $entry);
    }

    public function test_multiblock_files_return_exactly_the_newest_ten_with_bounded_memory(): void
    {
        $service = new RegistrationFailureLogService($this->directory);
        file_put_contents($this->directory.'/registration.log', $this->failure('warmup'));
        $service->recentFailures();

        foreach ([1000, 10000] as $lineCount) {
            $handle = fopen($this->directory.'/registration.log', 'wb');
            $chunk = str_repeat("Unrelated synthetic log line\n", 1000);
            for ($index = 0; $index < $lineCount / 1000; $index++) {
                fwrite($handle, $chunk);
            }
            for ($index = 0; $index < 20; $index++) {
                fwrite($handle, ($index % 2 === 1 ? $this->failure((string) $index) : 'unrelated')."\n");
            }
            fclose($handle);
            file_put_contents($this->directory.'/registration-old.log', 'Registration attempt failed: malformed');
            touch($this->directory.'/registration-old.log', 1);
            clearstatcache();

            memory_reset_peak_usage();
            $before = memory_get_usage();
            $result = $service->recentFailures();
            $peak = memory_get_peak_usage() - $before;

            self::assertSame(['19', '17', '15', '13', '11', '9', '7', '5', '3', '1'], array_column($result['entries'], 'reason'));
            self::assertSame(0, $result['skipped_malformed']);
            self::assertLessThanOrEqual(4 * 1024 * 1024, $peak);
        }
    }

    public function test_no_matches_scan_to_the_start_with_bounded_memory(): void
    {
        $handle = fopen($this->directory.'/registration.log', 'wb');
        fwrite($handle, "Registration attempt failed: malformed\n");
        $chunk = str_repeat("Unrelated synthetic log line\n", 1000);
        for ($index = 0; $index < 10; $index++) {
            fwrite($handle, $chunk);
        }
        fclose($handle);
        $service = new RegistrationFailureLogService($this->directory);
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $result = $service->recentFailures();
        $peak = memory_get_peak_usage() - $before;

        self::assertSame([], $result['entries']);
        self::assertSame(1, $result['skipped_malformed']);
        self::assertLessThanOrEqual(4 * 1024 * 1024, $peak);
    }

    public function test_boundary_lines_utf8_crlf_and_oversized_entries(): void
    {
        $prefix = '[2026-03-10 12:00:00] testing.WARNING: Registration attempt failed: boundary {"reason":"';
        $suffix = '"} []';
        $reason = str_repeat('a', 131072 - strlen($prefix.$suffix) - strlen('雪')).'雪';
        $boundary = $prefix.$reason.$suffix;
        file_put_contents($this->directory.'/registration.log',
            $this->failure('old')."\r\n\r\n".$boundary."\r\n".str_repeat('x', 131073)."\n".$this->failure('最新')
        );

        $result = (new RegistrationFailureLogService($this->directory))->recentFailures();

        self::assertSame(['最新', $reason, 'old'], array_column($result['entries'], 'reason'));
        self::assertSame(1, $result['skipped_oversized']);
        self::assertSame(0, $result['skipped_malformed']);
    }

    public function test_file_order_uses_mtime_then_filename_and_honors_limit(): void
    {
        foreach (['b', 'a', 'c'] as $name) {
            $path = $this->directory.'/registration-'.$name.'.log';
            file_put_contents($path, $this->failure($name));
            touch($path, $name === 'c' ? 200 : 100);
        }
        $service = new RegistrationFailureLogService($this->directory);
        self::assertSame(['c', 'a', 'b'], array_column($service->recentFailures()['entries'], 'reason'));
        self::assertSame(['c'], array_column($service->recentFailures(1)['entries'], 'reason'));
        self::assertSame([], $service->recentFailures(0)['entries']);
    }

    private function failure(string $reason): string
    {
        return '[2026-03-10 12:00:00] testing.WARNING: Registration attempt failed: '.$reason.' '.json_encode([
            'reason' => $reason, 'email' => 'person@example.invalid',
        ], JSON_THROW_ON_ERROR).' []';
    }
}
