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
        Schema::create('address_validation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_address_id')->nullable()->constrained('master_addresses')->nullOnDelete();
            $table->foreignId('normalized_address_id')->nullable()->constrained('normalized_addresses')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->enum('validation_type', [
                'api','manual','delivery','normalized_match','master_match','google_match','not_found'
            ]);
            $table->json('raw_payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_validation_logs');
    }
};
