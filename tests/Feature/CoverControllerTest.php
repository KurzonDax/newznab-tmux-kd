<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CoverController;
use GdImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CoverControllerTest extends TestCase
{
    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    protected function tearDown(): void
    {
        File::delete($this->createdFiles);

        parent::tearDown();
    }

    public function test_webp_request_falls_back_to_storage_backed_jpeg(): void
    {
        $name = 'fallback-'.uniqid();
        $this->createImage(storage_path('covers/preview/'.$name.'.jpg'), 'jpg');

        $response = $this->show('preview', $name.'.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_legacy_jpeg_request_falls_back_to_storage_backed_webp(): void
    {
        $name = 'fallback-'.uniqid();
        $this->createImage(storage_path('covers/sample/'.$name.'.webp'), 'webp');

        $response = $this->show('sample', $name.'.jpg');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
    }

    public function test_missing_public_extension_falls_back_without_moving_the_asset(): void
    {
        $name = 'public-'.uniqid();
        $path = public_path('covers/movies/'.$name.'-cover.jpg');
        $this->createImage($path, 'jpg');

        $response = $this->show('movies', $name.'-cover.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist(public_path('covers/movies/'.$name.'-cover.webp'));
    }

    public function test_tvshows_are_accepted_by_the_cover_route(): void
    {
        $id = (string) random_int(8000000, 8999999);
        $this->createImage(storage_path('covers/tvshows/'.$id.'.webp'), 'webp');

        $response = $this->show('tvshows', $id.'.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));

        $route = app('router')->getRoutes()->getByName('covers.show');
        $this->assertNotNull($route);
        $this->assertStringContainsString('tvshows', $route->wheres['type']);
    }

    public function test_traversal_filename_is_rejected(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->show('preview', '../.env.webp');
    }

    public function test_a_bare_sample_request_prefers_the_full_size_copy_over_the_thumb(): void
    {
        $guid = 'guid'.uniqid();
        $this->createImage(storage_path('covers/sample/'.$guid.'.webp'), 'webp', 1200, 600);
        $this->createImage(storage_path('covers/sample/'.$guid.'_thumb.webp'), 'webp', 20, 10);

        $response = $this->show('sample', $guid.'.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            storage_path('covers/sample/'.$guid.'.webp'),
            $response->getFile()->getPathname(),
        );
    }

    public function test_a_bare_preview_request_falls_back_to_the_thumb_for_the_back_catalog(): void
    {
        $guid = 'guid'.uniqid();
        $this->createImage(storage_path('covers/preview/'.$guid.'_thumb.webp'), 'webp');

        $response = $this->show('preview', $guid.'.webp');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            storage_path('covers/preview/'.$guid.'_thumb.webp'),
            $response->getFile()->getPathname(),
        );
    }

    public function test_covers_carry_a_short_revalidating_freshness_window(): void
    {
        $name = 'cache-'.uniqid();
        $this->createImage(storage_path('covers/preview/'.$name.'.webp'), 'webp');

        $response = $this->show('preview', $name.'.webp');

        // Release imagery is regenerable in place behind a permanent URL: the
        // short window keeps thumb-heavy pages cheap, must-revalidate stops
        // reuse of a stale copy past it. Directives serialise alphabetically.
        $this->assertSame('max-age=300, must-revalidate, public', $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('Last-Modified'));
    }

    public function test_an_unchanged_cover_answers_a_conditional_get_with_304(): void
    {
        $name = 'cache-'.uniqid();
        $path = storage_path('covers/preview/'.$name.'.webp');
        $this->createImage($path, 'webp');
        $mtime = time() - 3600;
        touch($path, $mtime);

        $response = $this->show('preview', $name.'.webp', ifModifiedSince: $mtime);

        $this->assertSame(304, $response->getStatusCode());
    }

    public function test_a_regenerated_cover_answers_a_stale_conditional_get_with_a_full_response(): void
    {
        $name = 'cache-'.uniqid();
        $path = storage_path('covers/preview/'.$name.'.webp');
        $this->createImage($path, 'webp');
        $mtime = time() - 3600;
        touch($path, $mtime);

        $response = $this->show('preview', $name.'.webp', ifModifiedSince: $mtime - 86400);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function show(string $type, string $filename, ?int $ifModifiedSince = null): Response|BinaryFileResponse
    {
        $server = $ifModifiedSince === null
            ? []
            : ['HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s \G\M\T', $ifModifiedSince)];

        return (new CoverController)->show(
            Request::create('/covers/'.$type.'/'.$filename, 'GET', server: $server),
            $type,
            $filename,
        );
    }

    private function createImage(string $path, string $format, int $width = 20, int $height = 10): void
    {
        File::ensureDirectoryExists(dirname($path));
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 40, 60));

        if ($format === 'webp') {
            imagewebp($image, $path, 82);
        } else {
            imagejpeg($image, $path, 82);
        }

        $this->createdFiles[] = $path;
    }
}
