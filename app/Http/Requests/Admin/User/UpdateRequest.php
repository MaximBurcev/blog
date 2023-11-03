<?php

namespace App\Http\Requests\Admin\User;

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
            'name' =>  'required|string',
            'email' =>  'required|email|unique:users,email,' . $this->user_id,
            'user_id'   => 'required|integer|exists:users,id',
            'role'  =>  'required|integer',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'Укажите имя пользователя',
            'email'         => 'Укажите email пользователя',
            'email.unique'  => 'Email не уникален'
        ];
    }
}
