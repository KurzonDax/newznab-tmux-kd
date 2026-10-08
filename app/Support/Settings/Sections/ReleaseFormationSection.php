<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Services\CollectionReconciliation\ReconciliationLimits;
use App\Support\NzbSettingRules;
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
                    id: 'reconciliation',
                    title: 'Reconciliation',
                    description: 'Check whether split collections belong to the same posting before forming a release. These download limits are shared across all reconciliation workers. Both limits apply. Changing a limit keeps usage already counted. When either allowance runs out, further evidence downloads wait for available budget. Usage and budget deferrals appear in the Releases pane (0.0).',
                    icon: 'fas fa-puzzle-piece',
                    settings: [
                        new SettingDefinition(
                            key: 'reconciliation_hourly_mib',
                            label: 'Hourly download limit',
                            help: 'Maximum evidence downloads in one UTC clock hour. Default 256 MiB.',
                            type: SettingType::Int,
                            unit: 'MiB',
                            rules: ['required', 'integer', 'min:1', 'max:'.ReconciliationLimits::maximumMib()],
                        ),
                        new SettingDefinition(
                            key: 'reconciliation_daily_mib',
                            label: 'Daily download limit',
                            help: 'Maximum evidence downloads in one UTC calendar day. Default 2,048 MiB (2 GiB).',
                            type: SettingType::Int,
                            unit: 'MiB',
                            rules: ['required', 'integer', 'min:1', 'max:'.ReconciliationLimits::maximumMib()],
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'obfuscated-recovery',
                    title: 'Obfuscated recovery',
                    description: 'Independent background release formation for selected groups. Starts disabled. Group Edit and Edit Selected choose the supported posting layouts.',
                    icon: 'fas fa-puzzle-piece',
                    settings: [
                        SettingDefinition::bool(
                            'obfuscation_recovery_enabled',
                            'Enable recovery',
                            'Allow new capture and recovery work. Disabling preserves existing releases and retained evidence.',
                            'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'obfuscation_recovery_threads',
                            label: 'Recovery download threads',
                            help: 'Shared by all selected groups and optional inspection. Each thread owns at most one provider connection. Leave capacity for ordinary processing. Default 2.',
                            type: SettingType::Int,
                            unit: 'threads',
                            rules: ['required', 'integer', 'min:1', 'max:60'],
                        ),
                        new SettingDefinition(
                            key: 'obfuscation_recovery_media_candidate_mib',
                            label: 'Media candidate download limit',
                            help: 'Lifetime accounted download ceiling for one candidate, including failed attempts. Structural limits still apply. Default 20 MiB.',
                            type: SettingType::Int,
                            unit: 'MiB',
                            rules: ['required', 'integer', 'min:1', 'max:1048576'],
                        ),
                        new SettingDefinition(
                            key: 'obfuscation_recovery_rar_candidate_mib',
                            label: 'RAR candidate download limit',
                            help: 'Lifetime accounted download ceiling for one candidate, including failed attempts. Structural limits still apply. Default 40 MiB.',
                            type: SettingType::Int,
                            unit: 'MiB',
                            rules: ['required', 'integer', 'min:1', 'max:1048576'],
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
                            help: 'A release holding a smaller share of its articles than this is deleted. 0 turns the check off. A release is kept while a secondary provider may still add late headers to it.',
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
                            key: 'obfuscation_recovery_retention_hours',
                            label: 'Recovery header retention',
                            help: 'Keep captured recovery headers this long from their first capture, even if work is waiting. Independent of incomplete-parts retention. Default 144 hours.',
                            type: SettingType::Int,
                            unit: 'hours',
                            rules: ['required', 'integer', 'min:1', 'max:87600'],
                        ),
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
            ],
        );
    }
}
