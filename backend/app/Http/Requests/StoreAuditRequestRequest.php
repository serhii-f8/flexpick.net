<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAuditRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'repo_url' => ['nullable', 'url', 'max:2048'],
            'message' => ['nullable', 'string', 'max:2000'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'website' => ['prohibited'], // honeypot — humans never fill it
        ];
    }
}
