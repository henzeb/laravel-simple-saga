<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\SagaManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;

class SagaPruneCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'saga:prune {--before=} {--workflow=} {--dry-run} {--force} {--r|with-rolled-back}';

    protected $description = 'Prune sagas whose latest status is Completed';

    public function handle(SagaManager $saga): int
    {
        /** @var string|null $beforeOption */
        $beforeOption = $this->option('before');
        $before = $beforeOption ? Carbon::parse($beforeOption) : null;
        /** @var string|null $workflowOption */
        $workflowOption = $this->option('workflow');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return 1;
        }

        $pruned = $saga->prune(SagaStepStatus::Completed, $before, $dryRun, $workflowOption);

        if ($this->option('with-rolled-back')) {
            $pruned += $saga->prune(SagaStepStatus::RolledBack, $before, $dryRun, $workflowOption);
        }

        $this->components->info(
            $dryRun ? "{$pruned} saga(s) would be pruned." : "{$pruned} saga(s) pruned."
        );

        return 0;
    }
}
