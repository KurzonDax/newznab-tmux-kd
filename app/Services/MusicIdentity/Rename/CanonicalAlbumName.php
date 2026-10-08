<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Rename;

use App\Models\ReleaseMusicIdentification;
use Illuminate\Support\Facades\DB;

/**
 * The canonical name of an accepted album decision (issue #309), in the shape the tag rename uses:
 * `{release artist credit} - {release-group title} ({first release year}) {format}`.
 *
 * Everything but the format comes from the MusicBrainz text the decision stored (#308): the release
 * artist credit (Various Artists for a compilation, never a track performer), the release group's
 * canonical title (never an edition title or a search alias) and the year of the group's original
 * release date (never an accepted edition's reissue date). The format is the audio file type observed in the decision's own evidence, never
 * a MusicBrainz medium such as CD. A missing year or format is left out; without a credit or a
 * title there is no name.
 */
final class CanonicalAlbumName
{
    /** Audio file types a format suffix may name, as file extensions. */
    private const array AUDIO_FORMATS = [
        'aac', 'aiff', 'ape', 'dff', 'dsf', 'flac', 'm4a', 'mp3', 'mpc', 'ogg', 'opus', 'tak', 'tta', 'wav', 'wma', 'wv',
    ];

    public function for(ReleaseMusicIdentification $decision): ?string
    {
        $credit = $this->text($decision->accepted_artist_credit);
        $title = $this->text($decision->accepted_title);
        if ($credit === null || $title === null) {
            return null;
        }

        $name = $credit.' - '.$title;
        if (preg_match('/^(\d{4})/', (string) $decision->original_release_date, $year) === 1) {
            $name .= ' ('.$year[1].')';
        }
        $format = $this->observedFormat($decision->release_audio_evidence_id);
        if ($format !== null) {
            $name .= ' '.$format;
        }

        return mb_substr($name, 0, 255); // releases.searchname holds 255 characters
    }

    /**
     * The one audio file type the evidence's tracks show, by file extension or, for a track whose
     * name has none, by its probed container; null when none shows or they disagree.
     */
    private function observedFormat(int $evidenceId): ?string
    {
        $formats = [];
        $tracks = DB::table('release_audio_evidence_tracks')->where('release_audio_evidence_id', $evidenceId)
            ->get(['raw_filename', 'container']);
        foreach ($tracks as $track) {
            $extension = strtolower(pathinfo((string) $track->raw_filename, PATHINFO_EXTENSION));
            $container = strtolower(trim((string) $track->container));
            $format = in_array($extension, self::AUDIO_FORMATS, true) ? $extension
                : (in_array($container, self::AUDIO_FORMATS, true) ? $container : null);
            if ($format !== null) {
                $formats[$format] = true;
            }
        }

        return count($formats) === 1 ? strtoupper((string) array_key_first($formats)) : null;
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
