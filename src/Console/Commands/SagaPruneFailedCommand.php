<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\SagaManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;

class SagaPruneFailedCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'saga:prune-failed {--before=} {--workflow=} {--dry-run} {--force} {--c|with-compensation-failed}';

    protected $description = 'Prune sagas whose latest status is Failed';

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

        $pruned = $saga->prune(SagaStepStatus::Failed, $before, $dryRun, $workflowOption);

        if ($this->option('with-compensation-failed')) {
            $pruned += $saga->prune(SagaStepStatus::CompensationFailed, $before, $dryRun, $workflowOption);
        }

        $this->components->info(
            $dryRun ? "{$pruned} saga(s) would be pruned." : "{$pruned} saga(s) pruned."
        );

        return 0;
    }
}
