<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ReportDigestNotification extends QueuedNotification
{
    /** @param list<array{tool:string,rows:int,summary?:array<string,mixed>,snapshot_url?:string}> $sections */
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
            if ($section['tool'] === 'operational_digest' && isset($section['summary'])) {
                $summary = $section['summary'];
                $mail->line(__('Snapshot checked at :time (:timezone)', ['time' => $summary['checkedAt'], 'timezone' => $summary['timezone']]));
                $mail->line(__('Order window: :start → :end', ['start' => $summary['orderWindow'][0], 'end' => $summary['orderWindow'][1]]));
                foreach (['paid_pending' => 'Paid / Shopify pending', 'sync_findings' => 'Sync findings', 'sla_overdue' => 'Estimated SLA overdue', 'sla_due_soon' => 'Estimated SLA due within 24 hours', 'open_issues' => 'Unresolved issues'] as $key => $label) {
                    $count = $summary['counts'][$key] ?? null;
                    $partial = $key !== 'open_issues' && ($summary['coverage']['shopify'] !== 'complete' || ($key === 'sync_findings' && $summary['coverage']['shipstation'] !== 'complete'));
                    $mail->line(__(':label: :count', ['label' => __($label), 'count' => $count === null ? __('Unavailable') : (($partial ? '≥ ' : '').$count)]));
                }
                $mail->line(__('Shopify coverage: :shopify; ShipStation coverage: :shipstation', ['shopify' => __($summary['coverage']['shopify']), 'shipstation' => __($summary['coverage']['shipstation'])]));
                if (isset($summary['coverage']['older_orders'])) {
                    $mail->line(__($summary['coverage']['older_orders']));
                }
                foreach (['manifests', 'billing_refunds', 'handover'] as $key) {
                    $mail->line(__($summary['coverage'][$key]));
                }
                if (isset($section['snapshot_url'])) {
                    $mail->action(__('Open operational snapshot'), $section['snapshot_url']);
                }
            }
        }

        return $mail;
    }
}
