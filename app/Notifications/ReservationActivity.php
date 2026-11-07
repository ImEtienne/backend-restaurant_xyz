<?php

namespace App\Notifications;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Notifications\Notification;

class ReservationActivity extends Notification
{
    public function __construct(private Reservation $reservation, private string $activity) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public static function notifyAdmins(Reservation $reservation, string $activity): void
    {
        foreach (User::query()->where('is_admin', true)->lazyById() as $admin) {
            $admin->notify(new self($reservation, $activity));
        }
    }

    /** @return array{type: string, message: string, reservation_id: int, name: string, date: string, time: string, guests: int} */
    public function toArray(object $notifiable): array
    {
        $action = match ($this->activity) {
            'created' => 'Nouvelle reservation',
            'updated' => 'Reservation modifiee',
            'cancelled' => 'Reservation annulee',
        };

        return [
            'type' => 'reservation_'.$this->activity,
            'message' => $action.' : '.$this->reservation->name,
            'reservation_id' => $this->reservation->id,
            'name' => $this->reservation->name,
            'date' => $this->reservation->date,
            'time' => substr($this->reservation->time, 0, 5),
            'guests' => $this->reservation->guests,
        ];
    }
}
