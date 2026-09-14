<?php

namespace Henzeb\Saga\Contracts;

interface RetryWhenStale
{
    // marker only — a redelivered step finding itself already Running/Compensating
    // retries normally instead of failing, per the precedence in ProcessesSaga.
}
