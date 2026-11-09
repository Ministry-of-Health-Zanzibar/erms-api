<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role_changes', function (Blueprint $table): void {
            $table->id();
            // Keep historical IDs and names even if an account or role is later removed.
            $table->unsignedBigInteger('user_id')->index();
            $table->string('user_name');
            $table->json('previous_roles');
            $table->json('new_roles');
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->string('changed_by_name')->nullable();
            $table->json('changed_by_roles');
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_changes');
    }
};
