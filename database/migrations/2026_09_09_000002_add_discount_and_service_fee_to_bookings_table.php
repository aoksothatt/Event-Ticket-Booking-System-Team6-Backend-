<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Booking', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->default(0)->after('total_amount');
            $table->decimal('service_fee', 10, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('Booking', function (Blueprint $table) {
            $table->dropColumn(['discount', 'service_fee']);
        });
    }
};
