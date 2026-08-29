<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const array UPDATED_REGEXES = [
        107 => '/^(?P<match0>[-a-zA-Z0-9 ]+) \\[\\d+(?P<match1>\\/\\d+)\\] - ".+?"[\-_\\s]{0,3}yEnc$/ui',
        111 => '/^"(?P<match0>.+?)(?:\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui',
        113 => '/^\\[\\d+\\/\\d+\\] - "(?P<match0>.+?)(?:\\.tar\\.zst(?:\\.vol\\d+\\+\\d+\\.par2|\\.par2)?|([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(?:\\d{1,3}\\.rev|\\.vol\\d+\\+\\d+\\.par2|\\.[A-Za-z0-9]{2,4})?)"[\-_\\s]{0,3}yEnc$/ui',
    ];

    /**
     * @var array<int, string>
     */
    private const array LEGACY_REGEXES = [
        107 => '/^[-a-zA-Z0-9 ]+ \\[\\d+(?P<match0>\\/\\d+\\] - "(.+?))([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(\\d{1,3}\\.rev"|\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}"|")[\-_\\s]{0,3}yEnc$/ui',
        111 => '/^"(?P<match0>.+?)([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(\\d{1,3}\\.rev"|\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}"|")[\-_\\s]{0,3}yEnc$/ui',
        113 => '/^\\[\\d+(?P<match0>\\/\\d+\\] - "(.+?))([\-_](proof|sample|thumbs?))*(\\.part\\d*(\\.rar)?|\\.rar|\\.7z)?(\\d{1,3}\\.rev"|\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4}"|")[\-_\\s]{0,3}yEnc$/ui',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->updateRegexes(self::UPDATED_REGEXES);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->updateRegexes(self::LEGACY_REGEXES);
    }

    /**
     * @param  array<int, string>  $regexes
     */
    private function updateRegexes(array $regexes): void
    {
        foreach ($regexes as $id => $regex) {
            DB::table('collection_regexes')
                ->where('id', $id)
                ->update(['regex' => $regex]);
        }
    }
};
