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
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('external_identifier')->unique();
            $table->string('name');
            $table->integer('item')->unique(); //Item corporativo del usuario
            $table->foreignId('role_id')  //llave foranea a la tabla rol
                  ->default(1) 
                  ->nullable(false)
                  ->constrained('roles', 'role_id')
                  ->onDelete('cascade');
            $table->timestamps();
            $table->softdeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
