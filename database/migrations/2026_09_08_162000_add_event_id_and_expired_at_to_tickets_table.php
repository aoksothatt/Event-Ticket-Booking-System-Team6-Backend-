<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('event_id')
                ->after('ticket_type_id')
                ->nullable()
                ->constrained('events')
                ->cascadeOnDelete();

            $table->timestamp('expired_at')
                ->nullable()
                ->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropColumn(['event_id', 'expired_at']);
        });
    }
};
