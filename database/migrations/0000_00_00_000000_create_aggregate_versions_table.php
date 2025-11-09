<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aggregate_versions', function (Blueprint $table) {
            $table->uuid('aggregate_id')->primary();
            $table->string('aggregate_id_class', 512)->index();
            $table->unsignedBigInteger('last_sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregate_versions');
    }
};