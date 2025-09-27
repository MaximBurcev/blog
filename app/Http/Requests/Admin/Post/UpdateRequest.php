<?php

namespace App\Http\Requests\Admin\Post;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
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
            'title'         => 'required|string',
            'content'       => 'required|string',
            'preview_image' => 'nullable|file',
            'main_image'    => 'nullable|file',
            'category_id'   => 'required|integer|exists:categories,id',
            'tag_ids'       => 'nullable|array',
            'tag_ids.*'     => 'nullable|integer|exists:tags,id',
            'code'          => 'required|string',
            'published'     =>  'boolean',
            'translate'     =>  'nullable',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'Укажите символьный код',
            'code.string'  =>  'Данные должны соответствовать строчному типу'
        ];
    }
}
