<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupListCommandTest extends TestCase
{
    public function test_list_reports_verified_files_from_sets_on_disk(): void
    {
        $location = $this->makeTempDirectory('nntmux-backup-list');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 25)->primary();
            $table->text('value')->nullable();
        });
        Schema::create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 10);
            $table->string('set_id');
            $table->text('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20);
            $table->string('offsite_status', 20)->nullable();
            $table->timestamp('offsite_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $location],
        ]);

        $set = $location.'/20260816-020000';
        mkdir($set);
        $this->writeBackup($set, 'full', '20260816-0200', '2026-08-16T02:00:00-05:00');
        $this->writeBackup($set, 'daily', '20260817-0200', '2026-08-17T02:00:00-05:00');

        $this->artisan('backup:list')
            ->expectsOutputToContain('full')
            ->expectsOutputToContain('daily')
            ->assertSuccessful();

        $this->assertDatabaseCount('database_backups', 2);
        $this->assertDatabaseHas('database_backups', [
            'set_id' => '20260816-020000',
            'kind' => 'full',
            'status' => 'successful',
        ]);
    }

    private function writeBackup(string $set, string $kind, string $timestamp, string $startedAt): void
    {
        $dump = "{$set}/{$kind}-{$timestamp}.sql.gz";
        file_put_contents($dump, gzencode("{$kind} backup\n"));
        file_put_contents($dump.'.manifest.json', json_encode([
            'kind' => $kind,
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'tables' => ['settings', 'users'],
            'tiers_included' => ['important'],
            'bytes' => filesize($dump),
            'sha256' => hash_file('sha256', $dump),
            'app_version' => 'test',
            'db_server_version' => 'test',
            'set_id' => basename($set),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
