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
        Schema::create('address_discrepancies', function (Blueprint $table) {
           $table->id();
            $table->foreignId('master_address_id')->constrained('master_addresses')->cascadeOnDelete();
            $table->integer('discrepancy_count')->default(0);
            $table->timestamp('last_discrepancy_at')->nullable();
            $table->boolean('requires_admin_validation')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('address_discrepancies');
    }
};
