<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();

            $table->string('aggregate_type', 255);
            $table->string('aggregate_id', 191);

            $table->unsignedBigInteger('snapshot_version')->default(0);
            $table->dateTime('snapshot_created_at');
            $table->json('data');
            $table->unique(['aggregate_type', 'aggregate_id'], 'pillar_snapshots_unique_aggregate');
            $table->index(['aggregate_type', 'snapshot_version'], 'pillar_snapshots_type_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};