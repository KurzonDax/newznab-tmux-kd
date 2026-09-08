<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecoveryAdmission
{
    public static function allows(int $groupId, RecoveryAlgorithm $algorithm): bool
    {
        $profile = DB::table('usenet_groups')->where('id', $groupId)->value('obfuscation_recovery_profile');

        return RecoveryConfig::fromSettings()->admits($profile, $algorithm->selection());
    }

    public static function nzbSql(string $table = 'r'): string
    {
        if (! Schema::hasTable('obfuscation_recovery_publications')) {
            return '1 = 1';
        }
        $grammar = DB::connection()->getQueryGrammar();
        $config = RecoveryConfig::fromSettings();
        $admitted = [];
        foreach (RecoveryAlgorithm::cases() as $algorithm) {
            if ($config->admits('both', $algorithm->selection())) {
                $admitted[] = "(recovery_nzb.profile = '".$algorithm->value."' AND recovery_group.obfuscation_recovery_profile IN ('both', '".$algorithm->selection()->value."'))";
            }
        }
        $blocked = $admitted !== [] ? 'NOT EXISTS (SELECT 1 FROM '.$grammar->wrapTable('usenet_groups').' recovery_group'
            .' WHERE recovery_group.id = '.$grammar->wrap($table.'.groups_id')
            .' AND ('.implode(' OR ', $admitted).'))' : '1 = 1';

        return 'NOT EXISTS (SELECT 1 FROM '.$grammar->wrapTable('obfuscation_recovery_publications').' recovery_nzb'
            .' WHERE recovery_nzb.releases_id = '.$grammar->wrap($table.'.id')
            ." AND recovery_nzb.state NOT IN ('published', 'absorbed', 'duplicate_policy_discarded')"
            ." AND (recovery_nzb.state <> 'created' OR ".$blocked.'))';
    }
}
