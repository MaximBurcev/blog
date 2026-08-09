<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ?-> обязателен: у гостя auth()->user() === null, и обращение к ->role
        // дало бы 500 вместо 404 на защищённом маршруте.
        if (auth()->user()?->role !== UserRole::Admin) {
            abort(404);
        }

        return $next($request);
    }
}
