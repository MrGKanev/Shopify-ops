<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class AuditSlackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $store, public int $missing, public string $period, public string $mentions = '')
    {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return [SlackWebhookChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $prefix = $this->mentions === '' ? '' : implode(' ', array_map(fn (string $id): string => "<@{$id}>", explode(' ', $this->mentions))).' ';

        return (new SlackMessage)->text("{$prefix}{$this->store}: Run Audit found {$this->missing} missing orders ({$this->period}).");
    }
}
