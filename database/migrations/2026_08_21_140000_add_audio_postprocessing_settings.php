<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site settings for the dedicated audio post-processing path.
 *
 * `saveaudiopreview` goes with them: it gated a Vorbis re-encode in the shared
 * pipeline that no longer exists, and the audio path is governed by the fetch
 * and clip settings below instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore($this->settings());
        DB::table('settings')->where('name', 'saveaudiopreview')->delete();
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('name', array_column($this->settings(), 'name'))->delete();
        DB::table('settings')->insertOrIgnore([['name' => 'saveaudiopreview', 'value' => '0']]);
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function settings(): array
    {
        return [
            ['name' => 'postthreadsaudio', 'value' => '1'],
            ['name' => 'audio_segments_to_download', 'value' => '12'],
            ['name' => 'audio_max_rar_parts', 'value' => '6'],
            ['name' => 'audio_preview_seconds', 'value' => '30'],
            ['name' => 'audio_preview_start_seconds', 'value' => '10'],
            ['name' => 'audio_spectrogram', 'value' => '1'],
        ];
    }
};
