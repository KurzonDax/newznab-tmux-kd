<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class DetailsDocumentViewTest extends TestCase
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

        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('details-covers')]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(GlobalDataComposer::class, 'resolvedData'))->setValue(null, null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_details_renders_a_complete_document_with_one_working_image_modal(): void
    {
        $release = Release::factory()->make([
            'id' => 1,
            'guid' => 'details-document',
            'searchname' => 'Example.Release',
            'size' => 1073741824,
            'adddate' => now(),
            'postdate' => now(),
        ]);
        $release->setRelation('audioTags', null);

        $html = view('details.index', ['release' => $release])->render();

        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        $this->assertSame(1, substr_count($html, 'x-data="imageModal"'));
        $this->assertStringNotContainsString('id="imageModal"', $html);
        $this->assertStringContainsString('Download NZB', $html);
    }
}
