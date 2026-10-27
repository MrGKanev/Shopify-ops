<?php

namespace App\Console\Commands;

use App\Application\Operations\PruneOrphanCarrierClaimFiles as OrphanFilePruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('claims:prune-orphan-files {--dry-run : Only count what would be removed}')]
#[Description('Remove carrier-claim evidence files whose claim or store no longer exists.')]
class PruneOrphanCarrierClaimFiles extends Command
{
    public function handle(OrphanFilePruner $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = $pruner->handle($dryRun);

        $this->table(['Data', $dryRun ? 'Would remove' : 'Removed'], [
            ['Orphan claim directories', $counts['directories']],
            ['Orphan files', $counts['files']],
        ]);

        return self::SUCCESS;
    }
}
