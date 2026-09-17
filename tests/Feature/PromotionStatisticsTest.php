<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminPromotionController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class PromotionStatisticsTest extends TestCase
{
    public function test_summary_pages_keep_complete_date_filtered_totals_and_top_five(): void
    {
        $this->travelTo(now()->startOfSecond());
        foreach (['settings', 'role_promotions', 'role_promotion_stats', 'users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        ProductionTables::fromAuthority()->create('settings');
        ProductionTables::fromAuthority()->create('users', ['id', 'username', 'deleted_at']);
        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('role_promotions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active');
        });
        Schema::create('role_promotion_stats', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('role_promotion_id');
            $table->integer('user_id');
            $table->integer('role_id');
            $table->integer('days_added');
            $table->timestamp('applied_at');
        });
        DB::table('users')->insert(['id' => 1, 'username' => 'Member']);
        DB::table('roles')->insert(['id' => 1, 'name' => 'User']);
        for ($id = 1; $id <= 30; $id++) {
            DB::table('role_promotions')->insert(['id' => $id, 'name' => "Promotion {$id}", 'is_active' => $id <= 15]);
            DB::table('role_promotion_stats')->insert([
                ['role_promotion_id' => $id, 'user_id' => 1, 'role_id' => 1, 'days_added' => 2, 'applied_at' => now()->subDays(3)],
                ['role_promotion_id' => $id, 'user_id' => 1, 'role_id' => 1, 'days_added' => 4, 'applied_at' => now()->subDays(60)],
            ]);
        }
        $request = Request::create('/admin/promotions/statistics?promotions_page=2&period=7days');
        $this->app->instance('request', $request);
        $data = app(AdminPromotionController::class)->statistics($request)->getData();
        $this->assertSame([
            'total_promotions' => 30, 'active_promotions' => 15, 'total_applications' => 30,
            'unique_users' => 1, 'total_days_added' => 60,
        ], $data['overallStats']);
        $this->assertSame(30, $data['promotions']->total());
        $this->assertSame([26, 27, 28, 29, 30], $data['promotions']->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4, 5], $data['topPromotions']->pluck('id')->all());
        foreach ($data['promotions'] as $promotion) {
            $this->assertFalse($promotion->relationLoaded('statistics'));
        }
        $this->assertCount(10, $data['recentActivity']);
        $this->assertStringContainsString('period=7days', $data['promotions']->url(1));
        foreach (['30days' => 30, '90days' => 60, 'year' => 60, 'all' => 60] as $period => $expected) {
            $request = Request::create('/admin/promotions/statistics', 'GET', ['period' => $period]);
            $data = app(AdminPromotionController::class)->statistics($request)->getData();
            $this->assertSame($expected, $data['overallStats']['total_applications']);
        }
        $request = Request::create('/admin/promotions/statistics', 'GET', [
            'start_date' => now()->subDays(61)->toDateTimeString(), 'end_date' => now()->subDays(59)->toDateTimeString(),
        ]);
        $data = app(AdminPromotionController::class)->statistics($request)->getData();
        $this->assertSame(30, $data['overallStats']['total_applications']);
        $this->assertSame(120, $data['overallStats']['total_days_added']);
    }
}
