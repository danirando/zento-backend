<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Qui registriamo le rotte per l'applicazione.
|
*/

// Rimuoviamo o commentiamo la rotta di base di Laravel se non la usiamo:
/*
Route::get('/', function () {
    return ['Laravel' => app()->version()];
});
*/

// 1. Root Route.
// In questo progetto il frontend React gira su Vite (porta 5173), quindi possiamo
// servire semplicemente la vista di default di Laravel invece di una view "app" inesistente.
Route::get('/', function () {
    return view('welcome');
});


// 2. Rotte di Autenticazione Laravel (login, register, logout, ecc.)
//    Usiamo le route generate da Breeze/Fortify invece di un controller inesistente.
require __DIR__.'/auth.php';


// 3. (Opzionale) Catch‑all per rotte non trovate.
// Per ora lo disattiviamo per evitare l'errore "View [app] not found" su /dashboard.
// Se in futuro vorrai far servire a Laravel anche il frontend, potrai
// ripristinare una Route::view con una view effettivamente esistente.
// Route::view('/{any}', 'welcome')->where('any', '.*');

// Le rotte /login e /register sono ora definite in routes/auth.php.
