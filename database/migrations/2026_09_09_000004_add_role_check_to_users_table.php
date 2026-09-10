<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL supports named table constraints. SQLite :memory: used by
        // tests has limited ALTER support, so validation falls back to the
        // application layer (Role enum + Form Requests) there.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE users ADD CONSTRAINT users_role_check '
                ."CHECK (role IN ('admin', 'organizer', 'event_staff', 'customer'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        }
    }
};
