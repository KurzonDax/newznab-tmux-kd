<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;
use ReflectionProperty;
use Tests\TestCase;

class LogDailyRetentionConfigTest extends TestCase
{
    /**
     * Tests that boot the application with LOG_DAILY_DAYS set to a non-default value.
     *
     * @var list<string>
     */
    private const array TESTS_WITH_CUSTOM_RETENTION = [
        'test_daily_channels_follow_the_shared_env_key',
        'test_configured_days_reach_the_monolog_rotating_handler',
        'test_browser_channel_shares_the_retention_key',
    ];

    private const string CUSTOM_RETENTION = '21';

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->originalEnvironment = ['LOG_DAILY_DAYS' => getenv('LOG_DAILY_DAYS')];

        $this->setEnvironmentValue(
            'LOG_DAILY_DAYS',
            in_array($this->name(), self::TESTS_WITH_CUSTOM_RETENTION, true) ? self::CUSTOM_RETENTION : null,
        );

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_daily_channels_default_to_seven_days(): void
    {
        $channels = $this->dailyChannels();

        $this->assertGreaterThan(10, count($channels));

        foreach ($channels as $name => $channel) {
            $this->assertSame(7, $channel['days'], "Channel [{$name}] should default to 7 days.");
        }
    }

    public function test_daily_channels_follow_the_shared_env_key(): void
    {
        $channels = $this->dailyChannels();

        $this->assertGreaterThan(10, count($channels));

        foreach ($channels as $name => $channel) {
            $this->assertSame(21, $channel['days'], "Channel [{$name}] should read LOG_DAILY_DAYS.");
        }
    }

    public function test_configured_days_reach_the_monolog_rotating_handler(): void
    {
        $handlers = Log::channel('daily')->getLogger()->getHandlers();

        $this->assertInstanceOf(RotatingFileHandler::class, $handlers[0]);

        $maxFiles = new ReflectionProperty(RotatingFileHandler::class, 'maxFiles');

        $this->assertSame(21, $maxFiles->getValue($handlers[0]));
    }

    public function test_browser_channel_shares_the_retention_key(): void
    {
        // Laravel Boost injects its own `single`-driver browser channel only when
        // `config('logging.channels.browser')` is null (BoostServiceProvider::
        // registerBrowserLogger()), so defining it here is what disables that
        // injection — and the channel then rotates like every other daily channel.
        $browser = config('logging.channels.browser');

        $this->assertNotNull($browser);
        $this->assertSame('daily', $browser['driver']);
        $this->assertSame(21, $browser['days']);
        $this->assertSame(storage_path('logs/browser.log'), $browser['path']);
    }

    public function test_no_per_channel_retention_env_keys_are_introduced(): void
    {
        $config = file_get_contents(config_path('logging.php'));

        $this->assertIsString($config);

        preg_match_all("/env\('(LOG_[A-Z_]*DAYS)'/", $config, $matches);

        $this->assertSame(['LOG_DAILY_DAYS'], array_values(array_unique($matches[1])));
    }

    public function test_env_example_documents_the_retention_keys(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertIsString($example);
        $this->assertStringContainsString('LOG_DAILY_DAYS=7', $example);
        $this->assertStringContainsString('LOG_ROTATE_SIZE_MB=', $example);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function dailyChannels(): array
    {
        return array_filter(
            config('logging.channels'),
            static fn (array $channel): bool => ($channel['driver'] ?? null) === 'daily',
        );
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
