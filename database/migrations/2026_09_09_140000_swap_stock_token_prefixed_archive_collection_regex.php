<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string PREVIOUS = '/(.+)[\-_ ]{0,3}[\\(\\[]\\d+\\/(?P<match0>\\d+[\\)\\]][\-_ ]{0,3}("|#34;).+?)(\\.part\\d*|\\.rar)?(\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4})("|#34;)(.+?)yEnc$/i';

    private const string UPDATED = '~(?J)(?:^([A-Za-z0-9][A-Za-z0-9._-]{0,199}) +\\[[0-9]{1,5}/(?P<match0>[0-9]{1,5}\\] - "(?-i:\\1))(?:\\.part[0-9]{1,5}\\.rar|\\.7z\\.[0-9]{3}|\\.vol[0-9]{1,5}\\+[0-9]{1,5}\\.par2|\\.(?:rar|7z|par2|nfo|sfv|nzb))" yEnc$|(.+)[\-_ ]{0,3}[\\(\\[]\\d+\\/(?P<match0>\\d+[\\)\\]][\-_ ]{0,3}("|#34;).+?)(\\.part\\d*|\\.rar)?(\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4})("|#34;)(.+?)yEnc$)~i';

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
        $query = DB::table('collection_regexes')->where('id', 284)
            ->where('status', 1)->where('ordinal', 55);

        // Stock ownership is byte-exact even with a case-insensitive DB collation.
        foreach (['group_regex' => '^alt\\.binaries\\.erotica$', 'regex' => $regex] as $column => $value) {
            if (DB::getDriverName() === 'sqlite') {
                $query->whereRaw($column.' COLLATE BINARY = ?', [$value]);
            } else {
                $query->whereRaw('CAST('.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
            }
        }

        return $query;
    }
};
