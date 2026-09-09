<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('ticket_number', 40)->nullable()->unique()->after('ticket_code');
            $table->string('qr_code', 255)->nullable()->after('qr_token');
            $table->timestamp('issued_at')->nullable()->after('status');
            $table->timestamp('checked_in_at')->nullable()->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['ticket_number', 'qr_code', 'issued_at', 'checked_in_at']);
        });
    }
};