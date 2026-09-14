<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('saga.drivers.database.table', 'saga_steps'), function (Blueprint $table) {
            $table->id();
            $table->string('saga_id');
            $table->unsignedInteger('step');
            $table->unsignedInteger('branch')->default(0);
            $table->string('status');
            $table->string('workflow')->nullable();
            $table->json('payload')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('recorded_at');
            $table->boolean('encrypted')->default(false);
            $table->string('signal')->nullable();
            $table->timestamp('signal_expires_at')->nullable();
            $table->timestamp('running_expires_at')->nullable();

            $table->index(['saga_id', 'id']);
            $table->index(['saga_id', 'step', 'branch', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('saga.drivers.database.table', 'saga_steps'));
    }
};
