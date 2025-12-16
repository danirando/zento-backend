<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// --- Controllori di Base (Assicurati che i percorsi siano corretti) ---
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AiController;

// 🚨 IMPORT DEL CONTROLLER DI AUTENTICAZIONE 🚨
// Utilizziamo il controller che abbiamo creato per gestire la logica di login/logout.
use App\Http\Controllers\AuthApiController; 

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- 1. ROTTE NON PROTETTE (AUTENTICAZIONE PUBBLICA) ---
// Queste rotte NON richiedono che l'utente sia già loggato.

// 🚨 ROTTA DI LOGIN (POST /login) 🚨
// Questa rotta risolve il tuo errore 405 Method Not Allowed.
Route::post('/login', [AuthApiController::class, 'login']); 


// --- 2. ROTTE PROTETTE (RICHIEDONO auth:sanctum) ---
// Raggruppiamo tutte le rotte che necessitano che l'utente sia loggato.
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Rotta per i dati dell'utente (GET /user)
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Rotta per i dati iniziali della Dashboard (GET /dashboard)
    // Non è necessario specificare di nuovo il middleware, poiché è nel gruppo.
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Rotta per la Chat AI (POST /chat)
    Route::post('/chat', [AiController::class, 'chat']);
    
    // Rotta per il Logout (POST /logout)
    Route::post('/logout', [AuthApiController::class, 'logout']);

});