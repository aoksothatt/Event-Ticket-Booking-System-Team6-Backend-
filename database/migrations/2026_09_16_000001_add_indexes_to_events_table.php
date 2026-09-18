<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes that back the homepage queries (status + date filters and
     * the trending flag) so PostgreSQL can serve them without a seq scan.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index(['status', 'start_date']);
            $table->index('is_trending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['status', 'start_date']);
            $table->dropIndex(['is_trending']);
        });
    }
};