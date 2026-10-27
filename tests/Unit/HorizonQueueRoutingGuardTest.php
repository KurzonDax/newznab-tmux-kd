<?php

declare(strict_types=1);

namespace Tests\Unit;

use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Horizon consumes only the queues in its supervisor list (#1150). Every onQueue() site in app/
 * must name a queue that the default list, loaded with HORIZON_QUEUES unset, contains. Any other
 * way of routing a job to a queue fails until this test learns to resolve it.
 */
class HorizonQueueRoutingGuardTest extends TestCase
{
    /** The onQueue() sites in app/ when #1150 was built. */
    private const int MINIMUM_ON_QUEUE_SITES = 11;

    /** Method calls that route a job, mailable, notification or listener to a queue. */
    private const array OTHER_ROUTE_METHODS = ['viaqueue', 'viaqueues', 'allonqueue', 'queueon', 'lateron'];

    private const string QUEUE_ATTRIBUTE = 'Illuminate\\Queue\\Attributes\\Queue';

    /** File => the declared type of a $queue property that holds no queue name, with the reason. */
    private const array ALLOWED_QUEUE_PROPERTIES = [
        'app/Services/StatusProbes/QueueProbe.php' => ['QueueFactory', 'the injected queue manager the status probe measures'],
    ];

    private string|false $originalHorizonQueues;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalHorizonQueues = getenv('HORIZON_QUEUES');
        $this->setHorizonQueues(null);
    }

    protected function tearDown(): void
    {
        $this->setHorizonQueues($this->originalHorizonQueues === false ? null : $this->originalHorizonQueues);

        parent::tearDown();
    }

    public function test_every_queue_the_application_dispatches_to_is_consumed_by_horizon(): void
    {
        $scan = $this->scan(base_path('app'));

        $this->assertSame([], $scan['failures'], implode("\n", $scan['failures']));
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_ON_QUEUE_SITES,
            count($scan['sites']),
            'The scanner found fewer onQueue() sites in app/ than #1150 counted; it is no longer reading them.',
        );
    }

    public function test_an_unlisted_queue_fails(): void
    {
        $directory = $this->makeTempDirectory();
        file_put_contents($directory.'/Unlisted.php', "<?php\n\nSomeJob::dispatch()->onQueue('unlisted');\n");

        $scan = $this->scan($directory);

        $this->assertCount(1, $scan['failures']);
        $this->assertStringContainsString('Unlisted.php:3', $scan['failures'][0]);
        $this->assertStringContainsString("'unlisted'", $scan['failures'][0]);
    }

    public function test_a_queue_property_fails(): void
    {
        $directory = $this->makeTempDirectory();
        file_put_contents($directory.'/QueueProperty.php', "<?php\n\nclass QueueProperty\n{\n    public \$queue = 'x';\n}\n");

        $scan = $this->scan($directory);

        $this->assertCount(1, $scan['failures']);
        $this->assertStringContainsString('QueueProperty.php:5', $scan['failures'][0]);
        $this->assertStringContainsString('Extend this test', $scan['failures'][0]);
    }

    #[DataProvider('unresolvedRouteProvider')]
    public function test_a_route_this_test_cannot_resolve_fails(string $source): void
    {
        $directory = $this->makeTempDirectory();
        file_put_contents($directory.'/Route.php', "<?php\n\n".$source."\n");

        $scan = $this->scan($directory);

        $this->assertCount(1, $scan['failures'], implode("\n", $scan['failures']));
        $this->assertStringContainsString('Route.php:3', $scan['failures'][0]);
        $this->assertStringContainsString('Extend this test', $scan['failures'][0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unresolvedRouteProvider(): array
    {
        return [
            'queue attribute' => ["#[Queue('x')] class AttributeJob {}"],
            'queue attribute after another' => ["#[Tries(3), Queue('x')] class AttributeJob {}"],
            'viaQueue' => ['class Listener { public function viaQueue(): string { return "x"; } }'],
            'viaQueues' => ['class Note { public function viaQueues(): array { return []; } }'],
            'allOnQueue' => ["Bus::chain([])->allOnQueue('x');"],
            'queueOn' => ["Mail::queueOn('x', \$mailable);"],
            'laterOn' => ["Mail::laterOn('x', 60, \$mailable);"],
            'queue route' => ["Queue::route(SomeJob::class, 'x');"],
            'promoted queue property' => ['class Job { public function __construct(public string $queue) {} }'],
            'variable onQueue argument' => ['SomeJob::dispatch()->onQueue($name);'],
            'onQueue in another case' => ['SomeJob::dispatch()->OnQueue($name);'],
        ];
    }

    /**
     * Reads every PHP file under the directory. A site is an onQueue() call whose queue name
     * resolved; a failure names the file and line of anything this test cannot vouch for.
     *
     * @return array{sites: list<string>, failures: list<string>}
     */
    private function scan(string $directory): array
    {
        $horizonQueues = (require base_path('config/horizon.php'))['defaults']['supervisor-1']['queue'];
        $sites = [];
        $failures = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = str_replace(base_path().'/', '', $path);
            $tokens = array_values(array_filter(
                PhpToken::tokenize((string) file_get_contents($path)),
                static fn (PhpToken $token): bool => ! $token->isIgnorable(),
            ));
            $attributeNames = $this->attributeNameIndexes($tokens);

            foreach ($tokens as $index => $token) {
                $location = $relativePath.':'.$token->line;
                $name = strtolower($token->text);
                $next = $tokens[$index + 1] ?? null;
                $previous = $tokens[$index - 1] ?? null;
                $callsWithParenthesis = $next !== null && $next->text === '(';

                if ($token->is(T_STRING) && $name === 'onqueue' && $callsWithParenthesis) {
                    $queue = $this->resolveQueueArgument($this->callArguments($tokens, $index + 1));
                    if ($queue === null) {
                        $failures[] = $location.': onQueue() argument is not a string literal or config(\'<key>\', \'<default>\'), '
                            .'optionally cast with (string). Use one of those forms. Extend this test to resolve any other form.';
                    } elseif (! in_array($queue, $horizonQueues, true)) {
                        $failures[] = $location.": onQueue() names queue '".$queue."', which the default HORIZON_QUEUES list "
                            .'in config/horizon.php does not consume. Add it to that list.';
                    } else {
                        $sites[] = $location;
                    }

                    continue;
                }

                $route = match (true) {
                    $token->is(T_STRING) && in_array($name, self::OTHER_ROUTE_METHODS, true) && $callsWithParenthesis => $token->text.'(',
                    $token->is(T_STRING) && $name === 'route' && $callsWithParenthesis && $previous?->is(T_DOUBLE_COLON)
                        && $this->lastNameSegment($tokens[$index - 2] ?? null) === 'Queue' => 'Queue::route(',
                    isset($attributeNames[$index]) && $this->lastNameSegment($token) === 'Queue' && $callsWithParenthesis => '#[Queue(',
                    $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ltrim($token->text, '\\') === self::QUEUE_ATTRIBUTE => self::QUEUE_ATTRIBUTE,
                    $token->is(T_VARIABLE) && $token->text === '$queue' && $this->isPropertyDeclaration($tokens, $index)
                        && $previous?->text !== (self::ALLOWED_QUEUE_PROPERTIES[$relativePath][0] ?? null) => 'a $queue property',
                    default => null,
                };

                if ($route !== null) {
                    $failures[] = $location.': '.$route.' routes work to a queue this test does not resolve. '
                        .'Extend this test to resolve that form and check the queue is in the default HORIZON_QUEUES list.';
                }
            }
        }

        return ['sites' => $sites, 'failures' => $failures];
    }

    /**
     * The tokens between the parenthesis at $open and its matching close.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>|null null when the call never closes
     */
    private function callArguments(array $tokens, int $open): ?array
    {
        $depth = 0;
        $arguments = [];
        for ($index = $open; $index < count($tokens); $index++) {
            $text = $tokens[$index]->text;
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
            }
            if ($depth === 0) {
                return $arguments;
            }
            if ($index !== $open) {
                $arguments[] = $tokens[$index];
            }
        }

        return null;
    }

    /**
     * Resolves 'name', config('key', 'default') or either form cast with (string).
     *
     * @param  list<PhpToken>|null  $arguments
     */
    private function resolveQueueArgument(?array $arguments): ?string
    {
        if ($arguments === null) {
            return null;
        }
        if (isset($arguments[0]) && $arguments[0]->is(T_STRING_CAST)) {
            array_shift($arguments);
        }

        if (count($arguments) === 1 && $arguments[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return $this->stringLiteral($arguments[0]->text);
        }

        $isConfigCall = count($arguments) === 6
            && $arguments[0]->is(T_STRING) && $arguments[0]->text === 'config'
            && $arguments[1]->text === '('
            && $arguments[2]->is(T_CONSTANT_ENCAPSED_STRING)
            && $arguments[3]->text === ','
            && $arguments[4]->is(T_CONSTANT_ENCAPSED_STRING)
            && $arguments[5]->text === ')';

        return $isConfigCall
            ? (string) config($this->stringLiteral($arguments[2]->text), $this->stringLiteral($arguments[4]->text))
            : null;
    }

    private function stringLiteral(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'" ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($body);
    }

    /**
     * Indexes of the attribute names in each #[ ... ] group: the first name and each name after
     * a top-level comma.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<int, true>
     */
    private function attributeNameIndexes(array $tokens): array
    {
        $names = [];
        foreach ($tokens as $index => $token) {
            if (! $token->is(T_ATTRIBUTE)) {
                continue;
            }

            $brackets = 1;
            $parentheses = 0;
            $expectName = true;
            for ($cursor = $index + 1; $cursor < count($tokens) && $brackets > 0; $cursor++) {
                $current = $tokens[$cursor];
                $topLevel = $brackets === 1 && $parentheses === 0;
                if ($topLevel && $expectName && $current->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $names[$cursor] = true;
                }
                $expectName = $topLevel && $current->text === ',';
                match ($current->text) {
                    '[' => $brackets++,
                    ']' => $brackets--,
                    '(' => $parentheses++,
                    ')' => $parentheses--,
                    default => null,
                };
            }
        }

        return $names;
    }

    /**
     * True when the variable follows a property modifier, past any type declaration; this
     * covers ordinary and constructor-promoted properties.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function isPropertyDeclaration(array $tokens, int $index): bool
    {
        $typeTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE, T_STATIC, '?', '|', '&'];
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY, T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET];

        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if ($token->is($modifiers)) {
                return true;
            }
            if (! $token->is($typeTokens)) {
                return false;
            }
        }

        return false;
    }

    private function lastNameSegment(?PhpToken $token): ?string
    {
        if ($token === null || ! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return null;
        }

        $segments = explode('\\', $token->text);

        return end($segments);
    }

    private function setHorizonQueues(?string $value): void
    {
        if ($value === null) {
            putenv('HORIZON_QUEUES');
            unset($_ENV['HORIZON_QUEUES'], $_SERVER['HORIZON_QUEUES']);

            return;
        }

        putenv('HORIZON_QUEUES='.$value);
        $_ENV['HORIZON_QUEUES'] = $value;
        $_SERVER['HORIZON_QUEUES'] = $value;
    }
}
