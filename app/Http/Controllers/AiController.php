<?php

namespace App\Http\Controllers;

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
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            // Aggiungiamo un log per gli errori del server
            Log::error('Tentativo di accesso all\'AI fallito: GEMINI_API_KEY mancante.');
            return response()->json([
                'error' => 'API Key mancante. Configura GEMINI_API_KEY nel file .env',
            ], 500);
        }

        // Definiamo il modello e l'endpoint
        $model = 'gemini-2.5-flash'; // Consiglio un modello più recente se possibile
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        try {
            // 2. Chiamata API con la chiave negli Headers (metodo più comune)
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                // Preferiamo inviare la chiave come Authorization Header
                'x-goog-api-key' => $apiKey, 
            ])->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            // 3. Gestione degli errori HTTP generici
            if ($response->failed()) {
                Log::error('Errore API Gemini:', ['body' => $response->body(), 'status' => $response->status()]);
                return response()->json([
                    'error' => 'Errore API Gemini. Dettagli: ' . $response->json('error.message', 'Errore sconosciuto'),
                ], $response->status());
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
}