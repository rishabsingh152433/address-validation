<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_api_hit_counters', function (Blueprint $table) {
            $table->id();
            $table->date('hit_date');
            $table->string('endpoint', 50);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['hit_date','endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_api_hit_counters');
    }
};
