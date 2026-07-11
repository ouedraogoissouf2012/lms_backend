<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CustomNotification extends Notification
{
    use Queueable;

    protected string $title;

    protected string $message;

    protected string $type;

    protected ?string $actionUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $title, string $message, string $type = 'info', ?string $actionUrl = null)
    {
        $this->title = $title;
        $this->message = $message;
        $this->type = $type;
        $this->actionUrl = $actionUrl;
    }

    /**
     * Get the notification's delivery channels.
     */
    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    /**
     * @return array{title: string, message: string, type: string, action_url: string|null, icon: string}
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'action_url' => $this->actionUrl,
            'icon' => $this->getIcon($this->type),
        ];
    }

    /**
     * Get icon based on type
     */
    protected function getIcon(string $type): string
    {
        $icons = [
            'info' => 'InformationCircleIcon',
            'success' => 'CheckCircleIcon',
            'warning' => 'ExclamationCircleIcon',
            'danger' => 'XCircleIcon',
        ];

        return $icons[$type] ?? 'BellIcon';
    }
}
