<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePosterIdentityBlacklistRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole('Admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'preview_token' => ['required', 'string', 'max:4096'],
            'delete_releases' => ['sometimes', 'boolean'],
            'regex' => ['prohibited'],
            'groupname' => ['prohibited'],
            'description' => ['prohibited'],
            'status' => ['prohibited'],
            'optype' => ['prohibited'],
            'msgcol' => ['prohibited'],
        ];
    }
}
