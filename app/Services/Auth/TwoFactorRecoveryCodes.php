<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\PasswordSecurity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TwoFactorRecoveryCodes
{
    /** @return list<string> */
    public function generate(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $security = PasswordSecurity::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $security?->google2fa_enable) {
                throw ValidationException::withMessages(['recovery_codes' => 'Enable two-factor authentication first.']);
            }
            $codes = array_map(static fn (): string => implode('-', str_split(bin2hex(random_bytes(16)), 8)), range(1, 10));
            $security->forceFill(['recovery_codes' => array_map(static fn (string $code): string => hash('sha256', $code), $codes)])->save();

            return $codes;
        });
    }

    public function consume(User $user, string $code): bool
    {
        if (! preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{8}){3}$/D', $code)) {
            return false;
        }

        return DB::transaction(function () use ($user, $code): bool {
            $security = PasswordSecurity::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $security?->google2fa_enable) {
                return false;
            }
            $codes = $security->recovery_codes ?? [];
            foreach ($codes as $index => $hash) {
                if (hash_equals($hash, hash('sha256', $code))) {
                    unset($codes[$index]);
                    $security->forceFill(['recovery_codes' => array_values($codes)])->save();

                    return true;
                }
            }

            return false;
        });
    }
}
