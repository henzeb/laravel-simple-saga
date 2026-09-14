<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\SagaManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

class SagaDeleteCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'saga:delete {sagaId} {--force}';

    protected $description = 'Delete one specific saga outright, regardless of its status';

    public function handle(SagaManager $saga): int
    {
        if (! $this->confirmToProceed()) {
            return 1;
        }

        /** @var string $sagaId */
        $sagaId = $this->argument('sagaId');

        $saga->coordinator()->delete($sagaId);

        $this->components->info("Saga {$sagaId} deleted.");

        return 0;
    }
}
