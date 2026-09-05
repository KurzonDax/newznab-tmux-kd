<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NNTPService;
use App\Support\Settings\SettingsRegistry;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * {@see NNTPService::doConnect()} must always run out of attempts.
 *
 * `nntpretries` is edited through a plain text input, so an operator can store a 0 or a
 * negative number. The counter is decremented at the top of every pass and the exhaustion
 * guards compared it with `=== 0`, so a budget that started at or below zero stepped straight
 * over the only value that ends the loop: the service reconnected forever against a dead
 * provider and never returned an error. Every caller that touches usenet hung with it,
 * including {@see NNTPService::dataError()}, whose reconnect takes a fresh full budget.
 *
 * The stored value means "maximum number of connection attempts", which is what the admin
 * help text has always claimed; anything below 1 is one attempt.
 */
final class NNTPServiceConnectRetryBudgetTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function a_stored_zero_makes_one_attempt_and_then_errors(): void
    {
        $this->storeRetries('0');

        $client = new UnreachableProviderClient;
        $result = $client->doConnect();

        $this->assertSame(1, $client->connectAttempts);
        $this->assertNntpError($result, 'Cannot connect to NNTP provider');
    }

    #[Test]
    public function a_stored_negative_makes_one_attempt_and_then_errors(): void
    {
        $this->storeRetries('-5');

        $client = new UnreachableProviderClient;
        $result = $client->doConnect();

        $this->assertSame(1, $client->connectAttempts);
        $this->assertNntpError($result, 'Cannot connect to NNTP provider');
    }

    #[Test]
    public function a_missing_settings_row_makes_one_attempt_and_then_errors(): void
    {
        $this->assertSame(0, DB::table('settings')->where('name', 'nntpretries')->count());

        $client = new UnreachableProviderClient;
        $result = $client->doConnect();

        $this->assertSame(1, $client->connectAttempts);
        $this->assertNntpError($result, 'Cannot connect to NNTP provider');
    }

    #[Test]
    public function the_stored_value_is_the_number_of_connection_attempts(): void
    {
        $this->storeRetries('10');

        $client = new UnreachableProviderClient;

        $this->assertSame(10, $client->retryBudget());

        $result = $client->doConnect();

        $this->assertSame(10, $client->connectAttempts);
        $this->assertSame(
            [250000, 500000, 1000000, 2000000, 4000000, 5000000, 5000000, 5000000, 5000000],
            $client->retryDelays,
        );
        $this->assertNntpError($result, 'Cannot connect to NNTP provider');
    }

    #[Test]
    public function a_budget_that_enters_the_loop_below_zero_still_terminates(): void
    {
        $this->storeRetries('10');

        $client = new UnreachableProviderClient;
        // Not reachable through the settings clamp: this pins the loop's own guards, so a
        // future caller handing doConnect() a spent budget cannot resurrect the hang.
        $client->overrideRetryBudget(-3);

        $result = $client->doConnect();

        $this->assertSame(1, $client->connectAttempts);
        $this->assertNntpError($result, 'Cannot connect to NNTP provider');
    }

    #[Test]
    public function authentication_failure_returns_after_one_attempt_without_sleeping(): void
    {
        $this->storeRetries('3');

        $client = new UnreachableProviderClient;
        $client->providerAcceptsConnections = true;

        $result = $client->doConnect();

        $this->assertSame(1, $client->connectAttempts, 'A live socket is not reopened between authentication attempts.');
        $this->assertSame(1, $client->authenticateAttempts);
        $this->assertSame([], $client->retryDelays);
        $this->assertNntpError($result, 'Cannot authenticate to NNTP provider');
    }

    #[Test]
    public function a_stored_zero_bounds_authentication_failure_to_one_attempt(): void
    {
        $this->storeRetries('0');

        $client = new UnreachableProviderClient;
        $client->providerAcceptsConnections = true;

        $result = $client->doConnect();

        $this->assertSame(1, $client->authenticateAttempts);
        $this->assertNntpError($result, 'Cannot authenticate to NNTP provider');
    }

    #[Test]
    public function a_reachable_provider_still_connects_on_the_first_attempt(): void
    {
        $this->storeRetries('10');

        $client = new UnreachableProviderClient;
        $client->providerAcceptsConnections = true;
        $client->authenticationSucceeds = true;

        $this->assertTrue($client->doConnect());
        $this->assertSame(1, $client->connectAttempts);
        $this->assertSame(1, $client->authenticateAttempts);
    }

    #[Test]
    public function the_admin_help_text_describes_connection_backoff_and_authentication_failure(): void
    {
        $helpText = $this->nntpRetriesHelpText();

        $this->assertStringNotContainsString('no pause', $helpText);
        $this->assertStringContainsString('connection attempts', $helpText);
        $this->assertStringContainsString('250 milliseconds', $helpText);
        $this->assertStringContainsString('5 seconds', $helpText);
        $this->assertMatchesRegularExpression('/authentication.*without retry/i', $helpText);
        $this->assertMatchesRegularExpression('/below 1|less than 1/i', $helpText, 'The field accepts any text, so the help must say what a 0 does.');
    }

    private function storeRetries(string $value): void
    {
        DB::table('settings')->updateOrInsert(['name' => 'nntpretries'], ['value' => $value]);
    }

    private function assertNntpError(mixed $result, string $expectedFragment): void
    {
        $this->assertInstanceOf(NntpError::class, $result, 'An exhausted budget must return an error, not hang or fatal.');
        $this->assertStringContainsString($expectedFragment, $result->getMessage());
    }

    /**
     * The help the admin reads under the `nntpretries` input, taken from the settings registry
     * that renders it rather than from the markup.
     */
    private function nntpRetriesHelpText(): string
    {
        $definition = app(SettingsRegistry::class)->definition('nntpretries');

        $this->assertNotNull($definition, 'nntpretries must stay on the settings hub.');

        return strip_tags($definition->help);
    }
}

