<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-level guarantee that one ticket can never be checked in twice:
     * only one CheckIn row may reference a ticket_id.
     */
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->unique('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropUnique(['ticket_id']);
        });
    }
};
