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
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            // Postgres doesn't index foreign keys by itself, and every page reads the user's purchases for their credits.
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount'); // nanodollars
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('credit_purchases');
    }
};
