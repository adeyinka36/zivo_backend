<?php

namespace App\Notifications;

use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RewardEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Media $media,
        private readonly ?string $code = null
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $creator = $this->media->creator;
        $offer = $this->media->offer ?: 'your reward';

        $fromDate = $this->media->from_date
            ? $this->media->from_date->timezone(config('app.timezone'))->format('j M Y')
            : null;

        $toDate = $this->media->to_date
            ? $this->media->to_date->timezone(config('app.timezone'))->format('j M Y')
            : null;

        $validityLine = $fromDate && $toDate
            ? "• **Valid:** {$fromDate} — {$toDate}"
            : null;

        $businessName = $creator?->business_name ?: 'our partner business';
        $businessAddress = $creator?->address;

        $claimLine = '• **Where to claim:** ';
        if ($businessAddress) {
            $claimLine .= $businessName . ', ' . $businessAddress;
        } else {
            $claimLine .= $businessName;
        }

        $message = (new MailMessage)
            ->subject('🎁 You have a reward from ZI-VO')
            ->greeting('Hi ' . ($notifiable->name ?: 'there') . ',')
            ->line('Great news – you paid attention and have unlocked earned reward on ZI-VO.')
            ->line('**Offer Details:**')
            ->line('• **Offer:** ' . $offer);

        if ($this->code) {
            $message->line('• **Your code:** ' . $this->code);
        }

        if ($validityLine) {
            $message->line($validityLine);
        }

        $message
            ->line($claimLine)
            ->line('When you visit, simply show this email to the business so they can validate and apply your offer.')
            ->line('This offer is personal to you and linked to your ZI-VO account. Please keep this email safe until you have redeemed it.')
            ->line('Thank you for paying attention!')
            ->salutation('Best regards,' . PHP_EOL . 'ZI-VO');

        return $message;
    }
}

