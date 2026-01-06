<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres: enum in Laravel becomes CHECK constraint.
        // So we drop old check and add new one with extra allowed values.

        DB::statement("ALTER TABLE address_validation_logs DROP CONSTRAINT IF EXISTS address_validation_logs_validation_type_check");

        DB::statement("
            ALTER TABLE address_validation_logs
            ADD CONSTRAINT address_validation_logs_validation_type_check
            CHECK (validation_type IN (
                'api','manual','delivery',
                'normalized_match','master_match','google_match','not_found',

                -- NEW values needed by improved logic
                'google_ambiguous',
                'google_auto_pick',
                'google_rejected_number_mismatch',
                'google_rejected_poi'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE address_validation_logs DROP CONSTRAINT IF EXISTS address_validation_logs_validation_type_check");

        DB::statement("
            ALTER TABLE address_validation_logs
            ADD CONSTRAINT address_validation_logs_validation_type_check
            CHECK (validation_type IN (
                'api','manual','delivery',
                'normalized_match','master_match','google_match','not_found'
            ))
        ");
    }
};
