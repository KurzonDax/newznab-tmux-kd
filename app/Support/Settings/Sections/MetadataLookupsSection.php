<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * Matching releases against external catalogues.
 *
 * Two engine panes do this work -- one for video, one for the shelf categories -- and each
 * category's toggle sits beside its own per-run cap, because the two are read together and
 * raising one without the other does nothing.
 */
final class MetadataLookupsSection implements SettingsSectionProvider
{
    /**
     * Books, games and the video lookups all share this three-way shape.
     *
     * @return array<int, string>
     */
    private static function lookupModes(string $noun): array
    {
        return [
            0 => 'Disabled',
            1 => 'Look up all '.$noun,
            2 => 'Look up renamed '.$noun.' only',
        ];
    }

    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'metadata-lookups',
            title: 'Metadata Lookups',
            description: 'Which external catalogues are queried, how many releases each pass takes on, and how politely the panes ask.',
            icon: 'fas fa-tags',
            stage: PipelineStage::Enrich,
            cards: [
                new SettingCard(
                    id: 'video-panes',
                    title: 'Video metadata panes',
                    description: 'Window 2, pane 1. Movies, TV and anime lookups run here.',
                    icon: 'fas fa-video',
                    settings: [
                        SettingDefinition::bool(
                            'post_non',
                            'Run the video panes',
                            'The master switch for movie, TV and anime lookups. The per-category toggles below only matter while this is on.',
                            'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'postthreadsnon',
                            label: 'Video lookup threads',
                            help: 'Parallel workers across the movie, TV and anime lookups. These talk to provider APIs rather than Usenet, so the limit is the provider\'s rate limit, not your connection allowance.',
                            type: SettingType::Int,
                            unit: 'workers',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'post_timer_non',
                            label: 'Pane sleep',
                            help: 'How long the pane waits after a cycle before starting the next one.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'shelf-pane',
                    title: 'Books, music & games pane',
                    description: 'Window 2, pane 2. The shelf categories share one pane and one thread count, and it also runs the audio preview fan-out.',
                    icon: 'fas fa-compact-disc',
                    settings: [
                        SettingDefinition::bool(
                            'post_amazon',
                            'Run the shelf pane',
                            'The master switch for book, music, console and game lookups.',
                            'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'postthreadsamazon',
                            label: 'Shelf lookup threads',
                            help: 'Parallel workers across books, music, console and PC games. Provider etiquette below applies per request, so more workers means proportionally more sleeping.',
                            type: SettingType::Int,
                            unit: 'workers',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'post_timer_amazon',
                            label: 'Pane sleep',
                            help: 'How long the pane waits after a cycle before starting the next one.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'movies',
                    title: 'Movies',
                    description: 'TMDB, IMDB and OMDB, tried in turn.',
                    icon: 'fas fa-film',
                    settings: [
                        new SettingDefinition(
                            key: 'lookupimdb',
                            label: 'Movie lookups',
                            help: 'Renamed-only limits the pass to releases whose names were corrected by the naming pane, which is the cheaper choice on a busy index.',
                            type: SettingType::Enum,
                            options: self::lookupModes('movies'),
                            icon: 'fas fa-clapperboard',
                        ),
                        new SettingDefinition(
                            key: 'maximdbprocessed',
                            label: 'Movies per run',
                            help: 'Releases one movie pass works through. No NNTP connection is used.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'tv',
                    title: 'TV',
                    description: 'The TV pipeline: TMDB, then TVDB, TVMaze and Trakt.',
                    icon: 'fas fa-tv',
                    settings: [
                        new SettingDefinition(
                            key: 'lookuptv',
                            label: 'TV lookups',
                            help: 'Renamed-only limits the pass to releases the naming pane corrected.',
                            type: SettingType::Enum,
                            options: self::lookupModes('TV releases'),
                            icon: 'fas fa-tv',
                        ),
                        new SettingDefinition(
                            key: 'maxrageprocessed',
                            label: 'TV releases per run',
                            help: 'Releases one TV pass works through. No NNTP connection is used.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'anime',
                    title: 'Anime',
                    description: 'AniDB and AniList.',
                    icon: 'fas fa-dragon',
                    settings: [
                        SettingDefinition::bool(
                            'lookupanidb',
                            'Anime lookups',
                            'Anime has no renamed-only mode: it is on or off.',
                            'fas fa-dragon',
                        ),
                        new SettingDefinition(
                            key: 'maxanidbprocessed',
                            label: 'Anime per run',
                            help: 'Releases one anime pass works through. No NNTP connection is used.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'music',
                    title: 'Music identity',
                    description: 'Resolving a music release to a MusicBrainz identity from local audio evidence.',
                    icon: 'fas fa-fingerprint',
                    settings: [
                        SettingDefinition::bool(
                            'music_identity_enabled',
                            'Music identity resolution',
                            'Uses immutable local audio evidence to resolve MusicBrainz identities. Nothing is requested from a provider until <code>MUSICBRAINZ_ENDPOINT_URL</code> is set in the environment.',
                            'fas fa-fingerprint',
                        ),
                        new SettingDefinition(
                            key: 'music_identity_workers',
                            label: 'Resolver workers',
                            help: 'Ceiling on resolver workers. Gateway request concurrency and public MusicBrainz etiquette stay bounded independently of this. Default 1.',
                            type: SettingType::Int,
                            unit: 'workers',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-layer-group',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'books',
                    title: 'Books',
                    description: 'ISBNdb, with an iTunes fallback.',
                    icon: 'fas fa-book',
                    settings: [
                        new SettingDefinition(
                            key: 'lookupbooks',
                            label: 'Book lookups',
                            help: 'Renamed-only limits the pass to releases the naming pane corrected.',
                            type: SettingType::Enum,
                            options: self::lookupModes('books'),
                            icon: 'fas fa-book',
                        ),
                        new SettingDefinition(
                            key: 'maxbooksprocessed',
                            label: 'Books per run',
                            help: 'Releases one book pass works through. No NNTP connection is used.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'games',
                    title: 'Games & console',
                    description: 'IGDB, GiantBomb and Steam.',
                    icon: 'fas fa-gamepad',
                    settings: [
                        new SettingDefinition(
                            key: 'lookupgames',
                            label: 'Game lookups',
                            help: 'Covers both console titles and PC games. Renamed-only limits the pass to releases the naming pane corrected.',
                            type: SettingType::Enum,
                            options: self::lookupModes('games'),
                            icon: 'fas fa-gamepad',
                        ),
                        new SettingDefinition(
                            key: 'maxgamesprocessed',
                            label: 'Games per run',
                            help: 'Releases one game pass works through. No NNTP connection is used.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'etiquette',
                    title: 'Service etiquette',
                    description: 'How politely the lookup panes treat the services they depend on.',
                    icon: 'fas fa-handshake',
                    settings: [
                        new SettingDefinition(
                            key: 'amazonsleep',
                            label: 'Pause between requests',
                            help: 'How long a worker waits between external metadata requests. This is per worker, so the effective request rate is this divided by the thread count: with 12 shelf workers, 1000&nbsp;ms still means twelve requests a second.',
                            type: SettingType::Int,
                            unit: 'milliseconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass',
                        ),
                    ],
                ),
            ],
        );
    }
}
