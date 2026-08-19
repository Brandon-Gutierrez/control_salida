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
        Schema::create('leave_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id') //llave foranea a la tabla users
                  ->nullable(false) //no puede ser nulo
                  ->constrained()   //asume que la tabla se llama users y la columna id     
                  ->onDelete('cascade'); //elimina el registro si el usuario es eliminado
            $table->foreignId('leave_id')  //llave foranea a la tabla leaves
                  ->nullable(false)
                  ->constrained()
                  ->onDelete('cascade');
                  $table->timestamps();
            $table->dateTime('leave_time')->nullable(false); //fecha y hora de salida
            $table->dateTime('return_time')->nullable(true); //fecha y hora de retorno
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
