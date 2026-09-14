<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\SagaManager;
use Illuminate\Console\Command;

class SagaListCommand extends Command
{
    protected $signature = 'saga:list {--workflow=}';

    protected $description = 'List every saga that is running or has failed';

    public function handle(SagaManager $saga): int
    {
        /** @var string|null $workflowOption */
        $workflowOption = $this->option('workflow');

        $active = $saga->active($workflowOption);

        if ($active->isEmpty()) {
            $this->components->info('No sagas are currently running or failed.');

            return 0;
        }

        $coordinator = $saga->coordinator();

        $this->table(
            ['Saga ID', 'Step', 'Status', 'Started At'],
            $active->map(function ($record) use ($coordinator) {
                $startedAt = $coordinator->current($record->sagaId)->trail()->first()?->recordedAt;

                return [
                    $record->sagaId,
                    $record->step,
                    $record->status->value,
                    $startedAt?->format('Y-m-d H:i:s') ?? '',
                ];
            })->all()
        );

        return 0;
    }
}
