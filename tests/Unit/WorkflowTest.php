<?php

use Henzeb\Saga\Workflow;

it('defers onStaleRunning to config by default', function () {
    $workflow = new class extends Workflow {
        public function steps(): array
        {
            return [];
        }
    };

    expect($workflow->onStaleRunning())->toBeNull();
});

it('allows a workflow to override onStaleRunning', function () {
    $workflow = new class extends Workflow {
        public function steps(): array
        {
            return [];
        }

        public function onStaleRunning(): ?string
        {
            return 'retry';
        }
    };

    expect($workflow->onStaleRunning())->toBe('retry');
});

it('defers signalTimeout to config by default', function () {
    $workflow = new class extends Workflow {
        public function steps(): array
        {
            return [];
        }
    };

    expect($workflow->signalTimeout())->toBeNull();
});

it('allows a workflow to override signalTimeout', function () {
    $workflow = new class extends Workflow {
        public function steps(): array
        {
            return [];
        }

        public function signalTimeout(): ?int
        {
            return 3600;
        }
    };

    expect($workflow->signalTimeout())->toBe(3600);
});
