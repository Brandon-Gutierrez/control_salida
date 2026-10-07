<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Define los cambios de la migración.
return new class extends Migration
{
    public function up(): void
    {
        // Define las columnas de la tabla.
        Schema::create('user_leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()
                ->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('period', 10);
            $table->unsignedInteger('max_exits')->nullable();
            $table->unsignedInteger('max_exits_per_premise')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_leave_policies');
    }
};
