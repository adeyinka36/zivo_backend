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
        private readonly Media $media,
        private readonly string $reason = 'completed'
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
        $mediaName = $this->media->name ?: 'Your media';
        $mediaDescription = $this->media->offer ?: 'No description provided';

        $mailMessage = (new MailMessage)
            ->greeting('Hello ' . $notifiable->name . ',');

        if ($this->reason === 'target_reached') {
            $mailMessage->subject('🎯 Campaign Target Reached!')
                ->line('Great news! Your media campaign has reached its target views.')
                ->line('**Campaign Summary:**')
                ->line('• **Media Name:** ' . $mediaName)
                ->line('• **Offer:** ' . $mediaDescription)
                ->line('• **Total Rounds:** ' . $this->media->rounds)
                ->line('• **Total Views:** ' . number_format($totalViews))
                ->line('Your campaign has successfully reached its target! The campaign will continue running until all rounds are completed or the campaign period ends.')
                ->line('You can view your campaign progress in your dashboard.');
        } elseif ($this->reason === 'expired') {
            $mailMessage->subject('⏰ Campaign Period Ended')
                ->line('Your media campaign period has ended.')
                ->line('**Campaign Summary:**')
                ->line('• **Media Name:** ' . $mediaName)
                ->line('• **Offer:** ' . $mediaDescription)
                ->line('• **Total Rounds:** ' . $this->media->rounds)
                ->line('• **Total Views:** ' . number_format($totalViews))
                ->line('Your campaign has reached the end of its validity period. The campaign will be removed from the system after 24 hours.')
                ->line('Thank you for using ZI-VO!');
        } else {
            $mailMessage->subject('🎉 Campaign Completed Successfully!')
                ->line('Congratulations! Your media campaign has completed successfully.')
                ->line('**Campaign Summary:**')
                ->line('• **Media Name:** ' . $mediaName)
                ->line('• **Offer:** ' . $mediaDescription)
                ->line('• **Total Rounds:** ' . $this->media->rounds)
                ->line('• **Total Views:** ' . number_format($totalViews))
                ->line('Your campaign has reached its target and all rounds have been completed. Thank you for sharing your content with the ZI-VO community!')
                ->line('You can view your completed campaigns in your dashboard.');
        }

        return $mailMessage->salutation('Best regards, The ZI-VO Team');
    }
}
