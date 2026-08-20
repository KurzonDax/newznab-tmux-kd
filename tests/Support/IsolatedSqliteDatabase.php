<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Boots the application against a throwaway file-backed SQLite database.
 *
 * Needed because the app boots through `CategorizationPipeline`, which reads `Settings` before
 * any test body runs -- so a `settings` table has to exist *before* bootstrap, which the normal
 * in-memory test connection cannot provide. See AGENTS.md, "Testing".
 *
 * A class using this trait must call {@see self::bootIsolatedDatabase()} from `setUp()` after
 * `parent::setUp()`, and {@see self::tearDownIsolatedDatabase()} from `tearDown()` before it.
 */
trait IsolatedSqliteDatabase
{
    private string $isolatedDatabasePath = '';

    /** @var array<string, string|false> */
    private array $isolatedOriginalEnvironment = [];

    public function createApplication(): Application
    {
        $this->isolatedDatabasePath = $this->makeTempPath('nntmux-'.str_replace('\\', '-', static::class), '.sqlite');

        $this->isolatedOriginalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->isolatedDatabasePath)) {
            unlink($this->isolatedDatabasePath);
        }

        $pdo = new PDO('sqlite:'.$this->isolatedDatabasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');

        foreach ($this->bootstrapSettings() as $name => $value) {
            $statement = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
            $statement->execute([$name, $value]);
        }

        $this->setIsolatedEnvironmentValue('APP_ENV', 'testing');
        $this->setIsolatedEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setIsolatedEnvironmentValue('DB_DATABASE', $this->isolatedDatabasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Settings that must exist before the application boots.
     *
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0'];
    }

    protected function bootIsolatedDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->isolatedDatabasePath,
        ]);

        DB::purge();
        DB::reconnect();
    }

    protected function tearDownIsolatedDatabase(): void
    {
        if ($this->isolatedDatabasePath !== '' && file_exists($this->isolatedDatabasePath)) {
            unlink($this->isolatedDatabasePath);
        }

        foreach ($this->isolatedOriginalEnvironment as $key => $value) {
            $this->setIsolatedEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    private function setIsolatedEnvironmentValue(string $key, ?string $value): void
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
