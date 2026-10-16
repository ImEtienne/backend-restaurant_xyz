<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminLoginRequest;
use App\Http\Resources\AdminUserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdminSessionController extends Controller
{
    public function store(AdminLoginRequest $request): AdminUserResource
    {
        abort_unless($request->hasSession(), 419, 'Initialisez la session depuis le frontend autorise.');

        if (! Auth::guard('web')->attempt([...$request->validated(), 'is_admin' => true])) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants incorrects ou acces administrateur non autorise.',
            ]);
        }

        $request->session()->regenerate();

        return new AdminUserResource(Auth::guard('web')->user());
    }

    public function show(Request $request): AdminUserResource
    {
        return new AdminUserResource($request->user());
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
