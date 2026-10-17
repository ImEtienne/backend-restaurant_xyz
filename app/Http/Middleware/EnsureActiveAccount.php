<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_admin && ! $user->is_active) {
            return response()->json([
                'code' => 'ACCOUNT_PENDING_APPROVAL',
                'message' => "Votre profil n'est pas encore active. Un administrateur doit le valider. Merci de patienter avant de pouvoir utiliser les services.",
            ], 403);
        }

        return $next($request);
    }
}
