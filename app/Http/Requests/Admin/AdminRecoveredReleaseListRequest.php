<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class AdminRecoveredReleaseListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'group' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'in:added-desc,added-asc,posted-desc,posted-asc'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
