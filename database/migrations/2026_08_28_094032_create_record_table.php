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
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->dateTime('leave_time')->nullable(false); //fecha y hora de salida
            $table->dateTime('return_time')->nullable(true); //fecha y hora de retorno
            $table->foreignId('user_id') //llave foranea a la tabla users
                  ->nullable(false) 
                  ->constrained()    
                  ->onDelete('cascade'); 
            $table->foreignId('reason_premise_id')  //llave foranea a la tabla auxiliar
                  ->nullable(false)
                  ->constrained('reason_premise')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_user');
    }
};
