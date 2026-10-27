<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorizonQueueConfigurationTest extends TestCase
{
    private const array DEFAULT_QUEUES = ['api-audit', 'default', 'emails', 'incidents', 'contactemail', 'newreg', 'welcomeemails'];

    private const array QUEUE_KEYS = ['HORIZON_QUEUES', 'REDIS_QUEUE', 'API_AUDIT_QUEUE', 'MAIL_QUEUE', 'MAIL_INCIDENT_QUEUE'];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::QUEUE_KEYS as $key) {
            $this->originalEnvironment[$key] = getenv($key);
            $this->setEnvironment($key, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironment($key, $value === false ? null : $value);
        }

        parent::tearDown();
    }

    public function test_default_list_covers_every_application_queue(): void
    {
        $this->assertSame(self::DEFAULT_QUEUES, $this->supervisorQueues());
    }

    #[DataProvider('queueKeyProvider')]
    public function test_each_queue_key_replaces_only_its_own_entry(string $key, string $value, int $position): void
    {
        $this->setEnvironment($key, $value);

        $expected = self::DEFAULT_QUEUES;
        $expected[$position] = $value;

        $this->assertSame($expected, $this->supervisorQueues());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function queueKeyProvider(): array
    {
        return [
            'api audit queue' => ['API_AUDIT_QUEUE', 'auditq', 0],
            'redis default queue' => ['REDIS_QUEUE', 'jobsq', 1],
            'mail queue' => ['MAIL_QUEUE', 'mailq', 2],
            'mail incident queue' => ['MAIL_INCIDENT_QUEUE', 'incq', 3],
        ];
    }

    public function test_queue_keys_naming_the_same_queue_give_one_entry(): void
    {
        $this->setEnvironment('MAIL_INCIDENT_QUEUE', 'emails');

        $this->assertSame(
            ['api-audit', 'default', 'emails', 'contactemail', 'newreg', 'welcomeemails'],
            $this->supervisorQueues(),
        );
    }

    public function test_explicit_horizon_queues_replaces_the_whole_list(): void
    {
        $this->setEnvironment('HORIZON_QUEUES', 'a,b');

        $this->assertSame(['a', 'b'], $this->supervisorQueues());
    }

    public function test_explicit_horizon_queues_are_trimmed_filtered_and_deduplicated(): void
    {
        $this->setEnvironment('HORIZON_QUEUES', ' a , ,b,a ');

        $this->assertSame(['a', 'b'], $this->supervisorQueues());
    }

    public function test_balance_and_worker_limits_are_unchanged(): void
    {
        $horizon = require base_path('config/horizon.php');

        $this->assertSame('auto', $horizon['defaults']['supervisor-1']['balance']);
        $this->assertSame(1, $horizon['defaults']['supervisor-1']['maxProcesses']);
        $this->assertSame(10, $horizon['environments']['production']['supervisor-1']['maxProcesses']);
        $this->assertSame(3, $horizon['environments']['local']['supervisor-1']['maxProcesses']);
    }

    /**
     * @return list<string>
     */
    private function supervisorQueues(): array
    {
        $horizon = require base_path('config/horizon.php');

        return $horizon['defaults']['supervisor-1']['queue'];
    }

    private function setEnvironment(string $key, ?string $value): void
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
