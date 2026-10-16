<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminUserResource::collection(User::query()->orderByDesc('id')->paginate(20));
    }

    public function show(User $user): AdminUserResource
    {
        return new AdminUserResource($user);
    }

    public function store(StoreUserRequest $request): AdminUserResource
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->is_admin = $request->boolean('is_admin');
        $user->save();

        return new AdminUserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): AdminUserResource
    {
        $updatedUser = DB::transaction(function () use ($request, $user): User {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get(['id']);
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($target->is_admin && $request->has('is_admin') && ! $request->boolean('is_admin') && $admins->count() <= 1,
                409, 'Le dernier administrateur ne peut pas etre retrograde.');
            $target->fill($request->safe()->only(['name', 'email', 'password']));
            if ($request->has('is_admin')) {
                $target->is_admin = $request->boolean('is_admin');
            }
            if ($target->isDirty('email')) {
                $target->email_verified_at = null;
            }
            $target->save();

            return $target;
        }, 3);

        return new AdminUserResource($updatedUser);
    }

    public function destroy(Request $request, User $user): Response
    {
        abort_if($request->user()->is($user), 409, 'Vous ne pouvez pas supprimer votre propre compte depuis la gestion des utilisateurs.');
        DB::transaction(function () use ($user): void {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get(['id']);
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($target->is_admin && $admins->count() <= 1, 409, 'Le dernier administrateur ne peut pas etre supprime.');
            $target->delete();
        }, 3);

        return response()->noContent();
    }
}
