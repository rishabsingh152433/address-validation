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
        Schema::create('master_addresses', function (Blueprint $table) {
            $table->id();
            $table->text('formatted_address');
            $table->decimal('google_lat', 10, 7)->nullable();
            $table->decimal('google_lng', 10, 7)->nullable();
            $table->enum('source', ['manual','api','lmt'])->default('api');
            $table->integer('validation_count')->default(0);
            $table->boolean('is_trusted')->default(false);
            $table->integer('concordance_level')->default(0);
            $table->timestamp('last_validated_at')->nullable();
            $table->decimal('final_navigation_coordinate_lat', 10, 7)->nullable();
            $table->decimal('final_navigation_coordinate_lng', 10, 7)->nullable();
            $table->boolean('created_from_history')->default(false);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_addresses');
    }
};
