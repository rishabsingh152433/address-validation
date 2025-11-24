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
        Schema::create('gps_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->nullable();
            $table->foreignId('master_address_id')->constrained('master_addresses')->cascadeOnDelete();
            $table->decimal('delivery_lat', 10, 7);
            $table->decimal('delivery_lng', 10, 7);
            $table->decimal('google_lat', 10, 7)->nullable();
            $table->decimal('google_lng', 10, 7)->nullable();
            $table->decimal('discrepancy_meters', 10, 2)->default(0);
            $table->integer('event_count')->default(1);
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
        Schema::dropIfExists('gps_events');
    }
};
