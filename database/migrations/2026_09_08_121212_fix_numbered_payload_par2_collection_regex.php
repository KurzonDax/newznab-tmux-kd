<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string PREVIOUS = '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui';

    private const string UPDATED = '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.(?:bin|mkv)(?:\\.vol\\d+\\+\\d+)?\\.par2|\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui';

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
        DB::table('collection_regexes')->where('id', 113)
            ->where('group_regex', '^alt\\.binaries\\.boneless$')
            ->where('regex', $previous)->where('status', 1)->where('ordinal', 90)
            ->update(['regex' => $updated]);
        Cache::forever('collection_regexes_revision', bin2hex(random_bytes(16)));
    }
};
