<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 50)->default('manual')->after('booking_id');
            $table->string('transaction_reference', 150)->nullable()->after('transaction_id');
            $table->string('bakong_md5', 64)->nullable()->after('transaction_reference');
            $table->string('bakong_transaction_id', 150)->nullable()->after('bakong_md5');
            $table->text('qr_payload')->nullable()->after('bakong_transaction_id');
            $table->json('raw_request')->nullable()->after('qr_payload');
            $table->json('raw_response')->nullable()->after('raw_request');
            $table->timestamp('expires_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'transaction_reference',
                'bakong_md5',
                'bakong_transaction_id',
                'qr_payload',
                'raw_request',
                'raw_response',
                'expires_at',
            ]);
        });
    }
};