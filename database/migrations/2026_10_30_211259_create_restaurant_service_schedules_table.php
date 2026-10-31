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
        Schema::create('restaurant_service_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('service_key', 64);
            $table->string('service_name', 100);
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->unsignedSmallInteger('slot_interval_minutes');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('capacity_guests');
            $table->timestamps();

            $table->unique(['day_of_week', 'service_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_service_schedules');
    }
};
