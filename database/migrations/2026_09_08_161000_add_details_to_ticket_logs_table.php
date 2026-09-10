<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->after('action');
            $table->string('description')->nullable()->after('actor_id');
            $table->json('metadata')->nullable()->after('description');

            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_id']);
            $table->dropColumn(['actor_id', 'description', 'metadata']);
        });
    }
};
