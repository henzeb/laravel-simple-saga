<?php

namespace Henzeb\Saga\Enums;

enum SagaStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case AttemptFailed = 'attempt_failed';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case CompensationPending = 'compensation_pending';
    case Compensating = 'compensating';
    case CompensationAttemptFailed = 'compensation_attempt_failed';
    case CompensationWaiting = 'compensation_waiting';
    case Compensated = 'compensated';
    case CompensationFailed = 'compensation_failed';
    case RolledBack = 'rolled_back';
}
