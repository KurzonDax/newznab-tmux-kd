<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Support\NzbSettingRules;
use App\Support\RepairSettingRules;
use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * Where collected parts become releases, and what happens to the ones that never finish.
 *
 * The gates on this page delete: a collection or release that fails one is removed, not held
 * back for later. That is the distinction the help text keeps drawing, because the
 * post-processing page has gates that look identical and merely skip.
 */
final class ReleaseFormationSection implements SettingsSectionProvider
{
    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'release-formation',
            title: 'Release Formation',
            description: 'Turning collections into releases: the gates a collection has to pass, where NZBs are written, and how long anything is kept.',
            icon: 'fas fa-boxes-packing',
            stage: PipelineStage::FormReleases,
            cards: [
                new SettingCard(
                    id: 'releases-pane',
                    title: 'Releases pane',
                    description: 'Window 0, Releases pane. It runs the whole formation cycle: age out stuck collections, apply the gates, create releases, write NZBs.',
                    icon: 'fas fa-gears',
                    settings: [
                        new SettingDefinition(
                            key: 'releases',
                            label: 'Release formation',
                            help: 'Whether the Releases pane runs. Turn it off only to post-process a backlog without adding to it.',
                            type: SettingType::Enum,
                            options: [1 => 'Enabled', 0 => 'Disabled'],
                            icon: 'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'releasethreads',
                            label: 'Release threads',
                            help: 'Parallel workers in the formation pass. These are database-bound rather than network-bound, so the ceiling here is your database, not the provider.',
                            type: SettingType::Int,
                            unit: 'threads',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'rel_timer',
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
                    id: 'gates',
                    title: 'Formation gates',
                    description: 'What a collection must look like to become a release. <strong>Everything in this card deletes.</strong> A collection or release that fails one of these is removed together with its parts; it is not set aside. Groups and categories carry their own minimums, and the stricter of site and group wins, so raising a value here can delete more than the number alone suggests.',
                    icon: 'fas fa-filter',
                    settings: [
                        new SettingDefinition(
                            key: 'minfilestoformrelease',
                            label: 'Minimum files',
                            help: 'A collection holding fewer files than this is deleted. The group\'s own minimum applies as well, and the larger of the two is the one enforced. 0 turns the site-wide check off and leaves the per-group values in charge.',
                            type: SettingType::Int,
                            unit: 'files',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-file-lines',
                        ),
                        new SettingDefinition(
                            key: 'minsizetoformrelease',
                            label: 'Minimum size',
                            help: 'A collection smaller than this is deleted. As with the file count, the group\'s own minimum applies and the stricter of the two wins; categories add a third minimum of their own, swept separately. 0 turns the site-wide check off.',
                            type: SettingType::Size,
                            icon: 'fas fa-compress',
                        ),
                        new SettingDefinition(
                            key: 'maxsizetoformrelease',
                            label: 'Maximum size',
                            help: 'A collection larger than this is deleted. 0 turns the check off. There is no per-group equivalent.',
                            type: SettingType::Size,
                            icon: 'fas fa-expand',
                        ),
                        new SettingDefinition(
                            key: 'completionpercent',
                            label: 'Minimum completion',
                            help: 'A collection holding a smaller share of its articles than this is deleted. 0 turns the check off. Release repair runs before this sweep, so a release that can be rebuilt is rebuilt first.',
                            type: SettingType::Int,
                            unit: '%',
                            rules: ['required', 'integer', 'min:0', 'max:100'],
                            icon: 'fas fa-percent',
                        ),
                        new SettingDefinition(
                            key: 'delaytime',
                            label: 'Quiet period before forming',
                            help: 'Hours of the group&apos;s posting timeline ingested past the collection&apos;s last part before it may be released with the files it holds. Paused ingestion pauses this wait. Below 2 hours it can form releases that are still arriving.',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-clock',
                        ),
                        new SettingDefinition(
                            key: 'collection_timeout',
                            label: 'Stuck collection timeout',
                            help: 'Hours of the group&apos;s posting timeline ingested past the last part before a stuck collection is deleted with its binaries and parts. Paused ingestion pauses this wait. Default 48.',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-hourglass-end',
                        ),
                        new SettingDefinition(
                            key: 'crossposttime',
                            label: 'Crosspost window',
                            help: 'Two releases with the same name from the same poster inside this window are treated as one posting and one of them is deleted. 0 turns the check off.',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-clone',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'nzb-storage',
                    title: 'NZB storage',
                    description: 'How many NZB files a cycle writes, and where they land on disk.',
                    icon: 'fas fa-folder-tree',
                    settings: [
                        new SettingDefinition(
                            key: 'maxnzbsprocessed',
                            label: 'NZBs written per cycle',
                            help: 'How many NZB files one formation cycle writes before looping. Anything left over is written on the next loop, so this bounds a burst rather than the total. <strong>0 or blank falls back to 1000.</strong>',
                            type: SettingType::Int,
                            unit: 'files',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-file-code',
                        ),
                        new SettingDefinition(
                            key: 'nzbsplitlevel',
                            label: 'Storage depth',
                            help: 'How many sub-directories deep, named after the leading characters of the release GUID, new NZB files are written. <strong>0 stores them flat</strong>; blank falls back to 4. Changing this on a live install is safe: lookups fall back through the other depths, so existing files stay reachable without being moved.',
                            type: SettingType::Int,
                            unit: 'levels',
                            rules: NzbSettingRules::rules()['nzbsplitlevel'],
                            icon: 'fas fa-sitemap',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'retention',
                    title: 'Retention & cleanup',
                    description: 'How long unfinished work and finished releases are kept. Every value here is a delete, and <strong>0 never means "delete at once"</strong> &mdash; but it does not mean one thing either: on the three release-retention windows it means keep indefinitely, while the incomplete-parts window falls back to its seeded 72 hours. Each field says which.',
                    icon: 'fas fa-broom',
                    settings: [
                        new SettingDefinition(
                            key: 'partretentionhours',
                            label: 'Incomplete parts retention',
                            help: 'How long leftover collections, binaries and parts are kept after leaving formation. Collections still forming are excluded; their stuck timeout applies. <strong>0 or blank falls back to 72 hours.</strong>',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-clock',
                        ),
                        new SettingDefinition(
                            key: 'releaseretentiondays',
                            label: 'Release retention',
                            help: 'How long a release stays in the index before it is deleted along with its NZB and images. 0 keeps releases indefinitely, which is the seeded default.',
                            type: SettingType::Int,
                            unit: 'days',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-calendar-days',
                        ),
                        new SettingDefinition(
                            key: 'miscotherretentionhours',
                            label: 'Other → Misc retention',
                            help: 'How long releases that ended up in Other &rarr; Misc are kept. These are the ones categorization could make nothing of. 0 keeps them indefinitely.',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass',
                        ),
                        new SettingDefinition(
                            key: 'mischashedretentionhours',
                            label: 'Other → Hashed retention',
                            help: 'How long releases whose names are hashes are kept. Name fixing may still rescue one, so a short window here throws away work the Naming pane could have done. 0 keeps them indefinitely.',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hashtag',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'repair',
                    title: 'Release repair & re-scan',
                    description: 'How hard the recovery passes work to rescue an incomplete release before the completion gate above may delete it. Repair rebuilds segments whose message-IDs can be derived from the ones already held; the re-scan goes back to the group\'s headers for files that were missed entirely.',
                    icon: 'fas fa-screwdriver-wrench',
                    settings: [
                        new SettingDefinition(
                            key: 'repair_retry_after_hours',
                            label: 'Repair retry window',
                            help: 'How long after a repair pass falls short the final pass may run. Fresh releases are promoted at the collection timeout and repaired within hours, while their articles may still be propagating across the provider farm, so a first attempt can fail where a recheck days later succeeds. Every release gets two passes at most.',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: RepairSettingRules::rulesFor('repair_retry_after_hours'),
                            icon: 'fas fa-rotate-right',
                        ),
                        new SettingDefinition(
                            key: 'repair_floor_completion',
                            label: 'Repair floor completion',
                            help: 'Releases measured below this percentage skip network repair entirely and go straight to a final outcome. A release holding under a tenth of its articles is not a header-scan miss, and confirming that costs article probes.',
                            type: SettingType::Int,
                            unit: '%',
                            rules: RepairSettingRules::rulesFor('repair_floor_completion'),
                            icon: 'fas fa-arrow-down-short-wide',
                        ),
                        new SettingDefinition(
                            key: 'repair_stat_sample_per_file',
                            label: 'Repair samples per file',
                            help: 'Synthesized message-IDs spot-checked per file before its segments are written into the NZB. A file is accepted only when every sampled ID exists. One sample cannot say whether the message-ID template was guessed correctly, so two is the sensible minimum.',
                            type: SettingType::Int,
                            unit: 'probes',
                            rules: RepairSettingRules::rulesFor('repair_stat_sample_per_file'),
                            icon: 'fas fa-vial',
                        ),
                        new SettingDefinition(
                            key: 'repair_max_stat_probes',
                            label: 'Repair probe ceiling per release',
                            help: 'Hard ceiling on article existence probes for one release, however many files it has. When the budget runs out mid-way the remaining files are left for the next pass rather than accepted on a thinner sample.',
                            type: SettingType::Int,
                            unit: 'probes',
                            rules: RepairSettingRules::rulesFor('repair_max_stat_probes'),
                            icon: 'fas fa-gauge-high',
                        ),
                        new SettingDefinition(
                            key: 'repair_limit',
                            label: 'Repair releases per run',
                            help: 'Releases one <code>releases:repair-completion</code> invocation works on. Repaired releases flow straight back into additional processing, so a large batch here starves fresh releases of post-processing capacity.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: RepairSettingRules::rulesFor('repair_limit'),
                            icon: 'fas fa-layer-group',
                        ),
                        new SettingDefinition(
                            key: 'rescan_limit',
                            label: 'Re-scan releases per run',
                            help: 'Releases one <code>releases:rescan-missing-files</code> invocation works on. The header re-scan recovers files the scan missed entirely, and it competes with live header scanning for the primary provider\'s connections.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: RepairSettingRules::rulesFor('rescan_limit'),
                            icon: 'fas fa-magnifying-glass-arrow-right',
                        ),
                        new SettingDefinition(
                            key: 'rescan_window_minutes',
                            label: 'Re-scan window',
                            help: 'How far either side of the release\'s known article range the re-scan looks, in posting time. Widening it finds files posted further from the rest of the collection, at a proportional cost in overview lines fetched.',
                            type: SettingType::Int,
                            unit: 'minutes',
                            rules: RepairSettingRules::rulesFor('rescan_window_minutes'),
                            icon: 'fas fa-clock-rotate-left',
                        ),
                        new SettingDefinition(
                            key: 'rescan_max_articles_per_release',
                            label: 'Re-scan article ceiling per release',
                            help: 'A release whose estimated article range is wider than this is stamped as skipped without fetching anything. Low-traffic groups give tight ranges; a busy group over a wide window can span millions of articles for one release.',
                            type: SettingType::Int,
                            unit: 'articles',
                            rules: RepairSettingRules::rulesFor('rescan_max_articles_per_release'),
                            icon: 'fas fa-ruler-horizontal',
                        ),
                        new SettingDefinition(
                            key: 'rescan_max_articles_per_run',
                            label: 'Re-scan article ceiling per run',
                            help: 'The invocation stops fetching once this many overview lines have been read, whatever is left in the batch. The unfinished releases keep their state and are picked up next run.',
                            type: SettingType::Int,
                            unit: 'articles',
                            rules: RepairSettingRules::rulesFor('rescan_max_articles_per_run'),
                            icon: 'fas fa-ruler-combined',
                        ),
                    ],
                ),
            ],
        );
    }
}
