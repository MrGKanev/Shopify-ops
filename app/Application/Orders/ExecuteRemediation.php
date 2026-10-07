<?php

namespace App\Application\Orders;

use App\Application\Operations\RaiseOperationalIssue;
use App\Domain\Orders\RemediationUnavailable;
use App\Domain\Orders\ShipStationRelevantFingerprint;
use App\Integrations\Shopify\ShopifyMutations;
use App\Models\RemediationRun;

class ExecuteRemediation
{
    public function __construct(private readonly BuildRemediationPlan $plans, private readonly LoadOrderForRemediation $orders, private readonly ShopifyMutations $mutations, private readonly RecordPush $pushes, private readonly RaiseOperationalIssue $issues) {}

    public function handle(RemediationRun $run): void
    {
        if ($run->expires_at->isPast()) {
            throw new RemediationUnavailable('The preview expired. Review the current order again.');
        }
        $approved = $run->plan;
        $plan = $this->plans->preview($run->store, $run->order_number, $run->action, $approved['options']);
        if (! hash_equals($approved['fingerprint'], $plan['fingerprint'])) {
            throw new RemediationUnavailable('Order data changed since the preview. Review it again before applying changes.');
        }
        $client = null;
        foreach ($plan['steps'] as $index => $step) {
            activity('operator-actions')->causedBy($run->user)->performedOn($run)
                ->withProperties(['store_id' => $run->store_id, 'action' => $run->action, 'step' => $index + 1, 'type' => $step['type']])->log('remediation_step_started');
            if ($step['type'] === 'shopify') {
                $result = $this->mutations->handle($run->store, $step['operation'], $step['field'], $step['variables']);
                $resource = match ($step['field']) {
                    'tagsAdd', 'tagsRemove' => 'node',
                    'fulfillmentOrderHold', 'fulfillmentOrderReleaseHold' => 'fulfillmentOrder',
                    default => 'fulfillment',
                };
                $actualId = $result[$resource]['id'] ?? null;
                if (! is_string($actualId) || $actualId === '' || (isset($step['variables']['id']) && $actualId !== $step['variables']['id'])) {
                    throw new RemediationUnavailable('Shopify did not confirm the requested change. Review the remote state before retrying.');
                }
            } else {
                $client ??= $this->orders->client($run->store);
                switch ($step['type']) {
                    case 'ss_update':
                    case 'ss_create':
                        $result = $client->updateOrder($step['payload']);
                        if (empty($result['orderId']) || ($step['type'] === 'ss_update' && (string) $result['orderId'] !== (string) $step['payload']['orderId'])) {
                            throw new RemediationUnavailable('ShipStation did not confirm the expected order identity. Review the remote state before retrying.');
                        }
                        $actual = $client->getOrder((int) $result['orderId']);
                        if (($actual['orderKey'] ?? '') !== $step['payload']['orderKey'] || ($actual['orderStatus'] ?? '') !== $step['payload']['orderStatus']) {
                            throw new RemediationUnavailable('ShipStation order identity or status differs after the update.');
                        }
                        if ($run->action !== 'cancel_shipstation' && ShipStationRelevantFingerprint::diff($step['payload'], $actual) !== []) {
                            throw new RemediationUnavailable('ShipStation shipping data differs after the update.');
                        }
                        if ($run->action === 'push_missing') {
                            $this->pushes->handle($run->store, $run->order_number, $run->shopify_id, $result['orderId'], $step['payload']);
                        }
                        break;
                    case 'ss_hold':
                        $client->holdOrder($step['order_id'], $step['hold_until']);
                        if (($client->getOrder($step['order_id'])['orderStatus'] ?? '') !== 'on_hold') {
                            throw new RemediationUnavailable('ShipStation did not confirm the hold.');
                        }
                        break;
                    case 'ss_restore':
                        $client->restoreOrder($step['order_id']);
                        if (($client->getOrder($step['order_id'])['orderStatus'] ?? '') !== 'awaiting_shipment') {
                            throw new RemediationUnavailable('ShipStation did not confirm the hold release.');
                        }
                        break;
                    case 'ss_tag':
                        $client->addOrderTag($step['order_id'], $step['tag_id']);
                        if (! in_array($step['tag_id'], $client->getOrder($step['order_id'])['tagIds'] ?? [], true)) {
                            throw new RemediationUnavailable('ShipStation did not confirm the tag.');
                        }
                        break;
                    case 'ss_refresh':
                        $client->refreshStore($step['store_id']);
                        break;
                    default:
                        throw new RemediationUnavailable('Unknown remediation step.');
                }
            }
            $run->update(['completed_steps' => $index + 1]);
        }
        if (in_array($run->action, ['sync_shipstation', 'push_missing'], true)) {
            $source = $run->action === 'sync_shipstation' ? 'order_changed_after_push' : 'run_audit';
            $reference = $source === 'run_audit' ? $run->order_number : $run->shopify_id;
            $issue = $run->store->operationalIssues()->where('fingerprint', RaiseOperationalIssue::fingerprint($source, $reference))->first();
            if ($issue !== null) {
                $this->issues->resolve($issue);
            }
        }
    }
}
