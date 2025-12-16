<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthApiController extends Controller
{
    public function login(Request $request)
    {
        // 1. Validazione delle credenziali
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'boolean', // Opzionale, per gestire 'Ricordami'
        ]);

        $credentials = $request->only('email', 'password');

        // 2. Tentativo di autenticazione
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            // Se l'autenticazione ha successo, la sessione viene avviata.
            // Sanctum gestisce automaticamente l'impostazione dei cookie di sessione/XSRF-TOKEN.
            
            // Restituisci una risposta di successo.
            return response()->json([
                'message' => 'Login completato con successo.',
                'user' => Auth::user(),
            ], 200);
        }

        // 3. Fallimento dell'autenticazione
        throw ValidationException::withMessages([
            'email' => [__('auth.failed')],
        ]);
    }

    // Puoi aggiungere anche la rotta di logout qui, se necessario
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout completato.'], 200);
    }
}