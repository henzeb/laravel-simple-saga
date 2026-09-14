<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\SagaManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;

class SagaSweepStaleCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'saga:sweep-stale {--before=} {--sync} {--dry-run} {--force}';

    protected $description = 'Fail sagas whose step has been running longer than its timeout allows';

    public function handle(SagaManager $saga): int
    {
        /** @var string|null $beforeOption */
        $beforeOption = $this->option('before');
        $before = $beforeOption ? Carbon::parse($beforeOption) : null;
        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $count = $saga->dueRunning($before)->count();

            $this->components->info("{$count} saga(s) would time out while stuck running.");

            return 0;
        }

        if (! $this->confirmToProceed()) {
            return 1;
        }

        $count = $saga->sweepStale($before, $sync);

        $this->components->info("{$count} saga(s) timed out while stuck running.");

        return 0;
    }
}
