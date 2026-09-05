<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Services\ReleaseRemoverService;
use App\Services\Tmux\TmuxTaskRunner;
use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * Correcting release names, and throwing away what should never have been indexed.
 */
final class NamingHygieneSection implements SettingsSectionProvider
{
    /**
     * The crap classes the picker offers, taken from the service that runs them.
     *
     * The list is derived rather than written out so the picker cannot offer a token the
     * sweep would reject, or hide one it accepts. Only the wording is ours.
     *
     * @return array<string, string>
     */
    private static function crapClasses(): array
    {
        /** @var array<string, string> $labels Wording only; the token set comes from the service. */
        $labels = [
            'blacklist' => 'Blacklisted names',
            'blfiles' => 'Blacklisted inner file names',
            'codec' => 'Codec-spam posters',
            'executable' => 'Executables',
            'gibberish' => 'Gibberish names',
            'hashed' => 'Hashed names',
            'installbin' => 'install.bin payloads',
            'nzb' => 'Single-NZB releases',
            'par2only' => 'PAR2-only releases',
            'passworded' => 'Passworded archives',
            'passwordurl' => 'Password-URL archives',
            'sample' => 'Sample-only releases',
            'scr' => 'Screener spam',
            'short' => 'Undersized releases',
            'size' => 'Size mismatches',
            'wmv_all' => 'WMV spam',
        ];

        $options = [];

        foreach (ReleaseRemoverService::TYPES as $type) {
            $options[$type] = $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
        }

        return $options;
    }

    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'naming-hygiene',
            title: 'Naming & Hygiene',
            description: 'The two panes that clean up after the indexer: one fixes names, the other deletes what should not be in the index at all.',
            icon: 'fas fa-broom',
            stage: PipelineStage::Enrich,
            cards: [
                new SettingCard(
                    id: 'fix-names',
                    title: 'Fix release names',
                    description: 'Window 1, pane 0. It works a chain of methods -- NFO, PAR2, MD5, inner file names -- against releases whose posted names say nothing useful.',
                    icon: 'fas fa-signature',
                    settings: [
                        SettingDefinition::bool(
                            'fix_names',
                            'Fix release names',
                            'Whether the naming pane runs. Everything downstream reads the corrected name, so turning this off also starves the renamed-only lookup modes.',
                            'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'fixnamethreads',
                            label: 'Naming threads',
                            help: 'Parallel naming workers. Capped at 16.',
                            type: SettingType::Int,
                            unit: 'workers',
                            rules: ['required', 'integer', 'min:1', 'max:16'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'fixnamesperrun',
                            label: 'Releases per run',
                            help: 'Releases one naming pass checks.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                        new SettingDefinition(
                            key: 'fix_timer',
                            label: 'Pane sleep',
                            help: 'How long the pane waits after a pass before starting the next one.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                        new SettingDefinition(
                            key: 'fix_names_timeout',
                            label: 'Per-step timeout',
                            help: 'Wall-clock limit for each naming method and for the catch-up sweep. A step that hits it is killed and the chain moves on to the next one, so one slow method cannot stall the pane. Minimum '.TmuxTaskRunner::MIN_FIX_NAMES_TIMEOUT.'.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:'.TmuxTaskRunner::MIN_FIX_NAMES_TIMEOUT],
                            icon: 'fas fa-stopwatch',
                        ),
                        SettingDefinition::bool(
                            'descriptive_title_rename',
                            'Rename from descriptive file names',
                            'Use a human-written inner video file name as the release name, but only when the current name looks obfuscated or hashed.',
                            'fas fa-pen',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'remove-crap',
                    title: 'Remove crap',
                    description: 'Window 1, pane 1. <strong>Everything this pane does is a permanent delete.</strong> All runs every class over a 2-hour window each cycle. Custom runs only the classes you pick, over the whole index on its first cycle and a 4-hour window afterwards.',
                    icon: 'fas fa-trash-can',
                    settings: [
                        new SettingDefinition(
                            key: 'fix_crap_opt',
                            label: 'Sweep mode',
                            help: 'Disabled leaves the pane idle. All runs every class below. Custom runs the classes you tick.',
                            type: SettingType::Enum,
                            options: [
                                'Disabled' => 'Disabled',
                                'All' => 'All classes',
                                'Custom' => 'Custom selection',
                            ],
                            icon: 'fas fa-toggle-on',
                        ),
                        new SettingDefinition(
                            key: 'fix_crap',
                            label: 'Custom classes',
                            help: 'Read only when the sweep mode is Custom. These are every class the sweep accepts.',
                            type: SettingType::CheckboxSet,
                            options: self::crapClasses(),
                            icon: 'fas fa-list-check',
                        ),
                        new SettingDefinition(
                            key: 'crap_timer',
                            label: 'Pane sleep',
                            help: 'How long the pane waits after a sweep before starting the next one.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'executables',
                    title: 'Executable discard',
                    description: 'Releases found to contain an executable payload. This is stricter than the inner-file blacklist on Post-Processing: that one hides a release as passworded, this one <strong>deletes it irreversibly</strong> along with its NZB, images and search-index entry.',
                    icon: 'fas fa-triangle-exclamation',
                    settings: [
                        new SettingDefinition(
                            key: 'discard_executable_extensions',
                            label: 'Executable extensions',
                            help: 'Pipe-separated list of extensions treated as executable payloads. Default <code>dll|exe|msi|scr|com|bat|cmd|pif</code>.',
                            type: SettingType::Text,
                            icon: 'fas fa-file-code',
                        ),
                        new SettingDefinition(
                            key: 'discard_executables',
                            label: 'Discard in',
                            help: 'Root categories where a release containing one of those extensions is deleted.',
                            type: SettingType::RootToggles,
                            icon: 'fas fa-trash-can',
                        ),
                        SettingDefinition::bool(
                            'forced_root_pc_escape',
                            'Let PC releases escape a forced root',
                            'In forced-root groups, keep the PC category for releases whose names categorize as PC software or games with at least 0.85 confidence, instead of overriding it. Such releases often carry malware and should stay visible as what they are. An escaped release then follows the PC root\'s discard toggle; disguised software that does not categorize as PC stays in the forced root and follows that root\'s toggle.',
                            'fas fa-shield-halved',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'categorization',
                    title: 'Categorization',
                    description: 'Two choices that move releases between categories rather than deleting them.',
                    icon: 'fas fa-folder-tree',
                    settings: [
                        SettingDefinition::bool(
                            'categorizeforeign',
                            'Separate foreign titles',
                            'Send non-English movies and TV to the Foreign categories instead of leaving them beside English ones.',
                            'fas fa-globe',
                        ),
                        SettingDefinition::bool(
                            'catwebdl',
                            'Separate WEB-DL',
                            'Send WEB-DL releases to the WEB-DL category instead of HD TV. Some downstream clients only look at HD TV, so separating them hides these releases from those clients.',
                            'fas fa-cloud-arrow-down',
                        ),
                    ],
                ),
            ],
        );
    }
}
