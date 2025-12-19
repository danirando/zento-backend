<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
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
            'conversation_id' => 'nullable|exists:conversations,id',
        ]);

        $prompt = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $user = $request->user();

        // 1.2 Gestione della conversazione
        if (!$conversationId) {
            $conversation = Conversation::create([
                'user_id' => $user->id,
                'title' => 'Nuova conversazione',
            ]);
            $conversationId = $conversation->id;
            $isNewConversation = true;
        } else {
            $conversation = Conversation::findOrFail($conversationId);
            $isNewConversation = false;
        }

        // 1.5 Salvataggio del messaggio dell'utente
        ChatMessage::create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $prompt,
        ]);

        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            Log::error('Tentativo di accesso all\'AI fallito: GEMINI_API_KEY mancante.');
            return response()->json([
                'error' => 'API Key mancante. Configura GEMINI_API_KEY nel file .env',
            ], 500);
        }

        $model = 'gemini-flash-latest'; 
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            // 2. Chiamata API per la risposta con TIMEOUT per evitare crash
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($url, [
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ]
            ]);

            if ($response->failed()) {
                Log::error('Errore API Gemini:', ['body' => $response->body(), 'status' => $response->status()]);
                
                $status = $response->status();
                $errorData = $response->json('error');
                $errorMessage = $errorData['message'] ?? 'Il servizio AI ha risposto con un errore.';

                if ($status === 429) {
                    return response()->json([
                        'error' => 'Limite di richieste raggiunto (Quota Exceeded). Riprova tra qualche secondo.',
                    ], 429);
                }

                if ($status === 404) {
                    return response()->json([
                        'error' => 'Modello non trovato. Verificare la configurazione del controller.',
                    ], 500);
                }

                return response()->json([
                    'error' => 'Errore del servizio AI (' . $status . '): ' . $errorMessage,
                ], 502); 
            }

            $data = $response->json();
            $responseText = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Nessuna risposta ricevuta.';

            // 4.5 Salvataggio della risposta dell'AI
            ChatMessage::create([
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
                'role' => 'assistant',
                'content' => $responseText,
            ]);

            // 5. Generazione del titolo SOLO per le nuove conversazioni per risparmiare quota API
            if ($isNewConversation) {
                try {
                    $titlePrompt = "Genera un titolo brevissimo (massimo 5 parole) per questa conversazione basato su questo messaggio: \"{$prompt}\". Rispondi SOLO con il titolo, senza virgolette o punteggiatura inutile.";
                    
                    $titleResponse = Http::withHeaders([
                        'Content-Type' => 'application/json',
                    ])->timeout(15)->post($url, [
                        'contents' => [
                            ['parts' => [['text' => $titlePrompt]]]
                        ]
                    ]);

                    if ($titleResponse->successful()) {
                        $titleData = $titleResponse->json();
                        $generatedTitle = $titleData['candidates'][0]['content']['parts'][0]['text'] ?? 'Conversazione';
                        $conversation->update(['title' => trim($generatedTitle)]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Errore generazione titolo (non riproveremo per questa chat):', ['msg' => $e->getMessage()]);
                    // Se fallisce una volta, evitiamo di riprovare sui messaggi successivi per non sprecare quota
                    $conversation->update(['title' => 'Conversazione']);
                }
            }

            return response()->json([
                'reply' => $responseText,
                'conversation_id' => $conversationId,
                'title' => $conversation->title,
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Timeout comunicazione con AI:', ['exception' => $e->getMessage()]);
            return response()->json([
                'error' => 'Il servizio AI ha impiegato troppo tempo a rispondere. Riprova tra poco.',
            ], 504);
        } catch (\Exception $e) {
            Log::error('Errore imprevisto AiController:', ['exception' => $e->getMessage()]);
            return response()->json([
                'error' => 'Si è verificato un errore interno nel processare la richiesta.',
            ], 500);
        }
    }

    /**
     * Recupera la cronologia delle chat per l'utente loggato.
     * Al momento restituisce una lista vuota o dati di mock.
     */
    public function history(Request $request)
    {
        $conversations = $request->user()->conversations()
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title', 'created_at']);

        return response()->json([
            'conversations' => $conversations,
            'message' => 'Lista conversazioni caricata.'
        ]);
    }

    /**
     * Recupera i messaggi di una specifica conversazione.
     */
    public function show($id, Request $request)
    {
        $conversation = $request->user()->conversations()->findOrFail($id);
        
        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get(['role', 'content as text', 'id']);

        return response()->json([
            'messages' => $messages,
            'title' => $conversation->title,
        ]);
    }

    /**
     * Elimina tutta la cronologia delle chat dell'utente.
     */
    public function destroyHistory(Request $request)
    {
        $user = $request->user();
        
        // Eliminiamo tutte le conversazioni e i relativi messaggi
        $user->conversations()->delete();

        return response()->json([
            'message' => 'Tutta la cronologia è stata eliminata.'
        ]);
    }
}