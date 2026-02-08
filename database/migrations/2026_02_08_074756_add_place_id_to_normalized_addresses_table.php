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
        Schema::table('normalized_addresses', function (Blueprint $table) {
            if (!Schema::hasColumn('normalized_addresses', 'place_id')) {
                $table->string('place_id', 255)->nullable()->index()->after('canonical_key_hash');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('normalized_addresses', function (Blueprint $table) {
            if (Schema::hasColumn('normalized_addresses', 'place_id')) {
                $table->dropIndex(['place_id']);
                $table->dropColumn('place_id');
            }
        });
    }
};
