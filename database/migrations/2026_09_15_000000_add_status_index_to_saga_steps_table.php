<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('saga.drivers.database.table', 'saga_steps'), function (Blueprint $table) {
            $table->index(['status', 'workflow']);
        });
    }

    public function down(): void
    {
        Schema::table(config('saga.drivers.database.table', 'saga_steps'), function (Blueprint $table) {
            $table->dropIndex(['status', 'workflow']);
        });
    }
};
