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
        Schema::rename('bookings', 'Booking');
    }

    /**
     * Restore the former table name when rolling this migration back.
     */
    public function down(): void
    {
        Schema::rename('Booking', 'bookings');
    }
};
