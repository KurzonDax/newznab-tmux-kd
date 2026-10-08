<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\CoverArt;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Requests the 500 px front thumbnail of a MusicBrainz release or release group from the Cover
 * Art Archive (`{base}/{kind}/{mbid}/front-500`), following its redirects to the image, and reads
 * the final status: 404 is no front image; any other non-2xx answer or a transport error failed.
 */
final class CoverArtArchiveClient
{
    public function front(CoverArtKind $kind, string $musicBrainzId): CoverArtFront
    {
        $url = rtrim((string) config('music-identity.cover_art.base_url', 'https://coverartarchive.org'), '/')
            .'/'.$kind->value.'/'.rawurlencode($musicBrainzId).'/front-500';

        try {
            $response = Http::accept('image/*')
                ->withUserAgent($this->userAgent())
                ->connectTimeout(max(0.1, (float) config('music-identity.cover_art.connect_timeout_seconds', 5)))
                ->timeout(max(0.1, (float) config('music-identity.cover_art.timeout_seconds', 15)))
                ->get($url);
        } catch (Throwable $exception) {
            return CoverArtFront::failed(Str::limit($exception->getMessage(), 200));
        }

        if ($response->status() === 404) {
            return CoverArtFront::none();
        }
        if (! $response->successful()) {
            return CoverArtFront::failed('HTTP '.$response->status());
        }
        $bytes = $response->body();

        return $bytes === '' ? CoverArtFront::failed('Empty image response') : CoverArtFront::image($bytes);
    }

    /** The MusicBrainz gateway's user agent: the configured contact, when there is one. */
    private function userAgent(): string
    {
        $contact = trim((string) config('music-identity.musicbrainz.user_agent_contact'));

        return $contact === '' ? 'NNTmux/1.0' : 'NNTmux/1.0 ('.$contact.')';
    }
}
