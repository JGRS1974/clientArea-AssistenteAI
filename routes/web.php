<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsApp\WhatsappLoginController;

Route::middleware(['web'])->group(function () {
    Route::get('/w/login/{token}', [WhatsappLoginController::class, 'show'])
        ->middleware('signed')
        ->name('whatsapp.login.show');

    Route::post('/w/login/{token}', [WhatsappLoginController::class, 'submit'])
        ->middleware('signed')
        ->name('whatsapp.login.submit');
});

