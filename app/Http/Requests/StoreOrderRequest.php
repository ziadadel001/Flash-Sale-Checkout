<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hold_id' => 'required|integer|exists:holds,id',
            'external_payment_id' => 'sometimes|string',
        ];
    }

    public function messages(): array
    {
        return [
            'hold_id.required' => 'Hold ID is required.',
            'hold_id.exists' => 'Hold not found.',
        ];
    }
}
