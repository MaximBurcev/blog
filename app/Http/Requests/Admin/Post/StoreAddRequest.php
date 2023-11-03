<?php

namespace App\Http\Requests\Admin\Post;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'url'      => 'required|url',
            'selector' => 'required|string',
            'tag_ids'  => 'array',
        ];
    }

    public function messages()
    {
        return [
            'url.required' => 'Укажите URL',
            'url.url'      => 'Укажите URL',
            'selector'     => 'Укажите селектор'
        ];
    }
}
