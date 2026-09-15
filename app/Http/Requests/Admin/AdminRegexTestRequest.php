<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class AdminRegexTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function isSubmitted(): bool
    {
        return $this->hasAny(['group', 'regex', 'limit', 'showlimit', 'querylimit']);
    }

    /** @return array<string, array<string|Closure>> */
    public function rules(): array
    {
        if (! $this->isSubmitted()) {
            return [];
        }

        return [
            'group' => ['bail', 'required', 'string', 'exists:usenet_groups,name'],
            'regex' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (@preg_match($value, '') === false) {
                    $fail('The regex could not be compiled: '.preg_last_error_msg());
                }
            }],
        ] + ($this->routeIs('admin.collection-regexes-test') ? [
            'limit' => ['sometimes', 'required', 'integer', 'between:1,1000'],
        ] : [
            'showlimit' => ['sometimes', 'required', 'integer', 'between:1,1000'],
            'querylimit' => ['sometimes', 'required', 'integer', 'between:1,500000'],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->url();
    }
}
