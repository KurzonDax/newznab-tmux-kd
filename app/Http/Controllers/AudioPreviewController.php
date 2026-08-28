<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReleaseAudioTag;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Streams the audio preview clip generated for a release during post-processing.
 *
 * Separate from {@see CoverController} because that controller only ever serves
 * images: it rejects non-image MIME types and does not honour range requests,
 * which an <audio> element needs in order to seek.
 */
class AudioPreviewController extends Controller
{
    public function show(Request $request, string $guid): BinaryFileResponse
    {
        if (! $this->isValidGuid($guid)) {
            abort(404);
        }

        $tag = ReleaseAudioTag::query()
            ->join('releases', 'releases.id', '=', 'release_audio_tags.releases_id')
            ->where('releases.guid', '=', $guid)
            ->where('release_audio_tags.has_preview', '=', 1)
            ->select('release_audio_tags.*')
            ->first();

        if (! $tag instanceof ReleaseAudioTag) {
            abort(404);
        }

        $extension = $tag->previewExtension();
        $mimeType = $tag->previewMimeType();
        if ($extension === null || $mimeType === null) {
            abort(404);
        }

        $path = $this->resolveClipPath($guid, $extension);
        if ($path === null) {
            abort(404);
        }

        // public: false, or the constructor overwrites the private cache directive
        // below -- the clip is gated behind auth and must not enter a shared cache.
        // no-cache, not a freshness lifetime: the clip is regenerable in place
        // behind this permanent URL, so every reuse must revalidate against
        // the Last-Modified the constructor derives from the file.
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-cache, private',
        ], public: false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $guid.'.'.$extension);

        // Answers the revalidation no-cache demands: an unchanged clip costs
        // a 304, a regenerated one streams its new bytes.
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
     * Search the same covers roots {@see CoverController} does, so a clip and the
     * spectrogram beside it can never resolve from different trees on an install
     * where COVERS_PATH points away from storage/covers.
     */
    private function resolveClipPath(string $guid, string $extension): ?string
    {
        $configured = config('nntmux_settings.covers_path');
        $roots = array_unique(array_filter([
            is_string($configured) && $configured !== '' ? rtrim($configured, '/\\') : null,
            storage_path('covers'),
            public_path('covers'),
        ]));

        foreach ($roots as $root) {
            $candidate = $root.DIRECTORY_SEPARATOR.'audiosample'.DIRECTORY_SEPARATOR.$guid.'.'.$extension;
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
