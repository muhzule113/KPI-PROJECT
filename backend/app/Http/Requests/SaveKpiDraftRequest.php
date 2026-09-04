<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveKpiDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'actual_decimal' => ['nullable', 'numeric'],
            'actual_json' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'row_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
