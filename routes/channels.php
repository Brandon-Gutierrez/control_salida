<?php

use Illuminate\Support\Facades\Broadcast;

// Define el comportamiento de esta ruta.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
