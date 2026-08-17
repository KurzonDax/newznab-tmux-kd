<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Backup\BackupLocationValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBackupSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'backup_enabled' => ['required', 'boolean'],
            'backup_full_dow' => ['required', 'integer', 'between:0,6'],
            'backup_full_time' => ['required', 'date_format:H:i'],
            'backup_daily_time' => ['required', 'date_format:H:i'],
            'backup_location' => ['required', 'string', 'max:2000'],
            'backup_keep_fulls' => ['required', 'integer', 'min:1', 'max:100'],
            'backup_pause_tmux' => ['required', 'boolean'],
            'backup_incl_working' => ['required', 'boolean'],
            'backup_dump_binary' => ['nullable', 'string', 'max:2000'],
            'backup_offsite_path' => ['nullable', 'string', 'max:2000'],
            'backup_offsite_after' => ['required', 'boolean'],
            'backup_offsite_keep' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    app(BackupLocationValidator::class)->validate((string) $this->input('backup_location'));
                } catch (\Throwable $e) {
                    $validator->errors()->add('backup_location', $e->getMessage());
                }

                $binary = trim((string) $this->input('backup_dump_binary'));
                if ($binary !== '' && (! str_starts_with($binary, DIRECTORY_SEPARATOR) || ! is_executable($binary))) {
                    $validator->errors()->add('backup_dump_binary', 'Dump binary must be an absolute path to an executable file.');
                }

                $destination = trim((string) $this->input('backup_offsite_path'));
                if ($destination !== '' && ! str_starts_with($destination, DIRECTORY_SEPARATOR)) {
                    $validator->errors()->add('backup_offsite_path', 'Off-site destination must be an absolute path.');
                }

                if ($destination !== '' && is_dir($destination) && ! is_writable($destination)) {
                    $validator->errors()->add('backup_offsite_path', 'Off-site destination must be writable when it is mounted.');
                }
            },
        ];
    }
}
