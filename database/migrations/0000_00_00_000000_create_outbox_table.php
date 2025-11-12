<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $t) {
            $t->unsignedBigInteger('global_sequence')->primary(); // = events.sequence
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->useCurrent();
            $t->timestamp('published_at')->nullable();
            $t->string('partition_key', 64)->nullable();
            $t->string('claim_token', 64)->nullable();
            $t->text('last_error')->nullable();
            $t->index(['published_at', 'available_at']);
            $t->index(['partition_key', 'published_at', 'available_at']);
            $t->index('claim_token');
            $t->foreign('global_sequence')->references('sequence')->on('events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }

};