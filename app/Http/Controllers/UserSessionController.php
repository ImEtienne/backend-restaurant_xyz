<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\AdminUserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class UserSessionController extends Controller
{
    public function store(LoginRequest $request): AdminUserResource
    {
        abort_unless($request->hasSession(), 419, 'Initialisez la session depuis le frontend autorise.');
        if (! Auth::guard('web')->attempt($request->validated())) {
            throw ValidationException::withMessages(['email' => 'Identifiants incorrects.']);
        }
        $user = Auth::guard('web')->user();
        if (! $user->is_admin && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(response()->json([
                'code' => 'ACCOUNT_PENDING_APPROVAL',
                'message' => "Votre profil n'est pas encore active. Un administrateur doit le valider. Merci de patienter avant de pouvoir utiliser les services.",
            ], 403));
        }
        $request->session()->regenerate();

        return new AdminUserResource(Auth::guard('web')->user());
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
