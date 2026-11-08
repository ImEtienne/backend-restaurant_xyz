<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class AdminNotificationDispatcher
{
    public static function send(Notification $notification): void
    {
        foreach (User::query()->where('is_admin', true)->lazyById() as $admin) {
            $admin->notify(clone $notification);
        }
    }
}
