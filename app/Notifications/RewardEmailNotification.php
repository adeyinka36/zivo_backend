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

        $businessName = $creator?->business_name ?: 'our partner business';
        $businessAddress = $creator?->address;

        $fromDate = $this->media->from_date
            ? $this->media->from_date->timezone(config('app.timezone'))->format('j M Y')
            : null;

        $toDate = $this->media->to_date
            ? $this->media->to_date->timezone(config('app.timezone'))->format('j M Y')
            : null;

        $claimLocation = $businessAddress
            ? $businessName . ', ' . $businessAddress
            : $businessName;

        return (new MailMessage)
            ->subject('🎁 You have a reward from ZI-VO')
            ->markdown('mail.reward', [
                'notifiableName' => $notifiable->name ?: 'there',
                'offer' => $offer,
                'code' => $this->code,
                'hasCode' => !empty($this->code),
                'fromDate' => $fromDate,
                'toDate' => $toDate,
                'claimLocation' => $claimLocation,
            ]);
    }
}

