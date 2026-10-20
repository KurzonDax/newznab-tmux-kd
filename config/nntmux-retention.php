<?php

declare(strict_types=1);

use App\Models\SystemMetric;
use App\Models\UserActivityStat;
use App\Models\UserRequest;
use App\Services\Backup\BackupCatalog;
use App\Services\Binaries\MissedPartHandler;
use App\Services\CollectionCleanupService;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\NNTP\ProviderListingCanary;
use App\Services\ObfuscationRecovery\RecoveryCatalog;
use App\Services\ObfuscationRecovery\RecoveryEvidenceRetention;
use App\Services\ObfuscationRecovery\RecoveryHistoryRetention;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use App\Services\ObfuscationRecovery\RecoveryReferences;
use App\Services\ObfuscationRecovery\RecoveryRetention;
use App\Services\ObfuscationRecovery\RecoveryRunRefresh;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Queue\DatabaseQueue;
use Laravel\Pulse\Storage\DatabaseStorage;

// Every table in database/schema/mariadb-schema.sql needs exactly one retention class.
// core_tables: rows are kept on purpose and leave only through a person's action, or never.
// owners: table => the class that deletes its rows automatically, or 'schedule:<name>' for a
//   named Schedule::call closure in routes/console.php.
// Tables in neither list must cascade (ON DELETE CASCADE) from a classified parent.
// Checked by tests/Feature/RetentionRegistryTest.php.
return [
    'core_tables' => [
        // No delete path yet; kept on purpose for now (#1116 decision 4).
        'obfuscation_recovery_attempts',
        'obfuscation_recovery_budget_owners',
        'obfuscation_recovery_budgets',
        'obfuscation_recovery_bundles',
        'obfuscation_recovery_controls',
        'obfuscation_recovery_dispatch',
        'obfuscation_recovery_evidence',
        'obfuscation_recovery_files',
        'obfuscation_recovery_frontier_allowances',
        'obfuscation_recovery_frontier_installs',
        'obfuscation_recovery_frontier_members',
        'obfuscation_recovery_frontier_policy',
        'obfuscation_recovery_frontier_progress',
        'obfuscation_recovery_frontier_requests',
        'obfuscation_recovery_frontier_targets',
        'obfuscation_recovery_gaps',
        'obfuscation_recovery_housekeeping',
        'obfuscation_recovery_index_owners',
        'obfuscation_recovery_metrics',
        'obfuscation_recovery_provider_backoff',
        'obfuscation_recovery_publications',
        'obfuscation_recovery_runs',
        'obfuscation_recovery_scan_windows',
        'obfuscation_recovery_slots',
        'obfuscation_recovery_targets',
        'obfuscation_recovery_traffic',
        'obfuscation_recovery_work',
        // Rows are kept: each one stops a release from being processed twice. #1125 clears their NZB copies when a job finishes.
        'par2_sidecar_operations',
        // Reference data, settings, user content and history: rows leave only by a person's action, or never.
        'anidb_info',
        'anidb_titles',
        'audio_genres',
        'binaryblacklist',
        'bookinfo',
        'category_regexes',
        'collection_regexes',
        'collection_sweep_cursors',
        'companies',
        'consoleinfo',
        'content',
        'countries',
        'decision_model_criteria',
        'decision_model_decisions',
        'download_stats',
        'failed_jobs',
        'firewall',
        'forum_categories',
        'forum_posts',
        'forum_threads',
        'forum_threads_read',
        'game_modes',
        'gamesinfo',
        'gdpr_audit_logs',
        'gdpr_consents',
        'gdpr_requests',
        'genres',
        'grab_stats',
        'invitations',
        'languages',
        'logging',
        'media_infos',
        'migrations',
        'movieinfo',
        'music_cover_art_lookups',
        'musicbrainz_artists',
        'musicbrainz_release_tracks',
        'musicinfo',
        'networks',
        'nntp_provider_traffic',
        'password_reset_tokens',
        'password_securities',
        'payments',
        'paypal_payments',
        'people',
        'permissions',
        'personal_access_tokens',
        'player_perspectives',
        'poster_renames',
        'predb',
        'predb_crcs',
        'predb_imports',
        'reconciliation_admissions',
        'reconciliation_budget_deferrals',
        'reconciliation_decisions',
        'reconciliation_traffic',
        'registration_periods',
        'registration_status_history',
        'release_informs',
        'release_naming_regexes',
        'release_regexes',
        'release_stats',
        'role_expiration_emails',
        'role_promotions',
        'role_stats',
        'roles',
        'root_categories',
        'search_index_failures',
        'service_incidents',
        'service_statuses',
        'settings',
        'short_groups',
        'signup_stats',
        'srrdb_lookups',
        'steam_apps',
        'telescope_entries',
        'telescope_monitoring',
        'tv_episodes',
        'tv_info',
        'usenet_groups',
        'user_activities',
        'user_invitations',
        'user_role_history',
        'users',
        'videos',
        'videos_aliases',
    ],
    'owners' => [
        'cache' => DatabaseStore::class,
        'collections' => CollectionCleanupService::class,
        'database_backups' => BackupCatalog::class,
        'jobs' => DatabaseQueue::class,
        'missed_parts' => MissedPartHandler::class,
        'nntp_listing_coverage' => ProviderListingCanary::class,
        'obfuscation_recovery_artifacts' => RecoveryEvidenceRetention::class,
        'obfuscation_recovery_catalog' => RecoveryCatalog::class,
        'obfuscation_recovery_coverage' => RecoveryPositiveCoverage::class,
        'obfuscation_recovery_dirty' => RecoveryRunRefresh::class,
        'obfuscation_recovery_expired_headers' => RecoveryHistoryRetention::class,
        'obfuscation_recovery_frontier_conflicts' => RecoveryHistoryRetention::class,
        'obfuscation_recovery_frontier_ranges' => RecoveryHistoryRetention::class,
        'obfuscation_recovery_frontiers' => RecoveryHistoryRetention::class,
        'obfuscation_recovery_headers' => RecoveryRetention::class,
        'obfuscation_recovery_references' => RecoveryReferences::class,
        'obfuscation_recovery_scan_batches' => RecoveryHistoryRetention::class,
        'obfuscation_recovery_scans' => RecoveryHistoryRetention::class,
        'pulse_aggregates' => DatabaseStorage::class,
        'pulse_entries' => DatabaseStorage::class,
        'pulse_values' => DatabaseStorage::class,
        'reconciliation_claims' => CollectionOwnership::class,
        'reconciliation_evidence' => 'schedule:cleanup-reconciliation-evidence',
        'releases' => ReleaseManagementService::class,
        'system_metrics' => SystemMetric::class,
        'user_activity_stats' => UserActivityStat::class,
        'user_activity_stats_hourly' => UserActivityStat::class,
        'user_downloads' => 'schedule:cleanup-api-request-logs',
        'user_requests' => UserRequest::class,
    ],
];
