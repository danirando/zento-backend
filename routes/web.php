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

// 1. Root Route (Carica l'applicazione React)
// Quando l'utente naviga su /, carichiamo la vista principale (di solito resources/views/app.blade.php o welcome.blade.php)
// che contiene il file Javascript di Vite.
Route::get('/', function () {
    // Assumi che il nome del tuo file Blade sia 'app' (o 'welcome' se non lo hai cambiato)
    return view('app'); 
});


// 2. Rotte di Autenticazione (se decidi di mantenerle, anche se Sanctum è gestito via API)
// require __DIR__.'/auth.php';


// 3. CATCH-ALL FALLBACK (CRUCIALE PER LA SPA)
// Tutte le richieste che non corrispondono alle rotte API (definite in routes/api.php)
// o alle rotte web esplicite, vengono reindirizzate alla vista principale React.
// Questo previene gli errori 404 di Laravel quando React gestisce il routing (es. /dashboard).
Route::view('/{any}', 'app') // Usa 'app' come nome della tua vista Blade che carica React
    ->where('any', '.*');