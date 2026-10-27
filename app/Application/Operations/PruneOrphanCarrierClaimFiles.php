<?php

namespace App\Application\Operations;

use App\Models\CarrierClaim;
use App\Models\CarrierClaimDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Removes evidence files that no claim can reach any more, e.g. after a database cascade from a deleted store.
 *
 * Only paths of the form carrier-claims/{store id}/{claim id}[/file] are considered; anything else on the disk is never touched.
 * Files inside a live claim directory are kept unless no document row points at them and they are older than the grace period,
 * so an upload that is still being saved is not removed.
 */
class PruneOrphanCarrierClaimFiles
{
    public const GRACE_HOURS = 24;

    /** @return array{directories: int, files: int} what was (or, for a dry run, would be) removed */
    public function handle(bool $dryRun = false): array
    {
        $disk = Storage::disk(CarrierClaimDocument::DISK);
        $root = 'carrier-claims';
        $directories = $files = 0;

        foreach ($disk->directories($root) as $storeDirectory) {
            $storeId = $this->identifier($storeDirectory);
            if ($storeId === null) {
                continue;
            }
            $claimIds = CarrierClaim::query()->where('store_id', $storeId)->pluck('id')->all();

            foreach ($disk->directories($storeDirectory) as $claimDirectory) {
                $claimId = $this->identifier($claimDirectory);
                if ($claimId === null) {
                    continue;
                }
                if (! in_array($claimId, $claimIds, true)) {
                    $files += count($disk->allFiles($claimDirectory));
                    $directories++;
                    if (! $dryRun) {
                        $disk->deleteDirectory($claimDirectory);
                    }

                    continue;
                }
                $known = CarrierClaimDocument::query()->where('carrier_claim_id', $claimId)->pluck('path')->all();
                foreach ($disk->files($claimDirectory) as $file) {
                    if (in_array($file, $known, true) || $disk->lastModified($file) > now()->subHours(self::GRACE_HOURS)->getTimestamp()) {
                        continue;
                    }
                    $files++;
                    if (! $dryRun) {
                        $disk->delete($file);
                    }
                }
            }
        }

        return ['directories' => $directories, 'files' => $files];
    }

    private function identifier(string $directory): ?int
    {
        $name = basename($directory);

        return ctype_digit($name) ? (int) $name : null;
    }
}
