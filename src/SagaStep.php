<?php

namespace Henzeb\Saga;

use Henzeb\Saga\Concerns\InteractsWithSaga;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class SagaStep
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithSaga;
}
