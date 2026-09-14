<?php

namespace Henzeb\Saga\Contracts;

interface ShouldCompensate
{
    // marker only — if compensate() exists, ProcessesSaga calls it for the rollback;
    // otherwise handle() itself doubles as the compensation.
}
