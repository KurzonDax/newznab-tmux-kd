<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostProcessingResetCounterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('categories_id');
            $table->integer('passwordstatus')->default(0);
            $table->integer('haspreview')->default(0);
            $table->integer('jpgstatus')->default(1);
            $table->integer('videostatus')->default(1);
            $table->integer('nfostatus')->default(1);
            $table->unsignedInteger('pp_timeout_count')->default(0);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });
    }

    public function test_misc_reset_clears_the_attempt_counter_when_it_returns_a_release_to_pending(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'categories_id' => Category::OTHER_MISC,
            'pp_timeout_count' => 2,
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'worker-token',
        ]);
        Search::shouldReceive('updateRelease')->once()->with(1);

        $this->artisan('nntmux:resetpp', ['--category' => ['misc']])->assertSuccessful();

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(-1, (int) $release->haspreview);
        $this->assertSame(0, (int) $release->passwordstatus);
        $this->assertSame(0, (int) $release->pp_timeout_count);
        $this->assertNull($release->additional_pp_claimed_at);
        $this->assertNull($release->additional_pp_claim_token);
    }
}
