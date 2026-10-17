<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivateClientRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;

class AdminClientActivationController extends Controller
{
    public function __invoke(ActivateClientRequest $request, User $user): AdminUserResource
    {
        abort_if($user->is_admin, 422, 'Cette activation concerne uniquement les profils clients.');
        $user->is_active = $request->boolean('is_active');
        $user->save();

        return new AdminUserResource($user);
    }
}
