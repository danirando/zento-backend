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
            'message' => 'required|string|max:4000',
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
            // Scoped all'utente autenticato: evita che un utente possa scrivere
            // o leggere il titolo di conversazioni appartenenti ad altri utenti.
            $conversation = $user->conversations()->findOrFail($conversationId);
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

        $model = 'gemini-3.6-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            // --- OTTIMIZZAZIONE 1: Intercettore locale per saluti comuni ---
            $lowerPrompt = mb_strtolower(trim($prompt));
            $localResponses = [
                'ciao' => "Ciao! Come posso aiutarti oggi?",
                'ehi' => "Ehi! In cosa posso esserti utile?",
                'hello' => "Hello! How can I help you today?",
                'chi sei' => "Sono Zento, la tua assistente AI. Come posso aiutarti?",
                'chi sei?' => "Sono Zento, la tua assistente AI. Come posso aiutarti?",
                'come stai' => "Sto bene, grazie! Pronta ad aiutarmi. E tu?",
                'come stai?' => "Sto bene, grazie! Pronta ad aiutarmi. E tu?",

                // Suggerimenti della dashboard (Case-insensitive match handled by mb_strtolower above)
                "spiegami come funziona l'intelligenza artificiale" => "L'intelligenza artificiale (IA) è un ramo dell'informatica che si occupa di creare sistemi capaci di simulare processi cognitivi umani, come l'apprendimento, il ragionamento e la risoluzione di problemi. Funziona principalmente elaborando enormi quantità di dati attraverso algoritmi di 'machine learning', che permettono al computer di identificare schemi e migliorare le proprie prestazioni nel tempo senza essere esplicitamente programmato per ogni singola attività.",
                "aiutami a scrivere un'email professionale" => "Certamente! Ecco una bozza standard:\n\nOggetto: [Oggetto dell'email]\n\nGentile [Nome del destinatario],\n\nspero che questa email la trovi bene. Le scrivo in merito a [Motivo dell'email].\n\n[Dettagli aggiuntivi...]\n\nResto in attesa di un suo gentile riscontro.\n\nCordiali saluti,\n[Tuo Nome]",
                "dammi idee per un progetto creativo" => "Ecco tre idee interessanti:\n1. **App di Micro-Journaling**: Un'app che ti chiede solo una parola al giorno per descrivere il tuo umore.\n2. **Galleria d'Arte Virtuale**: Un sito web dove artisti locali possono esporre le proprie opere in 3D.\n3. **Ricettario Anti-Spreco**: Un sistema che genera ricette basandosi solo sugli ingredienti rimasti nel frigorifero.",
                "riassumi le ultime notizie di tecnologia" => "Oggi nel mondo tech si parla molto di:\n- **Progressi nei modelli LLM**: Nuovi aggiornamenti che rendono le AI ancora più veloci ed efficienti.\n- **Sostenibilità nei Data Center**: Aziende che testano nuovi sistemi di raffreddamento a basso impatto.\n- **Realtà Aumentata**: Lancio di nuovi dispositivi indossabili sempre più leggeri e potenti.",
            ];

            if (collect(array_keys($localResponses))->contains($lowerPrompt)) {
                $responseText = $localResponses[$lowerPrompt];
            } else {
                // Se non è un saluto comune, procediamo con Gemini
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
            }

            // 4.5 Salvataggio della risposta dell'AI (sia locale che da Gemini)
            ChatMessage::create([
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
                'role' => 'assistant',
                'content' => $responseText,
            ]);

            // --- OTTIMIZZAZIONE 2: Generazione titolo via substring (risparmia 1 chiamata API) ---
            if ($isNewConversation) {
                $cleanPrompt = strip_tags($prompt);
                $newTitle = mb_substr($cleanPrompt, 0, 35);
                if (mb_strlen($cleanPrompt) > 35) {
                    $newTitle .= '...';
                }
                $conversation->update(['title' => $newTitle]);
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
            ->get(['role', 'content as text', 'id', 'is_saved'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'sender' => $m->role === 'user' ? 'user' : 'ai',
                'text' => $m->text,
                'isSaved' => $m->is_saved,
            ]);

        return response()->json([
            'messages' => $messages,
            'title' => $conversation->title,
        ]);
    }

    /**
     * Segna/rimuove il flag "salvato" su un messaggio dell'utente autenticato.
     */
    public function saveMessage(Request $request)
    {
        $request->validate([
            'message_id' => 'required|exists:chat_messages,id',
        ]);

        // Scoped all'utente: un utente può segnare come salvato solo un
        // messaggio che gli appartiene.
        $message = $request->user()->chatMessages()->findOrFail($request->input('message_id'));

        $message->update(['is_saved' => !$message->is_saved]);

        return response()->json([
            'message_id' => $message->id,
            'is_saved' => $message->is_saved,
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
