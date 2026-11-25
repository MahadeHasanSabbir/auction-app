<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * The everyday profile details. The email address and password are
     * changed on the security page, behind an emailed code.
     *
     * @return array<string, list<File|In|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            // Checked by content, not by file extension; re-encoded on save.
            'avatar' => ['nullable', File::image()->max(4 * 1024)],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }
}
