<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'required|regex:/^[a-zA-Z0-9_]+$/',
            'password' => 'required',
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => __('ui.username_regex'),
        ];
    }
}
