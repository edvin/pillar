<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox_workers', function (Blueprint $t) {
            $t->string('id', 36)->primary();
            $t->string('hostname', 128);
            $t->unsignedInteger('pid');

            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent();
            $t->timestamp('heartbeat_until');

            $t->index('heartbeat_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_workers');
    }

};