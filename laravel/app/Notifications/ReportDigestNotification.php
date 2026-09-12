<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{tool:string,rows:int}> $sections */
    public function __construct(public string $store, public array $sections)
    {
        $this->onQueue('notifications');
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
