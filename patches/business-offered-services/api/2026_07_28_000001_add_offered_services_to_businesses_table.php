<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop into the API repo as:
 * database/migrations/2026_07_28_000001_add_offered_services_to_businesses_table.php
 *
 * Adjust table name if providers are stored under business_profiles / accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('offered_services')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('offered_services');
        });
    }
};
