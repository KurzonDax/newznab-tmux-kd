<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use Illuminate\Support\Facades\DB;

/**
 * Finds or adds the `companies`, `game_modes` and `player_perspectives` rows a console game's
 * IGDB details name, and gives the game's link rows for `console_companies`,
 * `console_game_modes` and `console_player_perspectives`. Rows are found or added before the
 * game's write transaction opens, so no transaction holds a new row another worker cannot see.
 * The unique keys make a lock unnecessary: an insert that loses a race is ignored and the
 * winner's row is read back.
 */
class ConsoleGameDetails
{
    public const int DEVELOPER = 0;

    public const int PUBLISHER = 1;

    private const int COMPANY_NAME_LENGTH = 255;

    private const int NAME_LENGTH = 120;

    /**
     * The link rows for the IGDB details buildConsoleData() returns, keyed by table, without
     * `consoleinfo_id`. A missing list gives no rows.
     *
     * @param  array<string, mixed>  $con
     * @return array{console_companies: list<array{companies_id: int, role: int, position: int}>, console_game_modes: list<array{game_modes_id: int, position: int}>, console_player_perspectives: list<array{player_perspectives_id: int, position: int}>}
     */
    public function linkRows(array $con): array
    {
        $companies = [];
        foreach ([self::DEVELOPER => 'developers', self::PUBLISHER => 'publishers'] as $role => $key) {
            $ids = $this->rowIds($con[$key] ?? null, fn (array $company): ?int => $this->companyId($company['igdb_id'] ?? null, $company['name'] ?? null));
            foreach ($ids as $position => $id) {
                $companies[] = ['companies_id' => $id, 'role' => $role, 'position' => $position];
            }
        }

        return [
            'console_companies' => $companies,
            'console_game_modes' => $this->lookupRows('game_modes', $con['game_modes'] ?? null),
            'console_player_perspectives' => $this->lookupRows('player_perspectives', $con['player_perspectives'] ?? null),
        ];
    }

    /**
     * @return list<array<string, int>>
     */
    private function lookupRows(string $table, mixed $entries): array
    {
        $ids = $this->rowIds($entries, fn (array $entry): ?int => $this->lookupId($table, $entry['igdb_id'] ?? null, $entry['name'] ?? null));

        $rows = [];
        foreach ($ids as $position => $id) {
            $rows[] = [$table.'_id' => $id, 'position' => $position];
        }

        return $rows;
    }

    /**
     * The row ids the entries resolve to, in order and each once; an entry that resolves to none
     * is left out.
     *
     * @param  callable(array<string, mixed>): ?int  $resolve
     * @return list<int>
     */
    private function rowIds(mixed $entries, callable $resolve): array
    {
        $ids = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $id = is_array($entry) ? $resolve($entry) : null;
            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A company is found by its IGDB id, which every company IGDB names has; an existing row
     * keeps its stored name.
     */
    private function companyId(mixed $igdbId, mixed $name): ?int
    {
        $igdbId = $this->igdbId($igdbId);
        $name = $this->name($name, self::COMPANY_NAME_LENGTH);
        if ($igdbId === null || $name === '') {
            return null;
        }

        $find = static fn (): mixed => DB::table('companies')->where('igdb_id', $igdbId)->value('id');
        $id = $find();
        if ($id === null) {
            DB::table('companies')->insertOrIgnore(['name' => $name, 'igdb_id' => $igdbId]);
            $id = $find();
        }

        return $id !== null ? (int) $id : null;
    }

    /**
     * A game mode or player perspective is found by its IGDB id when IGDB sends one, else by its
     * unique name; a row found by name without an IGDB id gets the id. An existing row keeps its
     * stored name, so a mode IGDB renames keeps its row.
     */
    private function lookupId(string $table, mixed $igdbId, mixed $name): ?int
    {
        $igdbId = $this->igdbId($igdbId);
        $name = $this->name($name, self::NAME_LENGTH);
        if ($name === '') {
            return null;
        }

        $byIgdbId = static fn (): mixed => $igdbId !== null ? DB::table($table)->where('igdb_id', $igdbId)->value('id') : null;
        $byName = static fn (): ?object => DB::table($table)->where('name', $name)->first(['id', 'igdb_id']);

        $id = $byIgdbId();
        if ($id !== null) {
            return (int) $id;
        }

        $row = $byName();
        if ($row !== null) {
            if ($igdbId !== null && $row->igdb_id === null) {
                DB::table($table)->where('id', $row->id)->whereNull('igdb_id')->update(['igdb_id' => $igdbId]);
            }

            return (int) $row->id;
        }

        DB::table($table)->insertOrIgnore(['name' => $name, 'igdb_id' => $igdbId]);
        $id = $byIgdbId() ?? $byName()?->id;

        return $id !== null ? (int) $id : null;
    }

    private function igdbId(mixed $igdbId): ?int
    {
        return is_numeric($igdbId) && (int) $igdbId > 0 ? (int) $igdbId : null;
    }

    private function name(mixed $name, int $length): string
    {
        return is_string($name) ? mb_substr(trim($name), 0, $length) : '';
    }
}
