<?php

declare(strict_types=1);

namespace App\Support\Settings\Sections;

use App\Support\Settings\SettingCard;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingSection;
use App\Support\Settings\SettingsSectionProvider;
use App\Support\Settings\SettingType;

/**
 * The tmux processing engine itself: whether it runs, how it is laid out, and what it watches.
 *
 * This page has no pipeline stage of its own because it governs every stage at once.
 */
final class EngineSection implements SettingsSectionProvider
{
    public static function section(): SettingSection
    {
        return new SettingSection(
            id: 'engine',
            title: 'Engine & Monitoring',
            description: 'The master switch for the processing engine, the safety valves that hold work back, and the extra windows the session opens.',
            icon: 'fas fa-terminal',
            stage: null,
            cards: [
                new SettingCard(
                    id: 'session',
                    title: 'Session',
                    description: 'The tmux session the panes run in.',
                    icon: 'fas fa-play',
                    settings: [
                        new SettingDefinition(
                            key: 'running',
                            label: 'Processing engine',
                            help: 'The master switch. Stopping it lets every pane finish its current script and then leaves them idle; it does not kill work in flight.',
                            type: SettingType::Enum,
                            options: [1 => 'Running', 0 => 'Stopped'],
                            icon: 'fas fa-power-off',
                        ),
                        new SettingDefinition(
                            key: 'tmux_session',
                            label: 'Session name',
                            help: 'The tmux session name the panes attach to. No spaces. Changing it while the engine is running orphans the existing session, so stop the engine first.',
                            type: SettingType::Text,
                            rules: ['required', 'string', 'max:64', 'regex:/^\S+$/'],
                            icon: 'fas fa-tag',
                        ),
                        new SettingDefinition(
                            key: 'niceness',
                            label: 'Process niceness',
                            help: 'Scheduling priority for every script the engine starts. Lower runs sooner: -20 is the highest priority, 19 the lowest. Default 19, which keeps indexing out of the web front end\'s way.',
                            type: SettingType::Int,
                            rules: ['required', 'integer', 'min:-20', 'max:19'],
                            icon: 'fas fa-gauge',
                        ),
                        new SettingDefinition(
                            key: 'monitor_delay',
                            label: 'Stats refresh',
                            help: 'How long the monitor pane waits between refreshes. Every refresh re-runs the counting queries, so a short interval costs database work whether or not anything changed.',
                            type: SettingType::Int,
                            unit: 'seconds',
                            rules: ['required', 'integer', 'min:1'],
                            icon: 'fas fa-rotate',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'safety-valves',
                    title: 'Safety valves',
                    description: 'Backlog ceilings that pause the front of the pipeline so the back can catch up. Both are live engine settings; until now they could only be changed in SQL.',
                    icon: 'fas fa-shield-halved',
                    settings: [
                        new SettingDefinition(
                            key: 'collections_kill',
                            label: 'Pause header collection above',
                            help: 'When the collections table holds more rows than this, the monitor stops starting new header and backfill work until it drops back under. <strong>0 turns the valve off</strong>, which is the seeded default.',
                            type: SettingType::Int,
                            unit: 'collections',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-layer-group',
                        ),
                        new SettingDefinition(
                            key: 'postprocess_kill',
                            label: 'Pause post-processing above',
                            help: 'When total pending post-processing work is larger than this, the monitor stops starting new post-process passes until it drops back under. <strong>0 turns the valve off</strong>, which is the seeded default.',
                            type: SettingType::Int,
                            unit: 'releases',
                            rules: ['required', 'integer', 'min:0'],
                            icon: 'fas fa-hand',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'layout',
                    title: 'Layout mode',
                    description: 'How many windows and panes the session opens.',
                    icon: 'fas fa-table-cells-large',
                    settings: [
                        new SettingDefinition(
                            key: 'sequential',
                            label: 'Layout mode',
                            help: 'Full opens three windows and runs the panes in parallel. Basic opens a reduced set for a machine that cannot keep parallel panes fed. Anything else stored here runs as Full.',
                            type: SettingType::Enum,
                            options: [
                                0 => 'Full — three windows, panes run in parallel',
                                1 => 'Basic — reduced pane set',
                            ],
                            icon: 'fas fa-window-restore',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'irc-scraper',
                    title: 'IRC scraper',
                    description: 'The pre-database announce feed.',
                    icon: 'fas fa-comments',
                    settings: [
                        SettingDefinition::bool(
                            'run_ircscraper',
                            'Run the IRC scraper',
                            'Opens a pane that joins the configured announce channels and writes what they announce into the PreDB. It needs the IRC credentials in the environment; without them the pane starts and does nothing.',
                            'fas fa-hashtag',
                        ),
                    ],
                ),
                new SettingCard(
                    id: 'monitoring',
                    title: 'Monitoring windows',
                    description: 'Extra windows the session opens beside the processing panes. The tool list is read once when the engine starts, so a change here takes effect on the next start; a tool that is not installed is skipped rather than retried.',
                    icon: 'fas fa-chart-line',
                    settings: [
                        SettingDefinition::bool(
                            'console',
                            'Shell console',
                            'An interactive shell in its own window, for running commands against the install without leaving the session.',
                            'fas fa-terminal',
                        ),
                        SettingDefinition::bool('htop', 'htop', 'Interactive process viewer. Package: <code>htop</code>.', 'fas fa-microchip'),
                        SettingDefinition::bool('mytop', 'mytop', 'MariaDB query monitor. Package: <code>mytop</code>.', 'fas fa-database'),
                        SettingDefinition::bool('nmon', 'nmon', 'System performance monitor. Package: <code>nmon</code>.', 'fas fa-server'),
                        SettingDefinition::bool('vnstat', 'vnstat', 'Network traffic totals. Package: <code>vnstat</code>.', 'fas fa-network-wired'),
                        new SettingDefinition(
                            key: 'vnstat_args',
                            label: 'vnstat arguments',
                            help: 'Passed to vnstat verbatim, for example <code>-i eth0</code>.',
                            type: SettingType::Text,
                            icon: 'fas fa-terminal',
                            placeholder: '-i eth0',
                        ),
                        SettingDefinition::bool('tcptrack', 'tcptrack', 'Live connection list. Package: <code>tcptrack</code>.', 'fas fa-diagram-project'),
                        new SettingDefinition(
                            key: 'tcptrack_args',
                            label: 'tcptrack arguments',
                            help: 'Passed to tcptrack verbatim, for example <code>-i eth0 port 443</code>.',
                            type: SettingType::Text,
                            icon: 'fas fa-terminal',
                            placeholder: '-i eth0 port 443',
                        ),
                        SettingDefinition::bool('bwmng', 'bwm-ng', 'Live bandwidth meter. Package: <code>bwm-ng</code>.', 'fas fa-gauge-high'),
                        SettingDefinition::bool(
                            'redis',
                            'Redis monitor',
                            'Runs redis-cli against the Redis configured in the environment. Needs <code>redis-tools</code> on the host.',
                            'fas fa-bolt',
                        ),
                        new SettingDefinition(
                            key: 'redis_args',
                            label: 'Redis monitor arguments',
                            help: 'Passed to redis-cli verbatim, for example <code>info clients</code>. Blank shows the default stats and memory view.',
                            type: SettingType::Text,
                            icon: 'fas fa-terminal',
                            placeholder: 'info clients',
                        ),
                    ],
                ),
            ],
        );
    }
}
