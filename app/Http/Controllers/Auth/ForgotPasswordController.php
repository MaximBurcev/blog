<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    public function __construct()
    {
        // config/auth.php passwords.throttle ограничивает только повторную
        // отправку на ОДИН адрес. Без лимита по IP скрипт рассылал письма
        // сброса на произвольные чужие ящики через нашу инфраструктуру —
        // абьюз чужой почты и риск блокировки домена у SMTP-провайдера.
        $this->middleware('throttle:auth-sensitive')->only('sendResetLinkEmail');
    }

    /**
     * Отвечаем одинаково независимо от того, есть такой адрес в базе или нет.
     *
     * По умолчанию форма отдавала «We can't find a user with that email
     * address» для незарегистрированного и «We have emailed your password
     * reset link!» для существующего — то есть работала оракулом
     * существования аккаунта (CWE-204). Троттлинг поднимает стоимость
     * перебора, но не убирает саму разницу в ответе.
     *
     * INVALID_USER подменяем на успех; остальные причины отказа (например
     * THROTTLED) остаются как были — они не завязаны на существование
     * пользователя и нужны в интерфейсе.
     */
    protected function sendResetLinkFailedResponse(Request $request, $response)
    {
        if ($response === Password::INVALID_USER) {
            return $this->sendResetLinkResponse($request, Password::RESET_LINK_SENT);
        }

        return parent::sendResetLinkFailedResponse($request, $response);
    }
}
