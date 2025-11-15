<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('sequence'); // global, cross-aggregate ordering (PK)

            // Stream identity and contiguous per-stream version.
            // A stream in Pillar corresponds to a single aggregate root instance.
            $table->string('stream_id', 191)->index();
            $table->unsignedBigInteger('stream_sequence'); // 1..N per stream
            $table->unique(['stream_id', 'stream_sequence']);

            // Correlation & type info
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->unsignedInteger('event_version')->default(1);
            $table->json('metadata')->nullable();

            // Payload & metadata
            $table->json('event_data');
            $table->timestamp('occurred_at');

            // Composite index for reads by stream (aggregate) in global order
            $table->index(['stream_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};