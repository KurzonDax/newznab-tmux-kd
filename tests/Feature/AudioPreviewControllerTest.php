<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AudioPreviewControllerTest extends TestCase
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

        $migration = require database_path('migrations/2026_08_21_090000_create_release_audio_tags_table.php');
        $migration->up();

        $this->coversRoot = $this->makeTempDirectory('nntmux-covers');
        mkdir($this->coversRoot.'/audiosample', 0775, true);
        config(['nntmux_settings.covers_path' => $this->coversRoot]);
    }

    public function test_it_serves_the_preview_with_the_recorded_mime_type(): void
    {
        $this->seedPreview('abc123', 'mp3', 'audio/mpeg', str_repeat('a', 512));

        $response = $this->actingAs($this->verifiedUser())->get(route('preview.audio', 'abc123'));

        $response->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        // The preview is regenerable in place behind this permanent URL, so
        // every reuse must revalidate; private because it is auth-gated.
        // Symfony re-serialises cache directives alphabetically.
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('Last-Modified'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_an_unchanged_preview_answers_a_conditional_get_with_304(): void
    {
        $this->seedPreview('abc123', 'mp3', 'audio/mpeg', str_repeat('a', 512));
        $mtime = time() - 3600;
        touch($this->coversRoot.'/audiosample/abc123.mp3', $mtime);

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.audio', 'abc123'), [
                'If-Modified-Since' => gmdate('D, d M Y H:i:s \G\M\T', $mtime),
            ])
            ->assertStatus(304);
    }

    public function test_a_regenerated_preview_answers_a_stale_conditional_get_with_the_new_bytes(): void
    {
        $this->seedPreview('abc123', 'mp3', 'audio/mpeg', str_repeat('n', 64));
        $mtime = time() - 3600;
        touch($this->coversRoot.'/audiosample/abc123.mp3', $mtime);

        $response = $this->actingAs($this->verifiedUser())
            ->get(route('preview.audio', 'abc123'), [
                'If-Modified-Since' => gmdate('D, d M Y H:i:s \G\M\T', $mtime - 86400),
            ]);

        $response->assertOk();
        $this->assertSame(str_repeat('n', 64), $response->streamedContent());
    }

    public function test_it_answers_a_range_request_with_a_partial_response(): void
    {
        $this->seedPreview('abc123', 'flac', 'audio/flac', str_repeat('b', 512));

        $response = $this->actingAs($this->verifiedUser())
            ->get(route('preview.audio', 'abc123'), ['Range' => 'bytes=0-99']);

        $response->assertStatus(206);
        $this->assertSame('bytes 0-99/512', $response->headers->get('Content-Range'));
        $this->assertSame('100', $response->headers->get('Content-Length'));
        $this->assertSame(100, strlen($response->streamedContent()));
    }

    public function test_it_404s_when_the_release_has_no_audio_tag_row(): void
    {
        DB::table('releases')->insert(['guid' => 'abc123', 'searchname' => 'Some Album']);

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.audio', 'abc123'))
            ->assertNotFound();
    }

    public function test_it_404s_when_the_row_has_no_preview(): void
    {
        $this->seedPreview('abc123', 'mp3', 'audio/mpeg', 'data', hasPreview: false);

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.audio', 'abc123'))
            ->assertNotFound();
    }

    public function test_it_404s_when_the_file_is_missing_from_disk(): void
    {
        $this->seedPreview('abc123', 'mp3', 'audio/mpeg', null);

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.audio', 'abc123'))
            ->assertNotFound();
    }

    public function test_it_404s_for_an_unknown_preview_extension(): void
    {
        $this->seedPreview('abc123', 'exe', 'application/octet-stream', 'MZ');

        $this->actingAs($this->verifiedUser())
            ->get(route('preview.audio', 'abc123'))
            ->assertNotFound();
    }

    public function test_it_never_leaves_the_audiosample_directory(): void
    {
        $escape = $this->coversRoot.'/secret.mp3';
        file_put_contents($escape, 'secret');
        $this->seedPreview('../secret', 'mp3', 'audio/mpeg', null);

        $this->actingAs($this->verifiedUser())
            ->get('/preview/audio/'.rawurlencode('../secret'))
            ->assertNotFound();

        $this->assertSame('secret', file_get_contents($escape));
    }

    public function test_it_requires_authentication(): void
    {
        $this->seedPreview('abc123', 'mp3', 'audio/mpeg', 'data');

        $this->get(route('preview.audio', 'abc123'))->assertRedirect(route('login'));
    }

    private function seedPreview(
        string $guid,
        string $extension,
        string $mime,
        ?string $contents,
        bool $hasPreview = true,
        bool $hasSpectrogram = false,
    ): void {
        $releaseId = (int) DB::table('releases')->insertGetId([
            'guid' => $guid,
            'searchname' => 'Some Album',
        ]);

        DB::table('release_audio_tags')->insert([
            'releases_id' => $releaseId,
            'audio_format' => 'MPEG Audio',
            'has_preview' => $hasPreview ? 1 : 0,
            'preview_extension' => $extension,
            'preview_mime' => $mime,
            'preview_seconds' => 30,
            'preview_bytes' => $contents === null ? null : strlen($contents),
            'has_spectrogram' => $hasSpectrogram ? 1 : 0,
        ]);

        if ($contents !== null) {
            file_put_contents($this->coversRoot.'/audiosample/'.basename($guid).'.'.$extension, $contents);
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
