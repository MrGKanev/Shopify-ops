<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ReportDigestNotification extends QueuedNotification
{
    /** @param list<array{tool:string,rows:int}> $sections */
    public function __construct(public string $store, public array $sections)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__(':store: Daily report digest', ['store' => $this->store]))
            ->line(__('Daily report digest for :store:', ['store' => $this->store]));
        foreach ($this->sections as $section) {
            $mail->line(__(':tool: :rows rows', ['tool' => $section['tool'], 'rows' => $section['rows']]));
        }

        return $mail;
    }
}
