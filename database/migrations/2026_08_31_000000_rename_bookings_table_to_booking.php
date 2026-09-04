<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename the previously-created lowercase table in existing databases.
     */
    public function up(): void
    {
        // Fresh installs already create the final table name. Existing installs
        // from before that change still have the lowercase name.
        if (Schema::hasTable('bookings') && ! Schema::hasTable('Booking')) {
            Schema::rename('bookings', 'Booking');
        }
    }

    /**
     * Restore the former table name when rolling this migration back.
     */
    public function down(): void
    {
        if (Schema::hasTable('Booking') && ! Schema::hasTable('bookings')) {
            Schema::rename('Booking', 'bookings');
        }
    }
};
