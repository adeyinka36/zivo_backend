<?php

namespace App\Notifications;

use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MediaUploadSuccessNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly Media $media
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
        $rewardAmount = '$' . number_format($this->media->reward / 100, 2);
        $mediaDescription = $this->media->description ?: 'Your media';

        return (new MailMessage)
            ->subject('🎉 Your Media Upload is Live!')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Great news! Your media has been successfully uploaded and is now live on Zivo.')
            ->line('**Media Details:**')
            ->line('• **Title:** ' . $mediaDescription)
            ->line('• **Reward:** ' . $rewardAmount)
            ->line('• **Rounds:** ' . $this->media->rounds)
            ->line('Your media is now available for viewers to watch and engage with.')
            ->line('**Next Steps:**')
            ->line('To track your upload and view its performance, please refresh the profile page and check the "My Uploads" section in your profile. You\'ll be able to see all your uploaded media and monitor their progress there.')
            ->line('Thank you for sharing your content with the Zivo community.')
            ->salutation('Best regards, The Zivo Team');
    }
}
