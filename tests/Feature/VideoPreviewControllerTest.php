<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VideoPreviewControllerTest extends TestCase
{
    private string $coversRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->string('searchname')->default('');
            $table->integer('videostatus')->default(0);
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username')->default('');
            $table->string('email')->default('');
            $table->string('password')->default('');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_08_27_150100_create_release_video_clips_table.php');
        $migration->up();

        $this->coversRoot = $this->makeTempDirectory('nntmux-covers');
        mkdir($this->coversRoot.'/video', 0775, true);
        config(['nntmux_settings.covers_path' => $this->coversRoot]);
    }

    public function test_it_serves_a_clip_with_the_recorded_mime_type(): void
    {
        $this->seedVideo('abc123', 'mp4', 'video/mp4', str_repeat('v', 512));

        $response = $this->actingAs($this->verifiedUser())->get(route('preview.video', 'abc123'));

        $response->assertOk();
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        // Symfony re-serialises cache directives alphabetically.
        $this->assertSame('max-age=86400, private', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_it_serves_a_webm_clip(): void
    {
        $this->seedVideo('abc123', 'webm', 'video/webm', str_repeat('w', 128));

        $response = $this->actingAs($this->verifiedUser())->get(route('preview.video', 'abc123'));

        $response->assertOk();
        $this->assertSame('video/webm', $response->headers->get('Content-Type'));
    }

    public function test_it_serves_a_legacy_ogv_sample_without_a_clip_row(): void
    {
        $releaseId = (int) DB::table('releases')->insertGetId([
            'guid' => 'abc123',
            'searchname' => 'Some Movie',
            'videostatus' => 1,
        ]);
        $this->assertGreaterThan(0, $releaseId);
        file_put_contents($this->coversRoot.'/video/abc123.ogv', str_repeat('o', 256));

        $response = $this->actingAs($this->verifiedUser())->get(route('preview.video', 'abc123'));

        $response->assertOk();
        $this->assertSame('video/ogg', $response->headers->get('Content-Type'));
    }

    public function test_it_answers_a_range_request_with_a_partial_response(): void
    {
        $this->seedVideo('abc123', 'mp4', 'video/mp4', str_repeat('v', 512));

        $response = $this->actingAs($this->verifiedUser())
            ->get(route('preview.video', 'abc123'), ['Range' => 'bytes=0-99']);

        $response->assertStatus(206);
        $this->assertSame('bytes 0-99/512', $response->headers->get('Content-Range'));
        $this->assertSame('100', $response->headers->get('Content-Length'));
        $this->assertSame(100, strlen($response->streamedContent()));
    }

    public function test_it_404s_when_the_release_has_no_video_artifact(): void
    {
        DB::table('releases')->insert(['guid' => 'abc123', 'searchname' => 'Some Movie', 'videostatus' => 0]);

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.video', 'abc123'))
            ->assertNotFound();
    }

    public function test_it_404s_for_an_extension_outside_the_allow_list(): void
    {
        $this->seedVideo('abc123', 'exe', 'application/octet-stream', 'MZ');

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.video', 'abc123'))
            ->assertNotFound();
    }

    public function test_it_404s_when_the_file_is_missing_from_disk(): void
    {
        $this->seedVideo('abc123', 'mp4', 'video/mp4', null);

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.video', 'abc123'))
            ->assertNotFound();
    }

    public function test_it_never_leaves_the_video_directory(): void
    {
        $escape = $this->coversRoot.'/secret.mp4';
        file_put_contents($escape, 'secret');
        $this->seedVideo('../secret', 'mp4', 'video/mp4', null);

        $this->actingAs($this->verifiedUser())
            ->get('/preview/video/'.rawurlencode('../secret'))
            ->assertNotFound();

        $this->assertSame('secret', file_get_contents($escape));
    }

    public function test_it_requires_authentication(): void
    {
        $this->seedVideo('abc123', 'mp4', 'video/mp4', 'data');

        $this->get(route('preview.video', 'abc123'))->assertRedirect(route('login'));
    }

    private function seedVideo(
        string $guid,
        string $extension,
        string $mime,
        ?string $contents,
    ): void {
        $releaseId = (int) DB::table('releases')->insertGetId([
            'guid' => $guid,
            'searchname' => 'Some Movie',
            'videostatus' => 1,
        ]);

        DB::table('release_video_clips')->insert([
            'releases_id' => $releaseId,
            'extension' => $extension,
            'mime' => $mime,
            'duration_seconds' => 30,
            'bytes' => $contents === null ? null : strlen($contents),
        ]);

        if ($contents !== null) {
            file_put_contents($this->coversRoot.'/video/'.basename($guid).'.'.$extension, $contents);
        }
    }

    private function verifiedUser(): Authenticatable
    {
        $user = new User;
        $user->id = 1;
        $user->username = 'tester';
        $user->email = 'tester@example.test';
        $user->email_verified_at = now();
        $user->exists = true;

        return $user;
    }
}
