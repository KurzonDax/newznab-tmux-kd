<?php

declare(strict_types=1);

namespace Tests\Unit;

use Dotenv\Dotenv;
use Tests\TestCase;

class EnvExampleConfigKeysTest extends TestCase
{
    /**
     * Application config files whose every env() key must be listed in .env.example.
     *
     * @var list<string>
     */
    private const array CONFIG_FILES = [
        'nntmux.php',
        'status-probes.php',
        'tmux.php',
        'tmdb.php',
        'igdb.php',
        'search.php',
    ];

    /**
     * Keys listed commented out at their config default, so that default stays in force.
     *
     * @var list<string>
     */
    private const array COMMENTED_DEFAULT_KEYS = [
        'MYSQL_SEARCH_FALLBACK',
        'RELEASE_DEDUPE_SIZE_TOLERANCE',
        'NNTMUX_CATEGORIZATION_LOG',
        'STATUS_PROBE_DISK_MOUNT_POINTS',
        'STATUS_PROBE_DISK_WARNING_THRESHOLD_GB',
        'STATUS_PROBE_DISK_CRITICAL_THRESHOLD_GB',
        'TMDB_TIMEOUT',
        'TMDB_RETRY_TIMES',
        'TMDB_RETRY_DELAY',
        'IGDB_BASE_URL',
        'TWITCH_TOKEN_URL',
        'MANTICORESEARCH_INDEX_RELEASES',
        'MANTICORESEARCH_INDEX_PREDB',
        'MANTICORESEARCH_INDEX_MOVIES',
        'MANTICORESEARCH_INDEX_MUSIC',
        'MANTICORESEARCH_INDEX_BOOKS',
        'MANTICORESEARCH_INDEX_GAMES',
        'MANTICORESEARCH_INDEX_CONSOLE',
        'MANTICORESEARCH_INDEX_STEAM',
        'MANTICORESEARCH_INDEX_ANIME',
        'MANTICORESEARCH_MAX_MATCHES',
        'MANTICORESEARCH_CACHE_MINUTES',
        'MANTICORESEARCH_FUZZY_MAX_DISTANCE',
    ];

    /**
     * Keys listed commented out with no literal default to compare against.
     *
     * @var list<string>
     */
    private const array COMMENTED_KEYS_WITHOUT_DEFAULT = [
        'BTCPAY_SECRET',
        'NZB_UPLOAD_FOLDER',
        'TMUX_CONFIG_FILE',
    ];

    /**
     * Keys nothing reads any more; .env.example must not list them, even commented out.
     *
     * @var list<string>
     */
    private const array UNREAD_KEYS = [
        'TMUX_TERMINAL',
        'TMUX_USE_POWERLINE',
        'TMUX_USE_NERD_FONTS',
        'TMUX_SESSION_NAME',
        'TMUX_TIMEOUT',
        'MANTICORESEARCH_FUZZY_LAYOUTS',
        'VITE_PORT',
        'SAIL_XDEBUG_MODE',
    ];

    public function test_every_env_key_the_config_files_read_is_listed(): void
    {
        $example = $this->envExample();

        foreach (self::CONFIG_FILES as $file) {
            preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $this->configSource($file), $matches);

            $this->assertNotEmpty($matches[1], "config/{$file} reads no env() keys.");

            foreach (array_unique($matches[1]) as $key) {
                $this->assertNotNull($this->exampleLine($example, $key), "config/{$file} reads {$key}, which .env.example does not list.");
            }
        }
    }

    public function test_commented_keys_show_their_config_default(): void
    {
        $example = $this->envExample();
        $sources = implode("\n", array_map($this->configSource(...), self::CONFIG_FILES));

        foreach ([...self::COMMENTED_DEFAULT_KEYS, ...self::COMMENTED_KEYS_WITHOUT_DEFAULT] as $key) {
            $this->assertSame('# ', $this->exampleLine($example, $key)['comment'] ?? null, ".env.example has no '# {$key}=' line.");
        }

        foreach (self::COMMENTED_DEFAULT_KEYS as $key) {
            $this->assertSame(
                $this->configDefault($sources, $key),
                $this->exampleLine($example, $key)['value'] ?? null,
                ".env.example shows a value for {$key} that is not its config default.",
            );
        }
    }

    public function test_example_values_keep_the_code_defaults_in_force(): void
    {
        $parsed = Dotenv::parse($this->envExample());

        $this->assertSame('irc.synirc.net', $parsed['SCRAPE_IRC_SERVER'] ?? null);
        $this->assertSame('6667', $parsed['SCRAPE_IRC_PORT'] ?? null);
        $this->assertSame('86400', $parsed['IGDB_CACHE_LIFETIME'] ?? null);
        $this->assertArrayNotHasKey('NN_CONCURRENCY_TIMEOUT', $parsed);

        foreach ([...self::COMMENTED_DEFAULT_KEYS, ...self::COMMENTED_KEYS_WITHOUT_DEFAULT] as $key) {
            $this->assertArrayNotHasKey($key, $parsed, "{$key} must stay commented out in .env.example.");
        }
    }

    public function test_unread_keys_are_not_listed(): void
    {
        $example = $this->envExample();

        foreach (self::UNREAD_KEYS as $key) {
            $this->assertNull($this->exampleLine($example, $key), ".env.example still lists {$key}.");
        }

        $this->assertStringNotContainsString('MANTICORESEARCH_FUZZY_LAYOUTS', (string) file_get_contents(base_path('.env.dist')));
        $this->assertFileDoesNotExist(base_path('.env.tmux.example'));
    }

    /**
     * The .env.example line for a key, set or commented out.
     *
     * @return array{comment: ?string, value: string}|null
     */
    private function exampleLine(string $example, string $key): ?array
    {
        if (preg_match('/^(?<comment>#\s*)?'.preg_quote($key, '/').'=(?<value>.*)$/m', $example, $line, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return ['comment' => $line['comment'], 'value' => (string) $line['value']];
    }

    /**
     * The scalar literal a config file passes as the env() default for a key, as .env.example writes it.
     */
    private function configDefault(string $sources, string $key): string
    {
        $pattern = '/env\(\s*([\'"])'.preg_quote($key, '/').'\1\s*,\s*(?:([\'"])(?<string>.*?)\2|(?<scalar>true|false|-?\d+(?:\.\d+)?))\s*\)/';

        $this->assertSame(1, preg_match($pattern, $sources, $default, PREG_UNMATCHED_AS_NULL), "{$key} has no scalar literal default in the config files.");

        return $default['scalar'] ?? (string) $default['string'];
    }

    private function envExample(): string
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertIsString($example);

        return $example;
    }

    private function configSource(string $file): string
    {
        $source = file_get_contents(config_path($file));

        $this->assertIsString($source);

        return $source;
    }
}
