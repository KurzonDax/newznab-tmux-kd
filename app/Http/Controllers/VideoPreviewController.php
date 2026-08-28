<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Models\ReleaseVideoClip;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Streams the release's video artifact: the full-resolution stream-copy Clip
 * when one was stored, or the legacy downscaled OGV transcode otherwise.
 *
 * Follows {@see AudioPreviewController}: {@see CoverController} only ever
 * serves images and does not honour range requests, which a <video> element
 * needs in order to seek. The extension is validated against the
 * {@see ReleaseVideoClip::VIDEO_MIME_TYPES} allow-list, so the path can never
 * be a lookup for arbitrary files.
 */
class VideoPreviewController extends Controller
{
    public function show(Request $request, string $guid): BinaryFileResponse
    {
        if (! $this->isValidGuid($guid)) {
            abort(404);
        }

        $release = Release::query()
            ->where('guid', '=', $guid)
            ->first(['id', 'videostatus']);

        if (! $release instanceof Release || (int) $release->videostatus !== 1) {
            abort(404);
        }

        $clip = ReleaseVideoClip::query()
            ->where('releases_id', '=', (int) $release->id)
            ->first();

        // A Clip row names the container it was remuxed into; without one the
        // artifact is the legacy transcode's fixed OGV container.
        $extension = $clip instanceof ReleaseVideoClip ? $clip->clipExtension() : 'ogv';
        $mimeType = $clip instanceof ReleaseVideoClip ? $clip->clipMimeType() : ReleaseVideoClip::VIDEO_MIME_TYPES['ogv'];
        if ($extension === null || $mimeType === null) {
            abort(404);
        }

        $path = $this->resolveArtifactPath($guid, $extension);
        if ($path === null) {
            abort(404);
        }

        // public: false, or the constructor overwrites the private cache directive
        // below -- the artifact is gated behind auth and must not enter a shared cache.
        // no-cache, not a freshness lifetime: the artifact is regenerable in
        // place behind this permanent URL, so every reuse must revalidate
        // against the Last-Modified the constructor derives from the file.
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-cache, private',
        ], public: false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $guid.'.'.$extension);

        // Answers the revalidation no-cache demands: an unchanged artifact
        // costs a 304, a regenerated one streams its new bytes.
        $response->isNotModified($request);

        // Sets Accept-Ranges and turns a Range request into a 206 with the right
        // Content-Range; Laravel prepares the response again on the way out,
        // which recomputes the same values rather than compounding them.
        return $response->prepare($request);
    }

    /**
     * The guid is the only user input that reaches the path, so it is held to the
     * same character set {@see CoverController::isValidFilename()} allows.
     */
    private function isValidGuid(string $guid): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $guid) === 1;
    }

    /**
     * Search the same covers roots {@see CoverController} does, so the video
     * artifact and the preview image beside it can never resolve from
     * different trees on an install where COVERS_PATH points away from
     * storage/covers.
     */
    private function resolveArtifactPath(string $guid, string $extension): ?string
    {
        $configured = config('nntmux_settings.covers_path');
        $roots = array_unique(array_filter([
            is_string($configured) && $configured !== '' ? rtrim($configured, '/\\') : null,
            storage_path('covers'),
            public_path('covers'),
        ]));

        foreach ($roots as $root) {
            $candidate = $root.DIRECTORY_SEPARATOR.'video'.DIRECTORY_SEPARATOR.$guid.'.'.$extension;
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
