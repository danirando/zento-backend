<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; // Aggiunto per un miglior logging

class AiController extends Controller
{
    /**
     * Handle the AI chat request using Google Gemini.
     */
    public function chat(Request $request)
    {
        // 1. Validazione input
        $request->validate([
            'message' => 'required|string',
        ]);

        $prompt = $request->input('message');
        $user = $request->user();

        // 1.5 Salvataggio del messaggio dell'utente nel database
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $prompt,
        ]);
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            // Aggiungiamo un log per gli errori del server
            Log::error('Tentativo di accesso all\'AI fallito: GEMINI_API_KEY mancante.');
            return response()->json([
                'error' => 'API Key mancante. Configura GEMINI_API_KEY nel file .env',
            ], 500);
        }

        // Definiamo il modello e l'endpoint (Aggiornato a Gemini 3 Flash Preview)
        $model = 'gemini-3-flash-preview'; 
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            // 2. Chiamata API
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            // 3. Gestione degli errori dall'API Gemini
            if ($response->failed()) {
                Log::error('Errore API Gemini:', ['body' => $response->body(), 'status' => $response->status()]);
                
                $status = $response->status();
                $errorMessage = $response->json('error.message', 'Errore sconosciuto');

                // Se l'errore è un 429 (Too Many Requests), lo passiamo al frontend con un messaggio chiaro.
                if ($status === 429) {
                    return response()->json([
                        'error' => 'Limite di richieste raggiunto (Rate Limit). Riprova tra un momento. Dettagli: ' . $errorMessage,
                    ], 429);
                }

                // Per altri errori, restituiamo 502 (Bad Gateway) per indicare un problema col servizio esterno.
                return response()->json([
                    'error' => 'Il servizio AI ha risposto con un errore (' . $status . '). Dettagli: ' . $errorMessage,
                ], 502); 
            }

            $data = $response->json();
            $candidates = $data['candidates'] ?? null;
            
            // 4. Estrazione sicura e controllo dello stato
            if (empty($candidates) || $candidates[0]['finishReason'] !== 'STOP') {
                // Gestisce casi come risposte bloccate (Safety Settings) o errori interni di generazione
                $reason = $candidates[0]['finishReason'] ?? 'UNKNOWN';
                $message = "La generazione è stata interrotta. Ragione: " . $reason;
                Log::warning('Generazione Gemini interrotta:', ['reason' => $reason]);
                
                return response()->json([
                    'error' => $message,
                ], 400); 
            }

            $responseText = $candidates[0]['content']['parts'][0]['text'] ?? 'Nessuna risposta testuale ricevuta.';

            // 4.5 Salvataggio della risposta dell'AI nel database
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $responseText,
            ]);

            // 5. Correzione CRITICA per il Frontend React
            // Il frontend si aspetta 'reply', non 'response'.
            return response()->json([
                'reply' => $responseText, // Corretto il nome del campo
            ]);

        } catch (\Exception $e) {
            Log::error('Errore PHP durante la comunicazione con AI:', ['exception' => $e->getMessage()]);
            return response()->json([
                'error' => 'Errore interno del server: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recupera la cronologia delle chat per l'utente loggato.
     * Al momento restituisce una lista vuota o dati di mock.
     */
    public function history(Request $request)
    {
        // Recuperiamo i messaggi dal database per l'utente loggato.
        $history = $request->user()->chatMessages()
            ->orderBy('created_at', 'asc')
            ->get(['role', 'content as text', 'created_at']);

        return response()->json([
            'history' => $history,
            'message' => 'Cronologia caricata con successo.'
        ]);
    }

    /**
     * Elimina tutta la cronologia delle chat dell'utente.
     */
    public function destroyHistory(Request $request)
    {
        $user = $request->user();
        
        // Eliminiamo tutti i messaggi associati all'utente
        $user->chatMessages()->delete();

        return response()->json([
            'message' => 'Cronologia eliminata correttamente.'
        ]);
    }
}