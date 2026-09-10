<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $store, public string $tool, public int $rows, public ?string $start = null, public ?string $end = null)
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("{$this->store}: {$this->tool} found {$this->rows} rows")->line("{$this->tool} found {$this->rows} rows for {$this->store}.");

        return $this->start || $this->end ? $mail->line("Period: {$this->start} → {$this->end}") : $mail;
    }
}
