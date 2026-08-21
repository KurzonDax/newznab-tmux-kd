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
    /**
     * Extensions the audio pipeline is allowed to write, and the MIME type used
     * when the stored one is unusable.
     *
     * @var array<string, string>
     */
    private const array PREVIEW_MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
    ];

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

        $extension = strtolower((string) $tag->preview_extension);
        if (! array_key_exists($extension, self::PREVIEW_MIME_TYPES)) {
            abort(404);
        }

        $path = $this->coversRoot().DIRECTORY_SEPARATOR.'audiosample'.DIRECTORY_SEPARATOR.$guid.'.'.$extension;
        if (! is_file($path) || ! is_readable($path)) {
            abort(404);
        }

        // public: false, or the constructor overwrites the private cache directive
        // below -- the clip is gated behind auth and must not enter a shared cache.
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => $this->mimeTypeFor($tag, $extension),
            'Cache-Control' => 'private, max-age=86400',
        ], public: false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $guid.'.'.$extension);

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
     * Honour the MIME recorded when the clip was encoded, but never emit a value
     * that is not a well-formed media type.
     */
    private function mimeTypeFor(ReleaseAudioTag $tag, string $extension): string
    {
        $stored = (string) $tag->preview_mime;

        if (preg_match('#\A[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*\z#iD', $stored) === 1) {
            return $stored;
        }

        return self::PREVIEW_MIME_TYPES[$extension];
    }

    private function coversRoot(): string
    {
        $configured = config('nntmux_settings.covers_path');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/\\')
            : storage_path('covers');
    }
}
