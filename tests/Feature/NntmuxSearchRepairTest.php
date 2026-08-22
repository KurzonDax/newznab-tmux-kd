<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\NntmuxSearchRepair;
use App\Facades\Search;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class NntmuxSearchRepairTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::create('search_index_failures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id')->unique();
            $table->string('operation', 32)->default('upsert');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_one_failed_release_does_not_prevent_later_due_releases_from_processing(): void
    {
        $this->insertFailure(1, 1);
        $this->insertFailure(2, 1);

        Search::shouldReceive('updateRelease')->once()->with(1)->andThrow(new RuntimeException('broken projection'));
        Search::shouldReceive('updateRelease')->once()->with(2);

        $this->artisan('nntmux:search-repair', ['--limit' => 100])
            ->assertSuccessful();

        $this->addToAssertionCount(1);
    }

    public function test_failure_at_the_attempt_cap_is_marked_terminal_and_logged_once(): void
    {
        $this->insertFailure(42, NntmuxSearchRepair::MAX_ATTEMPTS);

        Search::shouldReceive('updateRelease')->never();
        Search::shouldReceive('deleteRelease')->never();
        Log::spy();

        $this->artisan('nntmux:search-repair')->assertSuccessful();
        $this->artisan('nntmux:search-repair')->assertSuccessful();

        $failure = DB::table('search_index_failures')->where('release_id', 42)->first();
        $this->assertNotNull($failure->resolved_at);
        $this->assertNull($failure->next_attempt_at);
        $this->assertSame('gave_up', $failure->last_error);
        Log::shouldHaveReceived('error')->once()->with(
            'Search index repair gave up on release after reaching the attempt cap.',
            \Mockery::on(static fn (array $context): bool => $context === [
                'release_id' => 42,
                'attempts' => NntmuxSearchRepair::MAX_ATTEMPTS,
            ])
        );
    }

    private function insertFailure(int $releaseId, int $attempts): void
    {
        DB::table('search_index_failures')->insert([
            'release_id' => $releaseId,
            'operation' => 'upsert',
            'attempts' => $attempts,
            'last_error' => 'insertRelease',
            'next_attempt_at' => now()->subMinute(),
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
