<?php

namespace Henzeb\Saga\Middleware;

use Closure;
use Henzeb\Saga\Concerns\IterableSagaStep;
use Henzeb\Saga\Contracts\RetryWhenStale;
use Henzeb\Saga\Contracts\ShouldCompensate;
use Henzeb\Saga\Exceptions\AwaitingSignalException;
use Henzeb\Saga\Exceptions\StaleSagaStepException;
use Henzeb\Saga\SagaCoordinator;
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

        if (! $coordinator->hasStepRecord($job->sagaId, $job->sagaStepIndex, $job->branch)) {
            return null; // saga was deleted (or never started) — nothing to do
        }

        if ($coordinator->isAwaitingSignal($job->sagaId, $job->sagaStepIndex, $job->branch)) {
            return null; // parked on waitFor(); not this delivery's concern
        }

        if ($coordinator->isAlreadyCompleted($job->sagaId, $job->sagaStepIndex, $job->branch)) {
            $coordinator->ensureAdvanced($job->sagaId, $job->workflow, $job->sagaStepIndex, $job->branch);

            return null;
        }

        if ($coordinator->isStepFinished($job->sagaId, $job->sagaStepIndex, $job->branch)) {
            return null; // settled by another delivery while this one was in flight; nothing to do
        }

        if ($coordinator->isCurrentlyInProgress($job->sagaId, $job->sagaStepIndex, $job->branch)
            && ! $coordinator->isStale($job->sagaId, $job->sagaStepIndex, $job->branch)) {
            $this->watchOrRelease($job, $coordinator);

            return null; // another delivery is already handling this step; nothing to do
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
            if ($this->isFinalAttempt($job, $coordinator)) {
                $coordinator->stepFailed($job->sagaId, $job->workflow, $job->sagaStepIndex, $exception, sync: $job->sync, branch: $job->branch);
            } else {
                $coordinator->markAttemptFailed($job->sagaId, $job->workflow, $job->sagaStepIndex, $exception, compensating: $compensating, branch: $job->branch);
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

    // Called when this delivery finds the step still Running/Compensating and
    // not yet stale — i.e. someone else may genuinely still be handling it.
    // Keeps a delivery coming back to check again, without ever spending the
    // step's own $tries budget on pure collisions: while this lineage still has
    // attempts left, it just releases itself for later; once it's on its own
    // last attempt, it hands watching duty to a freshly dispatched, independent
    // copy (see SagaCoordinator::dispatchWatcher()) before letting itself end.
    protected function watchOrRelease(object $job, SagaCoordinator $coordinator): void
    {
        $coordinator->interactsWithSaga($job);

        // The 'sync' connection runs a job immediately, inline, ignoring any
        // delay — release()ing or dispatching a watcher here would either be a
        // silent no-op or recurse straight back into this same call stack with
        // no time actually passing. Neither is safe, so a sync delivery that
        // finds the step still in progress just ends without either.
        if ($job->sync || ! $this->usesQueueInteraction($job) || ! $job->job) {
            return;
        }

        $attempts = $job->job->attempts();
        $tries = $job->tries ?? 1;

        // 0 is Laravel's own convention for "retry indefinitely" — never treat
        // that as "out of attempts, hand off to a watcher".
        if ($tries === 0 || $attempts < $tries) {
            $job->job->release($this->watchDelay($job, $attempts));

            return;
        }

        $coordinator->dispatchWatcher($job, $job->sync, $this->watchDelay($job, $attempts));
    }

    protected function watchDelay(object $job, int $attempts): int
    {
        /** @var mixed $backoff */
        $backoff = $job->backoff ?? null;

        if (is_int($backoff)) {
            return $backoff;
        }

        if (is_array($backoff) && $backoff !== []) {
            // Same convention Laravel's own backoff resolution uses: index by
            // the attempt just made, clamped to the array's last value once
            // attempts run past it.
            $value = $backoff[max(0, $attempts - 1)] ?? end($backoff);

            if (is_int($value)) {
                return $value;
            }
        }

        return 5;
    }

    protected function isFinalAttempt(object $job, SagaCoordinator $coordinator): bool
    {
        $coordinator->interactsWithSaga($job);

        if ($job->sync || ! $job instanceof ShouldQueue) {
            return true;
        }

        if (! $job->job) {
            return true;
        }

        $tries = $job->tries ?? 1;

        if ($tries === 0) {
            return false;
        }

        $priorFailures = $coordinator->attemptFailureCount($job->sagaId, $job->sagaStepIndex, $job->branch);

        return ($priorFailures + 1) >= $tries;
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
