<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Exceptions\SagaNotFoundException;
use Henzeb\Saga\SagaManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;

class SagaCompensateCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'saga:compensate {sagaId?} {--since=} {--workflow=} {--sync} {--dry-run} {--force}';

    protected $description = 'Manually compensate one saga by ID — stuck mid-run (Pending, Running, Waiting) or already Completed; without a sagaId, compensates every saga stuck since before a given date (optionally scoped to one workflow) — Completed sagas are never swept up in bulk';

    protected const STUCK = [SagaStepStatus::Pending, SagaStepStatus::Running, SagaStepStatus::Waiting];

    protected const COMPENSATABLE = [SagaStepStatus::Pending, SagaStepStatus::Running, SagaStepStatus::Waiting, SagaStepStatus::Completed];

    public function handle(SagaManager $saga): int
    {
        /** @var string|null $sagaId */
        $sagaId = $this->argument('sagaId');

        /** @var string|null $workflowOption */
        $workflowOption = $this->option('workflow');
        /** @var string|null $sinceOption */
        $sinceOption = $this->option('since');

        if ($sagaId !== null && ($workflowOption !== null || $sinceOption !== null)) {
            $this->components->error('Pass either a sagaId or --since=/--workflow=, not both.');

            return 1;
        }

        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return 1;
        }

        return $sagaId !== null
            ? $this->compensateOne($saga, $sagaId, $sync, $dryRun)
            : $this->compensateBulk($saga, $workflowOption, $sinceOption ? Carbon::parse($sinceOption) : null, $sync, $dryRun);
    }

    protected function compensateOne(SagaManager $saga, string $sagaId, bool $sync, bool $dryRun): int
    {
        try {
            $state = $saga->coordinator()->current($sagaId);
        } catch (SagaNotFoundException $exception) {
            $this->components->error($exception->getMessage());

            return 1;
        }

        if (! in_array($state->status, static::COMPENSATABLE, true)) {
            $this->components->error("Saga {$sagaId} cannot be compensated (currently {$state->status->value}).");

            return 1;
        }

        if ($dryRun) {
            $this->components->info("Saga {$sagaId} would be compensated (currently {$state->status->value}).");

            return 0;
        }

        $result = $saga->coordinator()->compensate($sagaId, $sync);

        $this->components->info("Saga {$sagaId} compensation started; now {$result->status->value}.");

        return 0;
    }

    protected function compensateBulk(SagaManager $saga, ?string $workflowOption, ?Carbon $since, bool $sync, bool $dryRun): int
    {
        $records = $saga->active($workflowOption)
            ->filter(fn ($record) => in_array($record->status, static::STUCK, true))
            ->when($since, fn ($records) => $records->filter(
                fn ($record) => $record->recordedAt !== null && $record->recordedAt < $since
            ));

        if ($records->isEmpty()) {
            $this->components->info('No stuck sagas found.');

            return 0;
        }

        $compensated = 0;

        foreach ($records as $record) {
            if ($dryRun) {
                $this->components->info("Saga {$record->sagaId} would be compensated (currently {$record->status->value}).");
                $compensated++;

                continue;
            }

            $result = $saga->coordinator()->compensate($record->sagaId, $sync);
            $this->components->info("Saga {$record->sagaId} compensation started; now {$result->status->value}.");
            $compensated++;
        }

        $verb = $dryRun ? 'Would compensate' : 'Compensated';
        $this->components->info("{$verb} {$compensated} saga(s).");

        return 0;
    }
}
