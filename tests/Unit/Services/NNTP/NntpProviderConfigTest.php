<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NNTP;

use App\Services\NNTP\Contracts\ProviderClient;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Config assembly, and the structural guarantees the pool is supposed to give:
 * header operations are unreachable through it, and the article contract is unchanged.
 */
final class NntpProviderConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        NntpProviderPool::forgetConfiguredProviders();
    }

    protected function tearDown(): void
    {
        NntpProviderPool::forgetConfiguredProviders();
        parent::tearDown();
    }

    #[Test]
    public function it_parses_numbered_provider_groups_in_position_order(): void
    {
        config()->set('nntmux_nntp.providers', [
            ['position' => 2, 'name' => 'eu', 'host' => 'eu.example.org', 'port' => 563, 'ssl' => true],
            ['position' => 1, 'name' => 'us', 'host' => 'us.example.org', 'port' => 119],
        ]);

        $providers = NntpProviderPool::configuredProviders();

        $this->assertSame(['us', 'eu'], array_map(fn ($p) => $p->name, $providers));
        $this->assertTrue($providers[0]->isPrimary());
        $this->assertFalse($providers[1]->isPrimary());
        $this->assertTrue($providers[1]->ssl);
        $this->assertSame(563, $providers[1]->port);
    }

    #[Test]
    public function the_primary_is_the_provider_at_position_one(): void
    {
        config()->set('nntmux_nntp.providers', [
            ['position' => 1, 'name' => 'us', 'host' => 'us.example.org'],
            ['position' => 2, 'name' => 'eu', 'host' => 'eu.example.org'],
        ]);

        $this->assertSame('us', NntpProviderPool::primaryProvider()->name);
    }

    #[Test]
    public function disabled_providers_stay_configured_but_leave_the_enabled_set(): void
    {
        config()->set('nntmux_nntp.providers', [
            ['position' => 1, 'name' => 'us', 'host' => 'us.example.org'],
            ['position' => 2, 'name' => 'eu', 'host' => 'eu.example.org', 'enabled' => false],
        ]);

        $pool = new NntpProviderPool;

        $this->assertCount(2, $pool->providers());
        $this->assertSame(['us'], array_map(fn ($p) => $p->name, $pool->enabledProviders()));
    }

    #[Test]
    public function an_empty_provider_list_fails_loudly_with_migration_guidance(): void
    {
        config()->set('nntmux_nntp.providers', []);

        // Parsing an empty list is not itself fatal -- constructing the pool (and therefore
        // booting artisan, including nntp:pool-status) must still work so the misconfiguration
        // can be reported rather than crashing every command.
        $this->assertSame([], NntpProviderPool::configuredProviders());
        $this->assertSame([], (new NntpProviderPool)->providers());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NNTP_PROVIDER_1_HOST');

        NntpProviderPool::primaryProvider();
    }

    #[Test]
    public function connections_are_advisory_metadata_and_never_gate_an_operation(): void
    {
        config()->set('nntmux_nntp.providers', [
            ['position' => 1, 'name' => 'us', 'host' => 'us.example.org', 'connections' => 40],
        ]);

        $this->assertSame(40, NntpProviderPool::primaryProvider()->connections);

        // CONNECTIONS is an operator-chosen split of what may be a shared account budget, so
        // the pool must not enforce it: a provider declaring one connection still answers every
        // article we ask it for. Excess connections are the provider's to refuse, and the
        // breaker treats a refusal as an ordinary failure.
        $client = new class implements ProviderClient
        {
            public int $calls = 0;

            public function provider(): NntpProvider
            {
                return new NntpProvider(1, 'us', 'us.example.org', 119, false, '', '', 1, 120, true);
            }

            public function doConnect(bool $compression = true): mixed
            {
                return true;
            }

            public function fetchArticleBody(string $messageId): mixed
            {
                $this->calls++;

                return 'BODY';
            }

            public function statArticle(string $messageId): mixed
            {
                return true;
            }

            public function doQuit(bool $force = false): mixed
            {
                return true;
            }
        };

        $pool = new NntpProviderPool(
            [new NntpProvider(1, 'us', 'us.example.org', 119, false, '', '', 1, 120, true)],
            null,
            static fn (NntpProvider $p): ProviderClient => $client,
        );

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('BODY', $pool->fetchArticleBody('<a'.$i.'>'));
        }

        $this->assertSame(5, $client->calls);
    }

    #[Test]
    public function the_pool_offers_no_header_operations(): void
    {
        $headerOperations = ['selectGroup', 'getXOVER', 'getOverview', 'getGroups', 'getHeaderField', 'group'];
        $poolMethods = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(NntpProviderPool::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach ($headerOperations as $operation) {
            $this->assertNotContains(
                $operation,
                $poolMethods,
                "The pool must not expose {$operation}: article numbers are per-server, so header "
                .'work is only meaningful against provider 1 and must stay off this API.'
            );
        }

        $clientMethods = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(ProviderClient::class))->getMethods(),
        );

        foreach ($headerOperations as $operation) {
            $this->assertNotContains($operation, $clientMethods);
        }
    }

    #[Test]
    public function get_messages_by_message_id_keeps_its_single_argument_concatenating_contract(): void
    {
        $method = new ReflectionMethod(NNTPService::class, 'getMessagesByMessageID');

        $this->assertCount(
            1,
            $method->getParameters(),
            'The alternate-provider flag is gone: failover is the pool\'s job, not the caller\'s.'
        );
        $this->assertSame('identifiers', $method->getParameters()[0]->getName());
        $this->assertSame('mixed', (string) $method->getReturnType());
    }
}
