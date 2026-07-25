<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSuspended extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $reason,
        public ?\DateTimeInterface $suspendUntil = null
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->error()
            ->subject('Your Account Has Been Suspended')
            ->greeting('Account Suspended')
            ->line('Your account has been suspended by an administrator.')
            ->line('Reason: '.$this->reason);

        if ($this->suspendUntil) {
            $message->line('Suspension expires: '.$this->suspendUntil->format('F j, Y \a\t g:i A'));
        } else {
            $message->line('This is a permanent suspension.');
        }

        $message->line('If you believe this is a mistake, please contact support.');

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reason' => $this->reason,
            'suspend_until' => $this->suspendUntil?->format('Y-m-d\TH:i:sP'),
        ];
    }
}
