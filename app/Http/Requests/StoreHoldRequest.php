<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1',
            'ttl_minutes' => 'sometimes|integer|min:1|max:60',
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Product ID is required.',
            'product_id.exists' => 'Product not found.',
            'qty.required' => 'Quantity is required.',
            'qty.min' => 'Quantity must be at least 1.',
            'ttl_minutes.max' => 'TTL minutes cannot exceed 60.',
        ];
    }
}
