<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\SagaManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;

class SagaSweepSignalsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'saga:sweep-signals {--before=} {--sync} {--dry-run} {--force}';

    protected $description = 'Fail sagas whose signal wait has passed its deadline';

    public function handle(SagaManager $saga): int
    {
        /** @var string|null $beforeOption */
        $beforeOption = $this->option('before');
        $before = $beforeOption ? Carbon::parse($beforeOption) : null;
        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $count = $saga->due($before)->count();

            $this->components->info("{$count} saga(s) would time out waiting for a signal.");

            return 0;
        }

        if (! $this->confirmToProceed()) {
            return 1;
        }

        $count = $saga->sweepSignals($before, $sync);

        $this->components->info("{$count} saga(s) timed out waiting for a signal.");

        return 0;
    }
}
