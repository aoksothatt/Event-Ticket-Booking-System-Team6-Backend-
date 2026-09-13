<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Legacy no-op migration.
 *
 * This file was originally left empty, which made every fresh `migrate`
 * (e.g. in test suites using RefreshDatabase) fail with
 * `Class "CreateTicketsTable" not found`. The tickets table is actually
 * created by 2026_09_04_035730_create_tickets_table.php and extended by the
 * subsequent add-column migrations, so this file intentionally does nothing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Intentionally a no-op — see class docblock above.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally a no-op — see class docblock above.
    }
};