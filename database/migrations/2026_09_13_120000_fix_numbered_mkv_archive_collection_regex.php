<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string PREVIOUS = '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.(?:bin|mkv)(?:\\.vol\\d+\\+\\d+)?\\.par2|\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui';

    private const string UPDATED = '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.mkv\\.part\\d+\\.rar|\\.(?:bin|mkv)(?:\\.vol\\d+\\+\\d+)?\\.par2|\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui';

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
        $query = DB::table('collection_regexes')->where('id', 113)
            ->where('status', 1)->where('ordinal', 90);

        // Stock ownership is byte-exact even with a case-insensitive DB collation.
        foreach (['group_regex' => '^alt\\.binaries\\.boneless$', 'regex' => $regex] as $column => $value) {
            if (DB::getDriverName() === 'sqlite') {
                $query->whereRaw($column.' COLLATE BINARY = ?', [$value]);
            } else {
                $query->whereRaw('CAST('.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
            }
        }

        return $query;
    }
};
