<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AdultBrowseNavigationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->withoutVite();
        Cache::flush();
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);

        Schema::create('content', function (Blueprint $table): void {
            $table->id();
            $table->integer('status');
            $table->integer('contenttype');
            $table->integer('ordinal');
        });
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_adult_browse_does_not_offer_a_cover_view_that_is_a_table(): void
    {
        $this->blade('<x-view-toggle current-view="list" covgroup="xxx" category="All" />')
            ->assertDontSee('Covers')
            ->assertSee('List');

        $this->blade('<x-view-toggle current-view="list" covgroup="movies" category="All" />')
            ->assertSee('Covers')
            ->assertSee('href="'.url('/Movies').'"', false);
    }
}
