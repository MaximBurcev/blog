<?php

namespace App\Http\Requests\Admin\Post;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
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
            'title' => 'required|string',
            'content' => 'required|string',
            'preview_image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
            'main_image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
            'category_id' => 'required|integer|exists:categories,id',
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'nullable|integer|exists:tags,id',
            'translate' => 'nullable',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => 'Укажите название поста',
            'title.string' => 'Данные должны соответствовать строчному типу',
        ];
    }
}
