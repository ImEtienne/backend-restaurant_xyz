<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class ClientRegistrationPending extends Notification
{
    public function __construct(private User $client) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{type: string, message: string, user_id: int, name: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'client_registered',
            'message' => 'Compte client en attente de validation : '.$this->client->name,
            'user_id' => $this->client->id,
            'name' => $this->client->name,
        ];
    }
}
