<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConversationIdService
{

    // Gera o ID da conversa
    public function setConversationId($request)
    {
        if (!$request->filled('conversation_id')) {
            $conversationId = (string) Str::uuid();
        } else{
            $conversationId = $request->conversation_id;
        }

        return $conversationId;
    }

}
