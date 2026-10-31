<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('service_key', 64)->nullable()->after('time');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('service_key');
            $table->index(['date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['date', 'status']);
            $table->dropColumn(['service_key', 'duration_minutes']);
        });
    }
};
