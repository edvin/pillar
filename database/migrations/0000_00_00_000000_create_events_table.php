<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('sequence'); // global, cross-aggregate ordering (PK)

            // Aggregate identity and contiguous per-aggregate version
            $table->uuid('aggregate_id')->index();
            $table->unsignedBigInteger('aggregate_sequence'); // 1..N per aggregate
            $table->unique(['aggregate_id', 'aggregate_sequence']);

            // Correlation & type info
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->unsignedInteger('event_version')->default(1);

            // Payload & metadata
            $table->json('event_data');
            $table->timestamp('occurred_at');

            // Composite index for reads by aggregate in global order
            $table->index(['aggregate_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};