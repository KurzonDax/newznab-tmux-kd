<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecoveryIdentityPolicy
{
    public static function ordinarySql(string $column = 'r.id'): string
    {
        if (! Schema::hasTable('obfuscation_recovery_publications')) {
            return '1 = 1';
        }
        $grammar = DB::connection()->getQueryGrammar();

        return 'NOT EXISTS (SELECT 1 FROM '.$grammar->wrapTable('obfuscation_recovery_publications').' recovery_owner'
            .' WHERE recovery_owner.releases_id = '.$grammar->wrap($column)
            ." AND recovery_owner.state NOT IN ('absorbed', 'duplicate_policy_discarded'))";
    }

    public static function singleItemSql(string $column = 'releases.id', ?ConnectionInterface $connection = null, bool $donor = false): string
    {
        $connection ??= DB::connection();
        if (! $connection instanceof Connection) {
            throw new \LogicException('unsupported_database_connection');
        }
        if (! $connection->getSchemaBuilder()->hasTable('obfuscation_recovery_publications')) {
            return '1 = 1';
        }
        $grammar = $connection->getQueryGrammar();
        $archive = $donor ? '' : " OR recovery_identity.identity_scope = 'archive_set'";

        return 'NOT EXISTS (SELECT 1 FROM '.$grammar->wrapTable('obfuscation_recovery_publications').' recovery_identity'
            .' WHERE recovery_identity.releases_id = '.$grammar->wrap($column)
            ." AND recovery_identity.state NOT IN ('absorbed', 'duplicate_policy_discarded')"
            ." AND (recovery_identity.state <> 'published' OR recovery_identity.initialization_state = 'pending'"
            .' OR recovery_identity.deleted_at IS NOT NULL OR recovery_identity.multi_media_inventory = 1'
            ." OR NOT (recovery_identity.profile = 'nyuu-media-v1'".$archive.')))';
    }

    public function publication(int $releaseId): ?object
    {
        if (! Schema::hasTable('obfuscation_recovery_publications')) {
            return null;
        }

        return DB::table('obfuscation_recovery_publications as p')->join('releases as recovery_release', 'recovery_release.id', '=', 'p.releases_id')
            ->where('p.releases_id', $releaseId)->whereColumn('p.guid', 'recovery_release.guid')
            ->whereNotIn('p.state', ['absorbed', 'duplicate_policy_discarded'])->first(['p.*']);
    }

    public function allowsParent(int $releaseId, ?RecoveryNameEvidence $evidence = null): bool
    {
        $publication = $this->publication($releaseId);
        if ($publication === null) {
            return $evidence === null;
        }
        if ($publication->state !== 'published' || $publication->deleted_at !== null) {
            return false;
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        if ($plan->algorithm === RecoveryAlgorithm::Rar && $publication->multi_media_inventory) {
            return false;
        }
        if ($evidence === null) {
            return $publication->initialization_state !== 'pending' && $plan->algorithm === RecoveryAlgorithm::Media && ! $plan->multiMediaInventory();
        }
        if ($evidence->publicationId !== (int) $publication->id || $evidence->manifestDigest !== $plan->manifestDigest) {
            return false;
        }
        $expected = [];
        foreach ($plan->files as $file) {
            if ($file->role !== RecoveryFileRole::Index) {
                $expected[] = $file->identity;
            }
        }
        sort($expected, SORT_STRING);
        $provided = $evidence->fileIds;
        sort($provided, SORT_STRING);

        return $expected === $provided && $evidence->scope === match (true) {
            $plan->algorithm === RecoveryAlgorithm::Rar => RecoveryNameScope::ArchiveSet,
            $plan->multiMediaInventory() => RecoveryNameScope::DescriptiveBundle,
            default => RecoveryNameScope::SingleFile,
        };
    }

    public function allowsDescriptiveCandidate(int $releaseId, string $candidate, RecoveryNameEvidence $evidence): bool
    {
        if ($evidence->scope !== RecoveryNameScope::DescriptiveBundle || strlen($candidate) > 255
            || ! $this->allowsParent($releaseId, $evidence)) {
            return false;
        }
        $publication = $this->publication($releaseId);
        if ($publication === null) {
            return false;
        }
        $plan = RecoveryPlan::fromArray(json_decode($publication->sealed_plan, true, flags: JSON_THROW_ON_ERROR));
        $index = app(RecoveryEvidence::class)->get($publication->index_message_id);
        if ($index === null) {
            return false;
        }
        $inventory = (new RecoveryCachedInventory)->validate($index->data, $plan);
        $names = [];
        foreach ($inventory->files as $file) {
            $names[bin2hex($file->id)] = $file->filename;
        }
        if ((new RecoveryInventoryIdentity)->media($names)['candidate'] !== $candidate) {
            return false;
        }

        return true;
    }

    public function allowsSingleItemMetadata(int $releaseId): bool
    {
        $publication = $this->publication($releaseId);
        if ($publication === null) {
            return true;
        }

        return $publication->state === 'published' && $publication->initialization_state !== 'pending'
            && ! $publication->multi_media_inventory && $publication->deleted_at === null
            && ($publication->profile === RecoveryAlgorithm::Media->value || $publication->identity_scope === 'archive_set');
    }
}
