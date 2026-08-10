<?php

namespace App\Http\Requests\Post\Comment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'message' => 'required|string|max:2000',
            // Имя нужно только гостю — у авторизованного оно берётся из аккаунта.
            'name' => auth()->check() ? 'nullable|string|max:255' : 'required|string|max:255',
            // Honeypot: обычный посетитель это поле не видит и не заполняет
            // (скрыто в вёрстке), боты — заполняют. Не строгая валидация,
            // а просто "должно быть строкой" — само срабатывание проверяется
            // и тихо отсекается в контроллере, без показа ошибки боту.
            'website' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Введите текст комментария',
            'message.max' => 'Комментарий слишком длинный (максимум 2000 символов)',
            'name.required' => 'Введите имя',
        ];
    }

    /**
     * Форма комментариев внизу длинной статьи — обычный redirect()->back()
     * возвращает наверх страницы, и пользователь не видит ошибку, не долистав
     * вручную. Ведём назад сразу на #comments, как и при успешной отправке.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw (new ValidationException($validator))
            ->redirectTo(url()->previous().'#comments');
    }
}
