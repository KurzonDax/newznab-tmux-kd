<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string PREVIOUS = '/.*"(?P<match0>.+?)(\\.part\\d*|\\.rar)?(\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}").+?yEnc$/i';

    private const string UPDATED = '/.*"(?P<match0>.+?)(\\.part\\d*|\\.rar)?(\\.(?:avi|mkv|mp4|vob)(?:\\.part\\d+\\.rar|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})"|\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}").+?yEnc$/i';

    public function up(): void
    {
        $this->replaceStock(self::PREVIOUS, self::UPDATED);
    }

    public function down(): void
    {
        $this->replaceStock(self::UPDATED, self::PREVIOUS);
    }

    private function replaceStock(string $previous, string $updated): void
    {
        DB::transaction(function () use ($previous, $updated): void {
            if ($this->stockRow($previous)->lockForUpdate()->first() === null) {
                return;
            }

            if ($this->stockRow($previous)->update(['regex' => $updated]) === 1) {
                DB::afterCommit(static function (): void {
                    Cache::forever('collection_regexes_revision', bin2hex(random_bytes(16)));
                });
            }
        });
    }

    private function stockRow(string $regex): Builder
    {
        $query = DB::table('collection_regexes')->where('id', 461)
            ->where('status', 1)->where('ordinal', 10);

        // Stock ownership is byte-exact even with a case-insensitive DB collation.
        foreach (['group_regex' => '^alt\\.binaries\\.multimedia$', 'regex' => $regex] as $column => $value) {
            if (DB::getDriverName() === 'sqlite') {
                $query->whereRaw($column.' COLLATE BINARY = ?', [$value]);
            } else {
                $query->whereRaw('CAST('.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
            }
        }

        return $query;
    }
};
