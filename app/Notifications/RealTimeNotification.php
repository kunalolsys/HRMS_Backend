<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class RealTimeNotification extends Notification
{
    use Queueable;

    public string $title;
    public string $body;
    public ?string $actionUrl;

    public function __construct(string $title, string $body, ?string $actionUrl = null)
    {
        $this->title = $title;
        $this->body = $body;
        $this->actionUrl = $actionUrl;
    }

    public function via(object $notifiable): array
    {
        // Database me save karega aur Reverb WebSocket par live push karega
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'created_at' => now()->toISOString(),
        ]);
    }
}