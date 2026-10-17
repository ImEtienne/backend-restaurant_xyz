<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(false);
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registration_token_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('registration_token_hash');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
