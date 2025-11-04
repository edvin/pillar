<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->uuid('aggregate_id')->index();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->unsignedInteger('event_version')->default(1);
            $table->json('event_data');
            $table->timestamp('occurred_at');
            $table->index(['aggregate_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};