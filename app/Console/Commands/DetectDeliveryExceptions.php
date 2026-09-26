<?php

namespace App\Console\Commands;

use App\Application\Operations\DetectDeliveryExceptions as DeliveryExceptionDetector;
use App\Models\Store;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('operations:detect-delivery-exceptions')]
#[Description('Detect shipments without a delivery confirmation and add them to issue triage.')]
class DetectDeliveryExceptions extends Command
{
    public function handle(DeliveryExceptionDetector $detector): int
    {
        $detected = 0;
        $failures = 0;
        Store::query()->chunkById(100, function ($stores) use ($detector, &$detected, &$failures): void {
            foreach ($stores as $store) {
                if ($store->missingShipStationCredentials()) {
                    continue;
                }

                try {
                    $detected += $detector->handle($store);
                } catch (Throwable $exception) {
                    $failures++;
                    report($exception);
                }
            }
        });

        $this->info("Detected {$detected} delivery exception(s).".($failures > 0 ? " {$failures} store scan(s) failed." : ''));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
