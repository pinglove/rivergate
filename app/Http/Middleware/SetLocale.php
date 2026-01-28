<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale', 'en');
        //\Log::debug('SET LOCALE MIDDLEWARE', [
        //'path' => $request->path(),
        //'session_locale' => session('locale'),
        //'session_id' => session()->getId(),
        //]);
        

        app()->setLocale($locale);

        return $next($request);
    }
}
