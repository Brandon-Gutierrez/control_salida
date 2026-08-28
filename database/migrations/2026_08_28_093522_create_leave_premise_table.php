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
        Schema::create('leave_premise', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('leave_id')  //llave foranea a la tabla leaves
                  ->nullable(false)
                  ->constrained()
                  ->onDelete('cascade');
            $table->foreignId('premise_id')  //llave foranea a la tabla premise
                  ->nullable(false)
                  ->constrained()
                  ->onDelete('cascade');
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