/**
 * Drives doConnect() over a provider that is never there: no socket is opened and no setting
 * other than the retry budget is consulted, so the attempt counters below are exactly the
 * loop's own behaviour.
 */
final class UnreachableProviderClient extends NNTPService
{
    /** Answer connect() with a live socket instead of a refusal. */
    public bool $providerAcceptsConnections = false;

    /** Answer authenticate() with success instead of a rejection. */
    public bool $authenticationSucceeds = false;

    public int $connectAttempts = 0;

    public int $authenticateAttempts = 0;

    /** @var list<int> */
    public array $retryDelays = [];

    /**
     * Turns a non-terminating loop into a failed test rather than a hung suite. Well above
     * any budget these tests store.
     */
    private const int ATTEMPT_CEILING = 50;

    public function provider(): NntpProvider
    {
        return new NntpProvider(
            position: 1,
            name: 'test-primary',
            host: 'news.example.invalid',
            port: 119,
            ssl: false,
            username: 'reader',
            password: 'secret',
            connections: 1,
            timeout: 5,
            enabled: true,
        );
    }

    public function retryBudget(): int
    {
        return $this->_nntpRetries;
    }

    public function overrideRetryBudget(int $retries): void
    {
        $this->_nntpRetries = $retries;
    }

    public function connect(?string $host = null, mixed $encryption = null, ?int $port = null, ?int $timeout = 15, int $socketTimeout = 120): mixed
    {
        $this->guardAgainstAnEndlessLoop(++$this->connectAttempts);

        return $this->providerAcceptsConnections ? true : new NntpError('Connection refused');
    }

    public function authenticate(?string $user, string $pass): mixed
    {
        $this->guardAgainstAnEndlessLoop(++$this->authenticateAttempts);

        return $this->authenticationSucceeds ? true : new NntpError('502 Authentication failed');
    }

    public function doQuit(bool $force = false): mixed
    {
        return true;
    }

    protected function sleepBeforeRetry(int $microseconds): void
    {
        $this->retryDelays[] = $microseconds;
    }

    public function _isConnected(): bool
    {
        return false;
    }

    private function guardAgainstAnEndlessLoop(int $attempts): void
    {
        if ($attempts > self::ATTEMPT_CEILING) {
            throw new RuntimeException('doConnect() made more than '.self::ATTEMPT_CEILING.' attempts: the retry budget no longer bounds the loop.');
        }
    }
}
