<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Services\Releases\ClipGenerationPolicy;
use App\Services\Releases\DynamicPreviewBudgetPolicy;
use App\Support\Settings\PipelineStage;
use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * What a finished release is opened up and looked inside for.
 *
 * The gates here <em>skip</em>. That is the one thing this page has to keep saying, because
 * the size gates on Release Formation look identical and delete.
 */
final class PostProcessingSection implements SettingsSectionProvider
{
    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'post-processing',
            title: 'Post-Processing',
            description: 'Archive inspection, password detection, NFO retrieval, and the previews and clips built from what is found inside.',
            icon: 'fas fa-magnifying-glass-plus',
            stage: PipelineStage::Enrich,
            cards: [
                new SettingCard(
                    id: 'additional',
                    title: 'Additional pane',
                    description: 'Window 2, pane 0. It fans out into per-GUID child processes rather than doing the work itself.',
                    icon: 'fas fa-gears',
                    settings: [
                        new SettingDefinition(
                            key: 'post',
                            label: 'Pane mode',
                            help: 'Which of the two jobs this pane runs. Archive inspection opens rar and zip sets for passwords, media info and previews; NFO retrieval fetches .nfo articles. They are independent, and NFO retrieval has its own thread count in the NFO card below.',
                            type: SettingType::Enum,
                            options: [
                                0 => 'Off',
                                1 => 'Archive inspection only',
                                2 => 'NFO retrieval only',
                                3 => 'Archive inspection and NFO retrieval',
                            ],
                            icon: 'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'postthreads',
                            label: 'Additional threads',
                            help: 'Child processes the pane fans out to, each holding one NNTP connection and reusing it across batches. Raise it only within your CPU, memory, disk and provider connection limits.',
                            type: SettingType::Int,
                            unit: 'workers',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'post_timer',
                            label: 'Pane sleep',
                            help: 'How long the pane waits after a cycle. Workers drain several batches before this delay, so a very short interval buys little.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'eligibility',
                    title: 'Eligibility',
                    description: 'Which releases are worth opening. <strong>These skip, they do not delete</strong> &mdash; unlike the size gates on Release Formation, a release outside this range keeps its row and its NZB and simply never gets inspected.',
                    icon: 'fas fa-filter',
                    settings: [
                        new SettingDefinition(
                            key: 'minsizetopostprocess',
                            label: 'Minimum size to inspect',
                            help: 'Releases smaller than this are left alone. <strong>Blank is not "no minimum": it reads as the seeded 1&nbsp;MB.</strong> Set 0 to inspect everything. Audio previews ignore this gate entirely, since a single-track post can sit under any sensible floor.',
                            type: SettingType::Size,
                            icon: 'fas fa-compress',
                        ),
                        new SettingDefinition(
                            key: 'maxsizetopostprocess',
                            label: 'Maximum size to inspect',
                            help: 'Releases larger than this are left alone. <strong>Blank reads as the seeded 100&nbsp;GB</strong>, not "no maximum". Set 0 to inspect everything.',
                            type: SettingType::Size,
                            icon: 'fas fa-expand',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'batching',
                    title: 'Batching & timeouts',
                    description: 'How much a worker takes on at once, and when it gives up on a release that will not finish.',
                    icon: 'fas fa-stopwatch',
                    settings: [
                        new SettingDefinition(
                            key: 'maxaddprocessed',
                            label: 'Releases per batch',
                            help: 'Releases one worker claims at a time for passwords, previews and media info. Bigger batches cut query overhead and raise each worker\'s memory and runtime. Default 25.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                        new SettingDefinition(
                            key: 'releaseprocessingtimeout',
                            label: 'Per-release timeout',
                            help: 'Wall-clock ceiling on a single release before the worker moves on. Keep it below the multiprocessing child timeout, or the child dies first and the release is never stamped. 0 disables. Default 120.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-half',
                        ),
                        new SettingDefinition(
                            key: 'maxpptimeoutcount',
                            label: 'Timeouts before deletion',
                            help: 'How many times a release may hit the per-release timeout before it is <strong>permanently deleted</strong>. Default 3.',
                            type: SettingType::Int,
                            unit: 'strikes',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-trash-can',
                        ),
                        new SettingDefinition(
                            key: 'timeoutseconds',
                            label: 'External tool timeout',
                            help: 'How long unrar, 7zip, mediainfo and ffmpeg may run before being killed. Needs the GNU timeout binary on the host. 0 disables; 60 is a sensible value.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-clock',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'archives',
                    title: 'Archive & password inspection',
                    description: 'How deep into a rar or zip set the inspection goes, and what marks a release as passworded.',
                    icon: 'fas fa-file-zipper',
                    settings: [
                        new SettingDefinition(
                            key: 'maxnestedlevels',
                            label: 'Nested archive depth',
                            help: 'How many archives deep to descend when a rar or zip contains another one.',
                            type: SettingType::Int,
                            unit: 'levels',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-layer-group',
                        ),
                        new SettingDefinition(
                            key: 'maxpartsprocessed',
                            label: 'Archive download budget',
                            help: 'How many archive parts one release may pull while being inspected before the attempt is abandoned. This is a budget, not a retry count: inspection stops as soon as it has what it needs.',
                            type: SettingType::Int,
                            unit: 'parts',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-download',
                        ),
                        new SettingDefinition(
                            key: 'passchkattempts',
                            label: 'Failed downloads tolerated',
                            help: 'How many failed part downloads a password check may absorb before giving up on the release. Above 1 this overrides the archive download budget and slows inspection considerably; 1 is almost always right.',
                            type: SettingType::Int,
                            unit: 'attempts',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-check-double',
                        ),
                        new SettingDefinition(
                            key: 'innerfileblacklist',
                            label: 'Inner file blacklist',
                            help: 'A release whose archive holds a file name matching this regex is flagged as potentially passworded. It hides the release rather than deleting it. <strong>An invalid regex throws during processing</strong>, so test it before saving.',
                            type: SettingType::Textarea,
                            icon: 'fas fa-ban',
                            placeholder: '/setup\.exe|password\.url/i',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'nfo',
                    title: 'NFO retrieval',
                    description: 'Fetching the .nfo article a posting usually carries. This pane runs only when the pane mode above includes NFO retrieval.',
                    icon: 'fas fa-file-lines',
                    settings: [
                        SettingDefinition::bool(
                            'lookupnfo',
                            'Fetch NFO files',
                            'Whether to retrieve NFO articles from Usenet. NFOs feed name fixing and give the details page something to show; the movie and TV lookups run from release names and do not depend on this.',
                            'fas fa-file-arrow-down',
                        ),
                        new SettingDefinition(
                            key: 'nfothreads',
                            label: 'NFO threads',
                            help: 'Parallel NFO workers, each opening its own NNTP session. Capped at 16.',
                            type: SettingType::Int,
                            unit: 'workers',
                            rules: ['required', 'integer', 'min:1', 'max:16'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'maxnfoprocessed',
                            label: 'NFOs per run',
                            help: 'Releases one NFO run works through. This uses NNTP connections, one per thread, but queries no external provider.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-list-ol',
                        ),
                        new SettingDefinition(
                            key: 'minsizetoprocessnfo',
                            label: 'Minimum size for NFO retrieval',
                            help: 'Releases smaller than this are skipped. 0 ignores the floor.',
                            type: SettingType::Size,
                            icon: 'fas fa-compress',
                        ),
                        new SettingDefinition(
                            key: 'maxsizetoprocessnfo',
                            label: 'Maximum size for NFO retrieval',
                            help: 'Releases larger than this are skipped. 0 ignores the ceiling.',
                            type: SettingType::Size,
                            icon: 'fas fa-expand',
                        ),
                        new SettingDefinition(
                            key: 'maxnforetries',
                            label: 'NFO download retries',
                            help: 'How many times a failed NFO download is retried before the release is marked as having none. 0 never retries; the maximum is 7.',
                            type: SettingType::Int,
                            unit: 'retries',
                            rules: ['required', 'integer', 'min:0', 'max:7'],
                            icon: 'fas fa-rotate-right',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'previews',
                    title: 'Previews & samples',
                    description: 'The images and clips built from the video found inside a release. Every one of these needs ffmpeg on the host.',
                    icon: 'fas fa-photo-film',
                    settings: [
                        SettingDefinition::bool(
                            'processthumbnails',
                            'Video thumbnails',
                            'Extract a still frame from the main video file as the release\'s preview image.',
                            'fas fa-image',
                        ),
                        SettingDefinition::bool(
                            'processvideos',
                            'Video samples',
                            'Build the short ogg sample clip. These are 1-3 seconds and around 100&nbsp;KB.',
                            'fas fa-film',
                        ),
                        SettingDefinition::bool(
                            'processjpg',
                            'JPG samples',
                            'Retrieve a sample JPG posted alongside the release. Mostly an XXX convention.',
                            'fas fa-file-image',
                        ),
                        new SettingDefinition(
                            key: 'segmentstodownload',
                            label: 'Fixed segment budget',
                            help: 'Articles fetched to build a sample or preview image on roots without the adaptive budget below. Default 2.',
                            type: SettingType::Int,
                            unit: 'segments',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-download',
                        ),
                        new SettingDefinition(
                            key: 'generate_previews',
                            label: 'Generate previews for',
                            help: 'Root categories that get preview images and sample clips at all. Unchecking one stops new work; it does not remove previews already made.',
                            type: SettingType::RootToggles,
                            icon: 'fas fa-photo-video',
                        ),
                        new SettingDefinition(
                            key: 'dynamic_preview_budget',
                            label: 'Adaptive budget for',
                            help: 'Roots where the segment budget is worked out from the file\'s bitrate rather than fixed, so a preview reaches the target duration instead of whatever two articles happened to hold. Only Movies, TV and XXX are eligible.',
                            type: SettingType::RootToggles,
                            eligibleRootIds: DynamicPreviewBudgetPolicy::ELIGIBLE_ROOT_IDS,
                            icon: 'fas fa-sliders',
                        ),
                        new SettingDefinition(
                            key: 'preview_target_seconds',
                            label: 'Preview target duration',
                            help: 'How much of the main video the adaptive budget aims to fetch. The fetch ceiling below wins over this target. 0 disables top-ups everywhere. Default 30.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-stopwatch',
                        ),
                        new SettingDefinition(
                            key: 'preview_max_fetch_mb',
                            label: 'Preview fetch ceiling',
                            help: 'Hard cap on bytes fetched for one video under the adaptive budget, whatever the bitrate says the target needs. 0 is unlimited. Default 300.',
                            type: SettingType::Int,
                            unit: 'MB',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hard-drive',
                        ),
                        new SettingDefinition(
                            key: 'preview_max_rar_parts',
                            label: 'Preview archive parts',
                            help: 'Archive volumes the adaptive budget may fetch while extending a video found inside a rar set, the first included. Fetching stops as soon as the target is covered, so this is a ceiling. Default 6.',
                            type: SettingType::Int,
                            unit: 'parts',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-file-zipper',
                        ),
                        new SettingDefinition(
                            key: 'generate_clips',
                            label: 'Generate clips for',
                            help: 'Roots that get a playable clip alongside the preview image. Only Movies, TV and XXX are eligible.',
                            type: SettingType::RootToggles,
                            eligibleRootIds: ClipGenerationPolicy::ELIGIBLE_ROOT_IDS,
                            icon: 'fas fa-clapperboard',
                        ),
                        new SettingDefinition(
                            key: 'clip_minimum_seconds',
                            label: 'Minimum clip duration',
                            help: 'Shortest clip worth keeping. A shorter encode is discarded and the release keeps its preview image but shows no play chip. 0 stores whatever comes out. Default 5.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hourglass-start',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'audio',
                    title: 'Audio previews',
                    description: 'A separate path from archive inspection, with its own workers and its own candidate query. It fetches one article, probes it, and only then pulls the rest of the head.',
                    icon: 'fas fa-headphones',
                    settings: [
                        new SettingDefinition(
                            key: 'postthreadsaudio',
                            label: 'Audio preview workers',
                            help: 'Parallel audio workers, each holding one NNTP connection. Default 1.',
                            type: SettingType::Int,
                            unit: 'workers',
                            rules: ['required', 'integer', 'min:1', 'max:99'],
                            icon: 'fas fa-diagram-project',
                        ),
                        new SettingDefinition(
                            key: 'audio_min_completion_percent',
                            label: 'Minimum source completion',
                            help: 'Releases measured below this are skipped before any audio article is fetched. 0 disables the check. Default 95.',
                            type: SettingType::Int,
                            unit: '%',
                            rules: ['required', 'integer', 'min:0', 'max:100'],
                            icon: 'fas fa-chart-pie',
                        ),
                        new SettingDefinition(
                            key: 'audio_segments_to_download',
                            label: 'Head articles fetched',
                            help: 'Articles taken from the head of a posted audio file, the probe article included. Too few and the clip runs out of audio before the preview length. Default 12.',
                            type: SettingType::Int,
                            unit: 'articles',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-download',
                        ),
                        new SettingDefinition(
                            key: 'audio_max_rar_parts',
                            label: 'Archive parts fetched',
                            help: 'Archive volumes fetched before giving up on finding one complete track. Fetching stops as soon as a track is whole, so this is a ceiling. Default 6.',
                            type: SettingType::Int,
                            unit: 'parts',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-file-zipper',
                        ),
                        new SettingDefinition(
                            key: 'audio_max_archive_mb',
                            label: 'Archive fetch ceiling',
                            help: 'Downloaded archive bytes allowed while looking for one complete track. 0 is unlimited. Default 1024.',
                            type: SettingType::Int,
                            unit: 'MB',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hard-drive',
                        ),
                        new SettingDefinition(
                            key: 'audio_preview_seconds',
                            label: 'Preview length',
                            help: 'How long the clip is. A shorter source yields a shorter clip rather than none. Default 30.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-stopwatch',
                        ),
                        new SettingDefinition(
                            key: 'audio_preview_start_seconds',
                            label: 'Preview start offset',
                            help: 'How far into the track the clip starts, skipping lead-in silence. Falls back to the very start when there is not that much audio. Default 10.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-forward',
                        ),
                        SettingDefinition::bool(
                            'audio_spectrogram',
                            'Render spectrogram',
                            'Draw a spectrogram beside each audio preview, showing where the source encoder\'s low-pass sits.',
                            'fas fa-wave-square',
                        ),
                    ],
                ),
            ],
        );
    }
}
