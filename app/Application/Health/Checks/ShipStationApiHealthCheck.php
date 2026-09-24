<?php

namespace App\Application\Health\Checks;

use App\Application\Health\CheckApiHealth;
use App\Models\Store;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class ShipStationApiHealthCheck extends Check
{
    public function __construct(private readonly CheckApiHealth $checkApiHealth) {}

    public function run(): Result
    {
        $failures = [];

        foreach (Store::query()->get() as $store) {
            $result = $this->checkApiHealth->checkShipStation($store);
            if ($result['configured'] && ! $result['ok']) {
                $failures[] = "{$store->label}: {$result['error']}";
            }
        }

        $result = Result::make();

        return $failures === []
            ? $result->ok()
            : $result->failed(implode(' | ', $failures));
    }
}
