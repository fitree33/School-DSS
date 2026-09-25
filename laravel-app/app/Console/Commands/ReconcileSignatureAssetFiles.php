<?php

namespace App\Console\Commands;

use App\Services\Signatures\SignatureAssetStorage;
use Illuminate\Console\Command;
use Throwable;

class ReconcileSignatureAssetFiles extends Command
{
    protected $signature = 'signature-assets:reconcile {--apply : Remove proven inactive orphan files} {--minimum-age=3600 : Minimum age in seconds; never below 3600}';

    protected $description = 'Inspect signature file ownership receipts; dry-run unless --apply is supplied.';

    public function handle(SignatureAssetStorage $storage): int
    {
        try {
            $counts = $storage->reconcile((bool) $this->option('apply'), max(3600, (int) $this->option('minimum-age')));
            $this->line($this->option('apply') ? 'Mode: apply' : 'Mode: dry-run (observations only; candidate activity is unknown)');
            foreach ($counts as $name => $count) {
                $this->line($name.': '.$count);
            }

            return $counts['unresolved'] > 0 || $counts['partial_error'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('Signature reconciliation is unavailable. No unverified file is eligible for cleanup.');

            return self::FAILURE;
        }
    }
}
