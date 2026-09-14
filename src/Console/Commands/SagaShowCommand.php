<?php

namespace Henzeb\Saga\Console\Commands;

use Henzeb\Saga\Enums\SagaStepStatus;
use Henzeb\Saga\Exceptions\SagaNotFoundException;
use Henzeb\Saga\SagaManager;
use Henzeb\Saga\StepLabelResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

class SagaShowCommand extends Command
{
    protected $signature = 'saga:show {sagaId} {--t|trail} {--limit=20 : History rows to show before paging through less}';

    protected $description = 'Show the current status of one saga; pass --trail for its full history';

    protected const COLORS = [
        SagaStepStatus::Pending->value => 'yellow',
        SagaStepStatus::Running->value => 'yellow',
        SagaStepStatus::Waiting->value => 'yellow',
        SagaStepStatus::Completed->value => 'green',
        SagaStepStatus::Failed->value => 'red',
        SagaStepStatus::CompensationPending->value => 'cyan',
        SagaStepStatus::Compensating->value => 'cyan',
        SagaStepStatus::CompensationWaiting->value => 'cyan',
        SagaStepStatus::Compensated->value => 'green',
        SagaStepStatus::CompensationFailed->value => 'red',
        SagaStepStatus::RolledBack->value => 'magenta',
    ];

    public function handle(SagaManager $saga, StepLabelResolver $stepLabels): int
    {
        /** @var string $sagaId */
        $sagaId = $this->argument('sagaId');

        try {
            $state = $saga->coordinator()->current($sagaId);
        } catch (SagaNotFoundException $exception) {
            $this->components->error($exception->getMessage());

            return 1;
        }

        $this->components->twoColumnDetail('Saga ID', $state->sagaId);

        if ($state->workflow !== null) {
            $this->components->twoColumnDetail('Workflow', $state->workflow);
        }

        $this->components->twoColumnDetail('Status', $this->statusLabel($state->status).' <fg='.$this->color($state->status).'>●</>');
        $this->components->twoColumnDetail('Step', $this->stepValue($stepLabels->resolve($state), $state->step));

        if ($state->signal !== null) {
            $this->components->twoColumnDetail('Awaiting Signal', $state->signal);
        }

        if ($state->signalExpiresAt !== null) {
            $this->components->twoColumnDetail('Signal Expires At', $state->signalExpiresAt->format('Y-m-d H:i:s'));
        }

        if ($state->runningExpiresAt !== null) {
            $this->components->twoColumnDetail('Running Expires At', $state->runningExpiresAt->format('Y-m-d H:i:s'));
        }

        if ($state->reason !== null) {
            $this->components->twoColumnDetail('Reason', $this->reason($state->reason));
        }

        if (! $this->option('trail')) {
            return 0;
        }

        $this->newLine();
        $this->line(' <fg=gray>History</>');

        $trail = $state->trail();
        $statusWidth = $trail->reduce(fn (int $max, $entry) => max($max, strlen($this->statusLabel($entry->status))), 0);
        $stepWidth = $trail->reduce(fn (int $max, $entry) => max($max, strlen($this->stepColumn($stepLabels->resolve($entry), $entry->step))), 0);

        $lines = [];

        foreach ($trail as $entry) {
            $timestamp = $entry->recordedAt?->format('Y-m-d H:i:s') ?? str_repeat(' ', 19);
            $step = str_pad($this->stepColumn($stepLabels->resolve($entry), $entry->step), $stepWidth);
            $status = str_pad($this->statusLabel($entry->status), $statusWidth);
            $color = $this->color($entry->status);

            $lines[] = "  <fg=gray>{$timestamp}</>  {$step}  <fg={$color}>{$status}</>";

            if ($entry->reason !== null) {
                $indent = str_repeat(' ', 2 + strlen($timestamp) + 2);
                $lines[] = "{$indent}<fg=gray>↳</> ".$this->reason($entry->reason);
            }
        }

        /** @var int $limit */
        $limit = (int) $this->option('limit');

        $this->renderTrail($lines, $limit);

        return 0;
    }

    /**
     * @param string[] $lines
     */
    protected function renderTrail(array $lines, int $limit): void
    {
        if (count($lines) <= $limit || ! $this->shouldPage()) {
            foreach ($lines as $line) {
                $this->line($line);
            }

            return;
        }

        $this->page($lines);
    }

    protected function shouldPage(): bool
    {
        return stream_isatty(STDOUT);
    }

    /**
     * @param string[] $lines
     */
    protected function page(array $lines): void
    {
        $process = @proc_open('less -R', [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);

        if (! is_resource($process)) {
            foreach ($lines as $line) {
                $this->line($line);
            }

            return;
        }

        $formatter = new OutputFormatter(true);
        $content = implode(PHP_EOL, array_map(fn (string $line) => $formatter->format($line), $lines));

        fwrite($pipes[0], $content.PHP_EOL);
        fclose($pipes[0]);
        proc_close($process);
    }

    protected function statusLabel(SagaStepStatus $status): string
    {
        return Str::of($status->value)->replace('_', ' ')->title()->toString();
    }

    protected function color(SagaStepStatus $status): string
    {
        return static::COLORS[$status->value];
    }

    protected function stepColumn(?string $label, int $step): string
    {
        return 'Step '.$this->stepValue($label, $step);
    }

    protected function stepValue(?string $label, int $step): string
    {
        $number = $step + 1;

        return $label === null ? (string) $number : "{$number} — {$label}";
    }

    protected function reason(string $reason): string
    {
        [$class, $message] = array_pad(explode(': ', $reason, 2), 2, null);

        return $message === null ? $class : "<fg=gray>{$class}:</> {$message}";
    }
}
