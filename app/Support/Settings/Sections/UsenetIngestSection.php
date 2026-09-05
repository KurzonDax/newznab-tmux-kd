<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Support\BackfillSettingRules;
use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * Everything that pulls headers off Usenet.
 *
 * The cards follow the engine's own layout: each pane's switch, threads and sleep timer sit
 * beside the settings that pane reads, so tuning one pane no longer means hunting across two
 * pages for the number that governs it.
 */
final class UsenetIngestSection implements SettingsSectionProvider
{
    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'usenet-ingest',
            title: 'Usenet Ingest',
            description: 'Header collection, new-group reach, missed-article repair, and backfill.',
            icon: 'fas fa-cloud-arrow-down',
            stage: PipelineStage::Ingest,
            cards: [
                new SettingCard(
                    id: 'connection',
                    title: 'News server connection',
                    description: 'How hard a failed connection is retried before the pass gives up. Provider hosts, ports and credentials live in the environment file, not here.',
                    icon: 'fas fa-plug',
                    settings: [
                        new SettingDefinition(
                            key: 'nntpretries',
                            label: 'Connection attempts',
                            help: 'Attempts made against a provider, counting the first. Failures back off from 250&nbsp;ms, doubling to 5&nbsp;seconds; an authentication rejection returns at once without retrying. <strong>0 or blank falls back to 1 attempt.</strong> Default 10.',
                            type: SettingType::Int,
                            unit: 'attempts',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-rotate-right',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'headers',
                    title: 'Header download',
                    description: 'Window 0, Binaries pane. This is the front of the pipeline: it walks each active group forward from its last record to the provider\'s newest article.',
                    icon: 'fas fa-download',
                    settings: [
                        new SettingDefinition(
                            key: 'binaries',
                            label: 'Header collection',
                            help: 'Whether the Binaries pane runs at all. Turning it off leaves existing collections alone; nothing new arrives.',
                            type: SettingType::Enum,
                            options: [1 => 'Enabled', 0 => 'Disabled'],
                            icon: 'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'binarythreads',
                            label: 'Header threads',
                            help: 'Groups scanned at once, each holding its own NNTP connection. Parts landing in missed_parts in bulk usually means the provider is not keeping up: lower this before anything else.',
                            type: SettingType::Int,
                            unit: 'threads',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'bins_timer',
                            label: 'Pane sleep',
                            help: 'How long the pane waits after a pass before starting the next one.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                        new SettingDefinition(
                            key: 'maxmssgs',
                            label: 'Headers per request',
                            help: 'How many headers one request asks the provider for. <strong>0 or blank falls back to 20000.</strong>',
                            type: SettingType::Int,
                            unit: 'headers',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-envelope',
                        ),
                        new SettingDefinition(
                            key: 'max_headers_iteration',
                            label: 'Headers per group per pass',
                            help: 'The widest article range one pass will try to close for a single group. A group further behind than this catches up over several passes rather than one enormous one.',
                            type: SettingType::Int,
                            unit: 'headers',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'new-groups',
                    title: 'New groups',
                    description: 'Where a group starts the first time it is activated. Backfill can reach further back afterwards.',
                    icon: 'fas fa-folder-plus',
                    settings: [
                        new SettingDefinition(
                            key: 'newgroupscanmethod',
                            label: 'Starting point',
                            help: 'Whether a new group starts a number of days back or a number of posts back. The two fields below are read according to this choice; the other one is ignored.',
                            type: SettingType::Enum,
                            options: [1 => 'Days back', 0 => 'Post count'],
                            icon: 'fas fa-flag-checkered',
                        ),
                        new SettingDefinition(
                            key: 'newgroupdaystoscan',
                            label: 'Days back',
                            help: 'Used when the starting point is Days back.',
                            type: SettingType::Int,
                            unit: 'days',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-calendar-days',
                        ),
                        new SettingDefinition(
                            key: 'newgroupmsgstoscan',
                            label: 'Post count',
                            help: 'Used when the starting point is Post count.',
                            type: SettingType::Int,
                            unit: 'posts',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-hashtag',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'part-repair',
                    title: 'Missed-article repair',
                    description: 'Articles a scan asked for and did not get are recorded and retried later. This is not the same as release repair, which rebuilds a finished release; this repairs the header scan itself.',
                    icon: 'fas fa-wrench',
                    settings: [
                        SettingDefinition::bool(
                            'partrepair',
                            'Retry missed articles',
                            'Whether the header pass comes back for articles it missed. It costs time on every pass, and buys completeness on providers that propagate unevenly.',
                            'fas fa-toolbox',
                        ),
                        SettingDefinition::bool(
                            'safepartrepair',
                            'Record misses during backfill',
                            'Whether backfill and the safe binaries scripts also record their missed articles for retry. Backfill reaches into old, thin retention where misses are common and often permanent, so this can grow missed_parts quickly.',
                            'fas fa-shield-halved',
                        ),
                        new SettingDefinition(
                            key: 'maxpartrepair',
                            label: 'Articles retried per pass',
                            help: 'Ceiling on missed articles one repair pass asks for. A steadily growing missed_parts table means the provider is dropping requests, not that this number is too small.',
                            type: SettingType::Int,
                            unit: 'articles',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-tools',
                        ),
                        new SettingDefinition(
                            key: 'partrepairmaxtries',
                            label: 'Attempts per article',
                            help: 'How many times one missed article is retried before it is given up on. <strong>0 or blank falls back to 1 attempt.</strong>',
                            type: SettingType::Int,
                            unit: 'attempts',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-repeat',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'backfill',
                    title: 'Backfill',
                    description: 'Window 0, Backfill pane. Where header collection walks forward from each group\'s newest record, backfill walks backwards from its oldest.',
                    icon: 'fas fa-backward',
                    settings: [
                        new SettingDefinition(
                            key: 'backfill',
                            label: 'Backfill',
                            help: 'Whether the Backfill pane runs at all.',
                            type: SettingType::Enum,
                            options: [1 => 'Enabled', 0 => 'Disabled'],
                            icon: 'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'backfill_order',
                            label: 'Group order',
                            help: 'Which group the pass picks up first. Most Posts drains the busiest groups soonest; Oldest gets the thinnest retention while it still exists.',
                            type: SettingType::Enum,
                            options: [
                                1 => 'Newest first',
                                2 => 'Oldest first',
                                3 => 'Alphabetical',
                                4 => 'Alphabetical, reversed',
                                5 => 'Most posts first',
                                6 => 'Fewest posts first',
                            ],
                            icon: 'fas fa-arrow-down-a-z',
                        ),
                        new SettingDefinition(
                            key: 'backfill_days',
                            label: 'Stop rule',
                            help: 'Days per group stops each group at its own configured backfill target. Shared stop date ignores those targets and takes every group back to the one date below.',
                            type: SettingType::Enum,
                            options: [
                                1 => 'Days per group',
                                2 => 'Shared stop date',
                            ],
                            icon: 'fas fa-flag',
                        ),
                        new SettingDefinition(
                            key: 'safebackfilldate',
                            label: 'Shared stop date',
                            help: 'The date every group stops at when the stop rule is Shared stop date. Stored as YYYY-MM-DD and parsed strictly: a value that will not parse fails the pass closed rather than being reinterpreted, because the coded fallback is the earliest date there is and would schedule maximal backfill.',
                            type: SettingType::Date,
                            rules: BackfillSettingRules::rules()['safebackfilldate'],
                            icon: 'fas fa-calendar-check',
                        ),
                        SettingDefinition::bool(
                            'disablebackfillgroup',
                            'Deactivate a finished group',
                            'When a group reaches its stop point, turn its backfill flag off so later passes skip it instead of re-checking it every time.',
                            'fas fa-toggle-off',
                        ),
                        new SettingDefinition(
                            key: 'backfill_qty',
                            label: 'Headers per group per pass',
                            help: 'How far back one pass takes a single group before moving to the next.',
                            type: SettingType::Int,
                            unit: 'headers',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-gauge',
                        ),
                        new SettingDefinition(
                            key: 'backfillthreads',
                            label: 'Backfill threads',
                            help: 'Groups backfilled at once, each holding its own NNTP connection. These compete with header collection for the same provider allowance.',
                            type: SettingType::Int,
                            unit: 'threads',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'back_timer',
                            label: 'Pane sleep',
                            help: 'How long the pane waits after a pass before starting the next one.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                        SettingDefinition::bool(
                            'progressive',
                            'Back off when the backlog grows',
                            'Stretch the pane sleep as the collections table fills up, so backfill yields to release formation instead of burying it.',
                            'fas fa-wave-square',
                        ),
                    ],
                ),
            ],
        );
    }
}
