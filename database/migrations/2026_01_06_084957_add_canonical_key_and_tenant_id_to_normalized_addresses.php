<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('normalized_addresses', function (Blueprint $table) {
            if (!Schema::hasColumn('normalized_addresses', 'canonical_key')) {
                $table->text('canonical_key')->nullable()->after('normalized_key');
            }
            if (!Schema::hasColumn('normalized_addresses', 'canonical_key_hash')) {
                $table->char('canonical_key_hash', 64)->nullable()->index()->after('canonical_key');
            }
            // Global cache: unique only on hash
            $table->unique('canonical_key_hash', 'na_canonical_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('normalized_addresses', function (Blueprint $table) {
            $table->dropUnique('na_canonical_hash_unique');
            if (Schema::hasColumn('normalized_addresses', 'canonical_key_hash')) {
                $table->dropColumn('canonical_key_hash');
            }
            if (Schema::hasColumn('normalized_addresses', 'canonical_key')) {
                $table->dropColumn('canonical_key');
            }
        });
    }
};
