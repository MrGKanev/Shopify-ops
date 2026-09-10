<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class ScanSlackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $store, public string $tool, public int $rows, public string $mentions = '')
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

        return (new SlackMessage)->text("{$prefix}{$this->store}: {$this->tool} found {$this->rows} rows.");
    }
}
