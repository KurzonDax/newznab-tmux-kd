<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Services\NNTP\NntpProvider;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RecoveryControl
{
    public function begin(RecoveryConfig $config, NntpProvider $provider, int $groupId, string $groupName,
        int $first, int $last, HeaderScanDirection $direction, int $chunks): ?RecoveryScanContext
    {
        if (! $config->enabled) {
            return null;
        }
        $connection = null;
        try {
            $connection = RecoveryConnection::open();

            return $connection->transaction(function () use ($connection, $config, $provider, $groupId, $groupName, $first, $last, $direction, $chunks): ?RecoveryScanContext {
                $selection = $connection->table('usenet_groups')->where('id', $groupId)->value('obfuscation_recovery_profile');
                $media = $config->admits($selection, RecoveryAlgorithm::Media->selection());
                $rar = $config->admits($selection, RecoveryAlgorithm::Rar->selection());
                if (! $media && ! $rar) {
                    return null;
                }
                $identity = new RecoveryIdentity;
                $source = $this->observe($connection, 'primary', $identity->digest([
                    $provider->host, (string) $provider->port, (string) $provider->ssl, $provider->username,
                ]));
                $global = $this->observe($connection, 'global', $identity->digest(['enabled']));
                $group = $this->observe($connection, 'group:'.$groupId, $identity->digest([
                    $source->epoch, (string) $global->generation, (string) $media, (string) $rar,
                ]));

                $context = new RecoveryScanContext($groupId, $groupName, $source->epoch, (int) $group->generation,
                    $first, $last, $direction, (string) Str::uuid(), 0, $chunks);
                RecoveryScanWindow::record($connection, $context, $config);

                return $context;
            }, 1);
        } catch (\Throwable) {
            Log::warning('Recovery scan context is unavailable; coverage remains unknown.', ['group_id' => $groupId]);

            return null;
        } finally {
            RecoveryConnection::close($connection);
        }
    }

    /** @param ?list<int> $groups */
    public static function invalidate(?array $groups = null): void
    {
        if (! Schema::hasTable('obfuscation_recovery_controls')) {
            return;
        }
        $query = DB::table('obfuscation_recovery_controls');
        if ($groups === null) {
            $query->where(fn ($scope) => $scope->where('scope', 'global')->orWhere('scope', 'like', 'group:%'));
        } else {
            $query->whereIn('scope', array_map(static fn (int $id): string => 'group:'.$id, $groups));
        }
        $query->increment('generation', 1, ['updated_at' => now()]);
    }

    private function observe(Connection $connection, string $scope, string $fingerprint): object
    {
        $connection->table('obfuscation_recovery_controls')->upsert([
            'scope' => $scope, 'fingerprint' => $fingerprint, 'epoch' => (string) Str::uuid(), 'generation' => 1, 'updated_at' => now(),
        ], ['scope'], ['scope']);
        $row = $connection->table('obfuscation_recovery_controls')->where('scope', $scope)->lockForUpdate()->first();
        if ($row->fingerprint !== $fingerprint) {
            $row->fingerprint = $fingerprint;
            $row->epoch = (string) Str::uuid();
            $row->generation = (int) $row->generation + 1;
            $connection->table('obfuscation_recovery_controls')->where('scope', $scope)->update([
                'fingerprint' => $fingerprint, 'epoch' => $row->epoch, 'generation' => $row->generation, 'updated_at' => now(),
            ]);
        }

        return $row;
    }
}
