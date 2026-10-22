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
 * What the self-hosted decision model is asked, and how far its answers reach.
 *
 * Where the model lives and how it is called is deployment configuration
 * (the DECISION_MODEL_* environment keys); this page only steers its use.
 */
final class DecisionModelSection implements SettingsSectionProvider
{
    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'decision-model',
            title: 'Decision Model',
            description: 'A second opinion from the self-hosted decision model. Each card says what the model is asked and how far its answer reaches.',
            icon: 'fas fa-scale-balanced',
            stage: PipelineStage::Enrich,
            cards: [
                new SettingCard(
                    id: 'category-arbitration',
                    title: 'Category arbitration',
                    description: 'Releases with a readable name that the categorizers could not place, or placed on a weak guess, are sent to the model. Nothing is sent while the model is disabled in the environment, and releases with hashed names or a confident category are never sent.',
                    icon: 'fas fa-folder-tree',
                    settings: [
                        new SettingDefinition(
                            key: 'decision_model_mode',
                            label: 'Mode',
                            help: 'Shadow asks the model and records its answer in the decision log without changing any category. Apply also files the release where the model says, when the model is confident enough. A change reaches running processes within a minute.',
                            type: SettingType::Enum,
                            options: [
                                'off' => 'Off — the model is not asked',
                                'shadow' => 'Shadow — ask and log, keep the category',
                                'apply' => 'Apply — ask, log and use a confident answer',
                            ],
                            icon: 'fas fa-toggle-on',
                        ),
                        new SettingDefinition(
                            key: 'decision_model_apply_threshold',
                            label: 'Apply at or above',
                            help: 'The model\'s confidence, from 0 to 1, at or above which its answer is accepted. A confident root with a less confident subcategory files the release in that root\'s Other category. Default <code>0.80</code>.',
                            type: SettingType::Text,
                            rules: ['required', 'numeric', 'between:0,1'],
                            icon: 'fas fa-gauge-high',
                            placeholder: '0.80',
                        ),
                        new SettingDefinition(
                            key: 'decision_model_arbitrate_below',
                            label: 'Ask below',
                            help: 'The categorizers\' own confidence, from 0 to 1, below which a release is sent to the model. Default <code>0.70</code>.',
                            type: SettingType::Text,
                            rules: ['required', 'numeric', 'between:0,1'],
                            icon: 'fas fa-gauge',
                            placeholder: '0.70',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'music-album-check',
                    title: 'Music album check',
                    description: 'For releases the music matcher has looked up, the model is asked which of the matcher\'s candidate albums the release is, or none of them, judging from the release name and its file names. Up to 50 releases are checked per music pass, newest first, including releases matched before this check existed. Nothing is sent while the model is disabled in the environment.',
                    icon: 'fas fa-compact-disc',
                    settings: [
                        new SettingDefinition(
                            key: 'decision_model_music_album_mode',
                            label: 'Mode',
                            help: 'Shadow asks the model and records its answer in the decision log without changing the album, the name or anything else.',
                            type: SettingType::Enum,
                            options: [
                                'off' => 'Off — the model is not asked',
                                'shadow' => 'Shadow — ask and log, change nothing',
                            ],
                            icon: 'fas fa-toggle-on',
                        ),
                    ],
                ),
            ],
        );
    }
}
