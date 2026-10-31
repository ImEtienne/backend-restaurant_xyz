<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('restaurant_booking_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamps();
        });

        $now = now();
        DB::table('restaurant_booking_locks')->insert([
            'id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_booking_locks');
    }
};
