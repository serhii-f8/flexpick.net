<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CardknoxCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // iFields single-use tokens, not card data.
            'xCardNum' => ['required', 'string', 'max:4096'],
            'xCVV' => ['required', 'string', 'max:4096'],
            'exp_month' => ['required', 'digits:2'],
            'exp_year' => ['required', 'digits:2'],
            'save_card' => ['nullable', 'boolean'],
        ];
    }

    /** MMYY, the format Cardknox expects in xExp. */
    public function exp(): string
    {
        return $this->string('exp_month')->toString().$this->string('exp_year')->toString();
    }
}
