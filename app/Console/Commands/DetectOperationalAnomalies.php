<?php

namespace App\Console\Commands;

use App\Application\Operations\DetectOperationalAnomalies as AnomalyDetector;
use App\Models\Store;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('operations:detect-anomalies')]
#[Description('Detect unusual operational activity and create triage issues.')]
class DetectOperationalAnomalies extends Command
{
    public function handle(AnomalyDetector $detector): int
    {
        $detected = 0;

        Store::query()->select('id')->chunkById(100, function ($stores) use ($detector, &$detected): void {
            foreach ($stores as $store) {
                $detected += $detector->handle($store);
            }
        });

        $this->info("Detected {$detected} operational anomaly signal(s).");

        return self::SUCCESS;
    }
}
