<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\AdminUserResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): AdminUserResource
    {
        return new AdminUserResource($request->user());
    }

    public function update(UpdateProfileRequest $request): AdminUserResource
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email', 'password']));
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        return new AdminUserResource($user);
    }
}
