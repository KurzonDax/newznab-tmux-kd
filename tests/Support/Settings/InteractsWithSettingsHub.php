<?php

declare(strict_types=1);

namespace Tests\Support\Settings;

use App\Http\Controllers\Admin\AdminSettingsController;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use ReflectionClass;

/**
 * The schema and the request helpers every settings-hub test needs.
 *
 * These were copied into each hub test to begin with and promptly drifted -- one copy of the
 * `root_categories` table lost its timestamps, and the copies seeded different root sets, so a
 * test could pass or fail on which file it lived in. One definition here is what stops that.
 */
trait InteractsWithSettingsHub
{
    /**
     * Create the two tables the hub writes to, seeded to the state the assertions expect.
     *
     * `generate_previews` defaults on for every root, matching the column default; the XXX
     * root arrives with its adaptive budget and clips already enabled, so a test can prove an
     * unchecked box turns something off rather than only that a checked one turns it on.
     */
    protected function createSettingsHubSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::dropIfExists('root_categories');

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->integer('status')->default(1);
            $table->boolean('discard_executables')->default(false);
            $table->boolean('generate_previews')->default(true);
            $table->boolean('dynamic_preview_budget')->default(false);
            $table->boolean('generate_clips')->default(false);
            $table->timestamps();
        });

        DB::table('root_categories')->insert([
            ['id' => 1, 'title' => 'Other', 'discard_executables' => 0, 'dynamic_preview_budget' => 0, 'generate_clips' => 0],
            ['id' => 1000, 'title' => 'Console', 'discard_executables' => 0, 'dynamic_preview_budget' => 0, 'generate_clips' => 0],
            ['id' => 2000, 'title' => 'Movies', 'discard_executables' => 1, 'dynamic_preview_budget' => 0, 'generate_clips' => 0],
            ['id' => 4000, 'title' => 'PC', 'discard_executables' => 0, 'dynamic_preview_budget' => 0, 'generate_clips' => 0],
            ['id' => 5000, 'title' => 'TV', 'discard_executables' => 1, 'dynamic_preview_budget' => 0, 'generate_clips' => 0],
            ['id' => 6000, 'title' => 'XXX', 'discard_executables' => 1, 'dynamic_preview_budget' => 1, 'generate_clips' => 1],
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function showSection(string $section, array $query = []): View
    {
        $request = Request::create('/admin/settings/'.$section, 'GET', $query);

        $view = app(AdminSettingsController::class)->show($request, $section);

        $this->assertInstanceOf(View::class, $view);

        return $view;
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function renderSection(string $section, array $query = []): string
    {
        return $this->showSection($section, $query)->render();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function saveCard(string $section, string $card, array $payload): RedirectResponse
    {
        $request = Request::create('/admin/settings/'.$section.'/'.$card, 'POST', $payload);

        return app(AdminSettingsController::class)->update($request, $section, $card);
    }

    protected function storedSettingValue(string $name): ?string
    {
        $value = DB::table('settings')->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * The composer memoizes into a static, which survives between tests in one process.
     */
    protected function resetGlobalComposerState(): void
    {
        (new ReflectionClass(GlobalDataComposer::class))->getProperty('resolvedData')->setValue(null, null);
    }
}
