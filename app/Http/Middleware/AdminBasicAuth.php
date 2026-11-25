<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mbron admin UI me Basic Auth, me user/password nga config/sheets-sync.php.
 *
 * Pa password te konfiguruar, UI-ja mbetet e mbyllur (pervec local/testing).
 */
class AdminBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('sheets-sync.admin.user');
        $password = (string) config('sheets-sync.admin.password');

        if ($password === '') {
            abort_unless(
                app()->environment(['local', 'testing']),
                403,
                'The admin UI is disabled: set SHEETS_ADMIN_PASSWORD in .env.'
            );

            return $next($request);
        }

        $userMatches = hash_equals($user, (string) $request->getUser());
        $passwordMatches = hash_equals($password, (string) $request->getPassword());

        if ($userMatches && $passwordMatches) {
            return $next($request);
        }

        return response('Authentication required.', 401, [
            'WWW-Authenticate' => 'Basic realm="Sheets Sync", charset="UTF-8"',
        ]);
    }
    
}