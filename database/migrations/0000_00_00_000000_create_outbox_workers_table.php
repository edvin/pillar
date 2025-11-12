<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbox_partitions', function (Blueprint $t) {
            $t->string('partition_key', 64)->primary();
            $t->string('lease_owner', 36)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->unsignedBigInteger('lease_epoch')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_partitions');
    }

};