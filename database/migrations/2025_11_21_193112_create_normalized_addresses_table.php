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
        Schema::create('normalized_addresses', function (Blueprint $table) {
            $table->id();
            $table->text('original_address')->nullable();
            $table->text('validated_address')->nullable();
            $table->foreignId('master_address_id')->nullable()->constrained('master_addresses')->nullOnDelete();
            $table->string('normalized_key')->index();
            $table->string('street')->nullable();
            $table->string('number')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('google_lat', 10, 7)->nullable();
            $table->decimal('google_lng', 10, 7)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('normalized_addresses');
    }
};
