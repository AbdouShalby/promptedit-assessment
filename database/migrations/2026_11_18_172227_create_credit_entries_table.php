<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Available credit in nanodollars. Always equal to the sum of the user's credit_entries.
            $table->bigInteger('balance_nano')->default(0);
        });

        // Append-only ledger: rows are never updated or deleted.
        Schema::create('credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 16);              // purchase, reserve, release, charge
            $table->bigInteger('amount_nano');       // signed: + adds credit, - takes credit
            $table->uuid('idempotency_key')->nullable()->unique();   // purchases: a double click credits once
            $table->timestamp('created_at');

            // At most one reserve, one release and one charge per generation:
            // the database itself refuses to charge a generation twice.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_entries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance_nano');
        });
    }
};
