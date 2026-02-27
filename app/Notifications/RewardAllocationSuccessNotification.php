<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RewardAllocationSuccessNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly float $rewardAmount
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $formattedAmount = '$' . number_format($this->rewardAmount, 2);

        return (new MailMessage)
            ->subject('🎁 You\'ve Won a Reward From  ZI-VO!')
            ->greeting('Congratulations ' . $notifiable->name . '!')
            ->line('We are excited to reward your attention.')
            ->line('**Reward Amount:** ' . $formattedAmount)
            ->line('Your reward has been sent via email. Please check your inbox for details on how to claim it.')
            ->line('Thank you for paying attention')
            ->salutation('Best regards, The ZI-VO Team');
    }
}
