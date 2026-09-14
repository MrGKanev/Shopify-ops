<?php

namespace App\Notifications;

use App\Application\Exports\CsvExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // ponytail: 5,000-row cap keeps the attachment memory-safe; raise via config if a report legitimately needs more.
    private const int MAX_ATTACHMENT_ROWS = 5000;

    /**
     * @param  list<string>|null  $attachmentHeaders
     * @param  list<list<bool|float|int|string|null>>|null  $attachmentRows
     */
    public function __construct(public string $store, public string $tool, public int $rows, public ?string $start = null, public ?string $end = null, public ?array $attachmentHeaders = null, public ?array $attachmentRows = null)
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

        if ($this->start || $this->end) {
            $mail = $mail->line("Period: {$this->start} → {$this->end}");
        }

        if ($this->attachmentHeaders !== null && $this->attachmentRows !== [] && $this->attachmentRows !== null) {
            $rows = array_slice($this->attachmentRows, 0, self::MAX_ATTACHMENT_ROWS);
            $csv = new CsvExporter;
            $filename = $csv->safeFilename("{$this->tool}-{$this->start}-to-{$this->end}");
            $mail = $mail->attachData($csv->content($this->attachmentHeaders, $rows), $filename, ['mime' => 'text/csv']);
        }

        return $mail;
    }
}
