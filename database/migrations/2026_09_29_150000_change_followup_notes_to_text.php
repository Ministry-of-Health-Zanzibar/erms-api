<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('followups', function (Blueprint $table): void {
            $table->text('notes')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('followups', function (Blueprint $table): void {
            $table->string('notes')->nullable()->change();
        });
    }
};
