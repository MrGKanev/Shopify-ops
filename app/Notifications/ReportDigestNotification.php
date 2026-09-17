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
        $mail = (new MailMessage)->subject("{$this->store}: daily report digest")->line("Daily report digest for {$this->store}:");
        foreach ($this->sections as $section) {
            $mail->line("{$section['tool']}: {$section['rows']} rows");
        }

        return $mail;
    }
}
