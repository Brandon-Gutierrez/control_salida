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
        Schema::create('reason_premise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reason_id')  //llave foranea a la tabla
                  ->nullable(false)
                  ->constrained('reason_leaves')
                  ->onDelete('cascade');
            $table->foreignId('premise_id')  //llave foranea a la tabla premise
                  ->nullable(false)
                  ->constrained()
                  ->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_premise');
    }
};
