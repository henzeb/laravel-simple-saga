<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\DTO\SagaState;
use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Exceptions\SagaNotFoundException;
use Henzeb\Saga\Exceptions\SagaNotRetryableException;
use Henzeb\Saga\SagaManager;
use Henzeb\Saga\Workflow;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class SagaRetryCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'saga:retry {sagaId?} {--workflow=} {--sync} {--dry-run} {--force}';

    protected $description = 'Retry a saga stuck RolledBack (restarts it) or CompensationFailed (retries that step\'s compensation); without a sagaId, retries every retryable saga (optionally scoped to one workflow)';

    public function handle(SagaManager $saga): int
    {
        /** @var string|null $sagaId */
        $sagaId = $this->argument('sagaId');

        /** @var string|null $workflowOption */
        $workflowOption = $this->option('workflow');

        if ($sagaId !== null && $workflowOption !== null) {
            $this->components->error('Pass either a sagaId or --workflow=, not both.');

            return 1;
        }

        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return 1;
        }

        return $sagaId !== null
            ? $this->retryOne($saga, $sagaId, $sync, $dryRun)
            : $this->retryBulk($saga, $workflowOption, $sync, $dryRun);
    }

    protected function retryOne(SagaManager $saga, string $sagaId, bool $sync, bool $dryRun): int
    {
        try {
            $state = $saga->coordinator()->current($sagaId);
        } catch (SagaNotFoundException $exception) {
            $this->components->error($exception->getMessage());

            return 1;
        }

        $workflow = $state->workflow;

        if (! in_array($state->status, [SagaStepStatus::RolledBack, SagaStepStatus::CompensationFailed], true)) {
            $this->components->error((new SagaNotRetryableException($sagaId, $state->status))->getMessage());

            return 1;
        }

        if ($dryRun) {
            $this->components->info("Saga {$sagaId} would be retried (currently {$state->status->value}).");

            return 0;
        }

        /** @var class-string<Workflow> $workflow */
        try {
            $result = $this->retryState($saga, $workflow, $sagaId, $state->status, $sync);
        } catch (SagaNotRetryableException $exception) {
            $this->components->error($exception->getMessage());

            return 1;
        }

        $this->components->info("Saga {$sagaId} retried; now {$result->status->value}.");

        return 0;
    }

    protected function retryBulk(SagaManager $saga, ?string $workflowOption, bool $sync, bool $dryRun): int
    {
        if ($workflowOption !== null && ! is_subclass_of($workflowOption, Workflow::class)) {
            $this->components->error("{$workflowOption} is not a Workflow class.");

            return 1;
        }

        $records = $saga->retryable($workflowOption);

        if ($records->isEmpty()) {
            $this->components->info('No retryable sagas found.');

            return 0;
        }

        $retried = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $workflow = $record->workflow;

            if ($dryRun) {
                $this->components->info("Saga {$record->sagaId} would be retried (currently {$record->status->value}).");
                $retried++;

                continue;
            }

            /** @var class-string<Workflow> $workflow */
            try {
                $result = $this->retryState($saga, $workflow, $record->sagaId, $record->status, $sync);
                $this->components->info("Saga {$record->sagaId} retried; now {$result->status->value}.");
                $retried++;
            } catch (SagaNotRetryableException|SagaNotFoundException $exception) {
                $this->components->warn("Skipping saga {$record->sagaId}: {$exception->getMessage()}");
                $skipped++;
            }
        }

        $verb = $dryRun ? 'Would retry' : 'Retried';
        $this->components->info("{$verb} {$retried} saga(s), skipped {$skipped}.");

        return 0;
    }

    /**
     * @param class-string<Workflow> $workflow
     */
    protected function retryState(SagaManager $saga, string $workflow, string $sagaId, SagaStepStatus $status, bool $sync): SagaState
    {
        return match ($status) {
            SagaStepStatus::RolledBack => $saga->workflow($workflow, $sagaId)->retry(sync: $sync),
            SagaStepStatus::CompensationFailed => $saga->workflow($workflow, $sagaId)->retryCompensation(sync: $sync),
            default => throw new SagaNotRetryableException($sagaId, $status),
        };
    }
}
