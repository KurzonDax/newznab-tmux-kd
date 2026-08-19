<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BlacklistConstants;
use App\Models\BinaryBlacklist;
use App\Models\Release;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class PosterIdentityBlacklistService
{
    public function __construct(
        private readonly BlacklistService $blacklists,
    ) {}

    /**
     * @param  list<string>  $observedGroups
     * @return array{regex:string, groupname:string, description:string}
     */
    private function previewForGroups(string $posterIdentity, string $adminUsername, array $observedGroups): array
    {
        $groups = array_map(
            static fn (string $group): string => preg_quote($group, '/'),
            $observedGroups,
        );

        return [
            'regex' => '^'.preg_quote($posterIdentity, '/').'$',
            'groupname' => '^(?:'.implode('|', $groups).')$',
            'description' => 'Poster identity blocked from poster page by '.$adminUsername,
        ];
    }

    /**
     * @return array{preview:array{regex:string, groupname:string, description:string}, token:string}
     */
    public function previewForConfirmation(string $posterIdentity, string $adminUsername): array
    {
        $groups = $this->observedGroups($posterIdentity);
        $preview = $this->previewForGroups($posterIdentity, $adminUsername, $groups);
        $payload = [
            'poster_identity' => $posterIdentity,
            'admin_username' => $adminUsername,
            'groups' => $groups,
            'preview' => $preview,
        ];

        return [
            'preview' => $preview,
            'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @throws LockTimeoutException
     */
    public function createOrEnable(string $posterIdentity, string $adminUsername, string $previewToken): BinaryBlacklist
    {
        $payload = $this->validatedPreviewPayload($previewToken, $posterIdentity, $adminUsername);
        $preview = $payload['preview'];
        $groups = $payload['groups'];

        return Cache::lock('poster-identity-blacklist:'.sha1($preview['regex']), 15)
            ->block(10, fn (): BinaryBlacklist => DB::transaction(function () use ($posterIdentity, $preview, $groups): BinaryBlacklist {
                $matchingRule = $this->matchingRuleForGroups($posterIdentity, $groups);
                if ($matchingRule !== null) {
                    return $matchingRule;
                }

                $disabledGeneratedRule = BinaryBlacklist::query()
                    ->where('regex', $preview['regex'])
                    ->where('msgcol', BlacklistConstants::BLACKLIST_FIELD_FROM)
                    ->where('optype', BlacklistConstants::OPTYPE_BLACKLIST)
                    ->where('status', '!=', BlacklistConstants::BLACKLIST_ENABLED)
                    ->where('description', 'like', 'Poster identity blocked from poster page by %')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($disabledGeneratedRule !== null) {
                    $disabledGeneratedRule->status = BlacklistConstants::BLACKLIST_ENABLED;
                    $disabledGeneratedRule->groupname = $preview['groupname'];
                    $disabledGeneratedRule->description = $preview['description'];
                    $disabledGeneratedRule->save();

                    return $disabledGeneratedRule;
                }

                return BinaryBlacklist::query()->create([
                    'groupname' => $preview['groupname'],
                    'regex' => $preview['regex'],
                    'description' => $preview['description'],
                    'status' => BlacklistConstants::BLACKLIST_ENABLED,
                    'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
                    'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
                ]);
            }));
    }

    public function matchingRule(string $posterIdentity): ?BinaryBlacklist
    {
        $groups = $this->observedGroups($posterIdentity);

        return $this->matchingRuleForGroups($posterIdentity, $groups);
    }

    /**
     * @param  list<string>  $groups
     */
    private function matchingRuleForGroups(string $posterIdentity, array $groups): ?BinaryBlacklist
    {
        if ($groups === []) {
            return null;
        }

        foreach ($groups as $group) {
            $rules = $this->blacklists->getBlacklist(
                true,
                BlacklistConstants::OPTYPE_BLACKLIST,
                $group,
                true,
            );

            foreach ($rules as $rule) {
                if ((int) $rule->msgcol !== BlacklistConstants::BLACKLIST_FIELD_FROM
                    || ! $this->matches((string) $rule->regex, $posterIdentity)) {
                    continue;
                }

                $matchingRule = BinaryBlacklist::query()->find((int) $rule->id);
                if ($matchingRule !== null) {
                    return $matchingRule;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function observedGroups(string $posterIdentity): array
    {
        return Release::query()
            ->from('releases as r')
            ->join('usenet_groups as g', 'g.id', '=', 'r.groups_id')
            ->where('r.fromname', $posterIdentity)
            ->when(
                in_array(DB::getDriverName(), ['mysql', 'mariadb'], true),
                static fn ($query) => $query->whereRaw('BINARY r.fromname = BINARY ?', [$posterIdentity]),
            )
            ->whereNotNull('g.name')
            ->distinct()
            ->orderBy('g.name')
            ->pluck('g.name')
            ->map(static fn (mixed $group): string => (string) $group)
            ->values()
            ->all();
    }

    private function matches(string $regex, string $value): bool
    {
        return @preg_match('/'.$regex.'/i', $value) === 1;
    }

    /**
     * @return array{groups:list<string>, preview:array{regex:string, groupname:string, description:string}}
     */
    private function validatedPreviewPayload(
        string $previewToken,
        string $posterIdentity,
        string $adminUsername,
    ): array {
        try {
            $payload = json_decode(Crypt::decryptString($previewToken), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->invalidPreviewToken();
        }

        if (! is_array($payload)
            || ($payload['poster_identity'] ?? null) !== $posterIdentity
            || ($payload['admin_username'] ?? null) !== $adminUsername
            || ! is_array($payload['groups'] ?? null)
            || ! is_array($payload['preview'] ?? null)
            || array_filter($payload['groups'], static fn (mixed $group): bool => ! is_string($group)) !== []
        ) {
            $this->invalidPreviewToken();
        }

        /** @var list<string> $groups */
        $groups = array_values($payload['groups']);
        $expectedPreview = $this->previewForGroups($posterIdentity, $adminUsername, $groups);

        if ($payload['preview'] !== $expectedPreview) {
            $this->invalidPreviewToken();
        }

        return [
            'groups' => $groups,
            'preview' => $expectedPreview,
        ];
    }

    private function invalidPreviewToken(): never
    {
        throw ValidationException::withMessages([
            'preview_token' => 'The blacklist confirmation has expired or is invalid.',
        ]);
    }
}
