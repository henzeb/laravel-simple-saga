<?php

namespace Henzeb\Saga\Middleware;

use Closure;
use Henzeb\Saga\Concerns\IterableSagaStep;
use Henzeb\Saga\Contracts\RetryWhenStale;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\Exceptions\AwaitingSignalException;
use Henzeb\Saga\Exceptions\StaleSagaStepException;
use Henzeb\Saga\SagaManager;
use Henzeb\Saga\WorkflowResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ProcessesSaga
{
    public function handle(object $job, Closure $next): mixed
    {
        $coordinator = app(SagaManager::class)->coordinator();

        $coordinator->interactsWithSaga($job);

        if ($coordinator->isAlreadyCompleted($job->sagaId, $job->sagaStepIndex, $job->branch)) {
            $coordinator->ensureAdvanced($job->sagaId, $job->workflow, $job->sagaStepIndex, $job->branch);

            return null;
        }

        if ($coordinator->isStale($job->sagaId, $job->sagaStepIndex, $job->branch) && ! $this->shouldRetryStale($job)) {
            $coordinator->stepFailed(
                $job->sagaId, $job->workflow, $job->sagaStepIndex,
                new StaleSagaStepException($job->sagaId, $job->sagaStepIndex),
                sync: $job->sync, branch: $job->branch
            );

            return null;
        }

        $compensating = $job instanceof ShouldCompensate
            && $coordinator->isCompensating($job->sagaId, $job->sagaStepIndex, $job->branch);

        if ($compensating) {
            $coordinator->markCompensating($job->sagaId, $job->workflow, $job->sagaStepIndex, $job->branch);
        } else {
            $coordinator->markRunning($job->sagaId, $job->workflow, $job->sagaStepIndex, $job->branch);
        }

        try {
            $result = $compensating && method_exists($job, 'compensate')
                ? app()->call([$job, 'compensate'])
                : $next($job);
        } catch (AwaitingSignalException $exception) {
            $coordinator->stepAwaitsSignal(
                $job->sagaId, $job->workflow, $job->sagaStepIndex, $exception->signal, $job->context,
                compensating: $compensating, branch: $job->branch
            );

            return null;
        } catch (Throwable $exception) {
            if ($this->isFinalAttempt($job)) {
                $coordinator->stepFailed($job->sagaId, $job->workflow, $job->sagaStepIndex, $exception, sync: $job->sync, branch: $job->branch);
            }

            throw $exception;
        }

        if ($this->usesQueueInteraction($job) && $job->job?->isReleased()) {
            return $result;
        }

        if ($this->isIterable($job) && $this->hasNext($job)) {
            $this->advance($job);

            $coordinator->stepIterated(
                $job->sagaId, $job->workflow, $job->sagaStepIndex, $job->context,
                compensating: $compensating, sync: $job->sync, branch: $job->branch
            );

            return $result;
        }

        $coordinator->stepCompleted(
            $job->sagaId, $job->workflow, $job->sagaStepIndex, $result,
            compensating: $compensating, sync: $job->sync, branch: $job->branch
        );

        return $result;
    }

    protected function isIterable(object $job): bool
    {
        return in_array(IterableSagaStep::class, class_uses_recursive($job), true);
    }

    protected function usesQueueInteraction(object $job): bool
    {
        return in_array(InteractsWithQueue::class, class_uses_recursive($job), true);
    }

    protected function hasNext(object $job): bool
    {
        // @phpstan-ignore-next-line method.notFound (IterableSagaStep::hasNext() is protected by design)
        return Closure::bind(fn () => $this->hasNext(), $job, $job::class)();
    }

    protected function advance(object $job): void
    {
        // @phpstan-ignore-next-line method.notFound (IterableSagaStep::next() is protected by design)
        Closure::bind(fn () => $this->next(), $job, $job::class)();
    }

    protected function isFinalAttempt(object $job): bool
    {
        app(SagaManager::class)->coordinator()->interactsWithSaga($job);

        if ($job->sync || ! $job instanceof ShouldQueue) {
            return true;
        }

        return ! $job->job || $job->job->attempts() >= ($job->tries ?? 1);
    }

    protected function shouldRetryStale(object $job): bool
    {
        app(SagaManager::class)->coordinator()->interactsWithSaga($job);

        if ($job instanceof RetryWhenStale) {
            return true;
        }

        $policy = app(WorkflowResolver::class)->resolve($job->workflow)->onStaleRunning() ?? config('saga.on_stale_running', 'fail');

        return $policy === 'retry';
    }
}
