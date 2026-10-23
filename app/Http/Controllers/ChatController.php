<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $conv = $user->conversations()->latest()->first() ?? $user->conversations()->create();
        return redirect()->route('chat.show', $conv);
    }

    public function create()
    {
        $conv = auth()->user()->conversations()->create();
        return redirect()->route('chat.show', $conv);
    }

    public function show(Conversation $conversation)
    {
        abort_unless($conversation->user_id === auth()->id(), 403);

        return view('chat', [
            'conversation'  => $conversation,
            'conversations' => auth()->user()->conversations()->latest()->get(),
            'messages'      => $conversation->messages()->get(),
        ]);
    }

    public function stream(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->user_id === auth()->id(), 403);
        $request->validate(['message' => 'required|string|max:4000']);

        $conversation->messages()->create(['role' => 'user', 'content' => $request->message]);

        if ($conversation->title === 'New chat') {
            $conversation->update(['title' => Str::limit($request->message, 40)]);
        }

        $history = $conversation->messages()->latest('id')->take(20)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()->all();

        return response()->stream(function () use ($conversation, $history) {
            set_time_limit(0);
            $full = '';

            $res = (new \GuzzleHttp\Client(['timeout' => 0]))->post(
                config('services.ollama.url') . '/api/chat',
                [
                    'json' => [
                        'model'    => config('services.ollama.model'),
                        'messages' => $history,
                        'stream'   => true,
                    ],
                    'stream' => true,
                ]
            );

            $body = $res->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $buffer .= $body->read(1024);
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '') continue;

                    $token = json_decode($line, true)['message']['content'] ?? '';
                    if ($token !== '') {
                        $full .= $token;
                        echo 'data: ' . json_encode(['t' => $token]) . "\n\n";
                        @ob_flush(); flush();
                    }
                }
            }

            $conversation->messages()->create(['role' => 'assistant', 'content' => $full]);
            echo "data: [DONE]\n\n";
            @ob_flush(); flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
