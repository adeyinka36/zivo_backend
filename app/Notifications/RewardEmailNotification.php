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

        $primaryLocation = $this->media->locations()->first();

        $businessName = $creator?->business_name ?: 'our partner business';
        $businessAddress = $creator?->address;

        $locationDescription = null;
        if ($primaryLocation) {
            $parts = array_filter([
                $primaryLocation->region_name,
                $primaryLocation->country,
            ]);
            $locationDescription = implode(', ', $parts);
        }

        $claimLine = '• **Where to claim:** ';
        if ($businessAddress && $locationDescription) {
            $claimLine .= $businessName . ', ' . $businessAddress . ' (' . $locationDescription . ')';
        } elseif ($businessAddress) {
            $claimLine .= $businessName . ', ' . $businessAddress;
        } elseif ($locationDescription) {
            $claimLine .= $businessName . ' in ' . $locationDescription;
        } else {
            $claimLine .= $businessName;
        }

        $message = (new MailMessage)
            ->subject('🎁 You unlocked an offer on ZI-VO')
            ->greeting('Hi ' . ($notifiable->name ?: 'there') . ',')
            ->line('Great news – you answered your quiz correctly and have unlocked an exclusive offer from one of our business partners on ZI-VO.')
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
            ->line('Thank you for paying attention and discovering local businesses with ZI-VO.')
            ->salutation('Best regards,' . PHP_EOL . 'The ZI-VO Team');

        return $message;
    }
}

