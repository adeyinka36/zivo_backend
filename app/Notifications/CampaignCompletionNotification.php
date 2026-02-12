<?php

namespace App\Notifications;

use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignCompletionNotification extends Notification
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
        $targetViews = (int) config('services.media.target_views', 25);
        $totalViews = $this->media->rounds * $targetViews;
        $rewardAmount = '$' . number_format($this->media->reward / 100, 2);
        $mediaName = $this->media->name ?: 'Your media';
        $mediaDescription = $this->media->description ?: 'No description provided';

        return (new MailMessage)
            ->subject('🎉 Campaign Completed Successfully!')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Congratulations! Your media campaign has completed successfully.')
            ->line('**Campaign Summary:**')
            ->line('• **Media Name:** ' . $mediaName)
            ->line('• **Description:** ' . $mediaDescription)
            ->line('• **Total Rounds:** ' . $this->media->rounds)
            ->line('• **Total Views:** ' . number_format($totalViews))
            ->line('• **Reward Amount:** ' . $rewardAmount)
            ->line('Your campaign has reached its target and all rounds have been completed. Thank you for sharing your content with the Zivo community!')
            ->line('You can view your completed campaigns in your profile section under "My Uploads".')
            ->salutation('Best regards, The Zivo Team');
    }
}
