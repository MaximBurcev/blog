<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;

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
}
