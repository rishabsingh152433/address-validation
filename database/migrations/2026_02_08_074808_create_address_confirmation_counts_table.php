<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('address_confirmation_counts', function (Blueprint $table) {
            $table->id();
            $table->string('place_id', 255)->unique();
            $table->unsignedInteger('confirmation_count')->default(0);
            $table->timestamp('first_confirmed_at')->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_confirmation_counts');
    }
};
