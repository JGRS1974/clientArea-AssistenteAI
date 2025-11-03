<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BilletController;
use App\Http\Controllers\Api\AIAssistantController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\TtsAudioController;
use App\Http\Controllers\Api\AIAssistantMultipleInputController;
use App\Http\Controllers\WhatsApp\WhatsAppWebhookController;

//Route::get('/user', function (Request $request) {
//    return $request->user();
//})->middleware('auth:sanctum');

//Route::middleware(['throttle:120,1'])->group(function () {

    // Endpoint principal para chat com AI
    //Route::post('/chat', [AIAssistantController::class, 'chat']);
    Route::post('/chat', [AIAssistantMultipleInputController::class, 'chat']);

    Route::get('/boleto/download/{token}', [BilletController::class, 'downloadPdf'])->name('boleto.download');

    Route::get('/boleto/view/{token}', [BilletController::class, 'viewPdf'])->name('boleto.view');

    // Gerenciamento de conversas
    Route::prefix('conversations')->group(function () {
        Route::get('/{sessionId}', [ConversationController::class, 'getConversation']);
        Route::post('/{sessionId}/reset', [ConversationController::class, 'resetConversation']);
        Route::get('/{sessionId}/history', [ConversationController::class, 'getHistory']);
        Route::delete('/{sessionId}', [ConversationController::class, 'deleteConversation']);
    });

    // Health check
    Route::get('/health-redis', function() {
        try {
            $redis = Redis::connection('conversations');
            $redis->ping();

            return response()->json([
                'status' => 'ok',
                'redis' => 'connected',
                'timestamp' => now()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'redis' => 'disconnected',
                'error' => $e->getMessage()
            ], 500);
        }
    });

    Route::get('/teste-redis', function () {
        return [
            'host' => env('NF_REDISASSISTENTE_HOST'),
            'password' => env('NF_REDISASSISTENTE_PASSWORD'),
            'port' => env('NF_REDISASSISTENTE_PORT'),
        ];
    });
    
    // Webhook da Evolution API (WhatsApp)
    Route::post('/webhooks/evolution', [WhatsAppWebhookController::class, 'incoming'])
        ->name('webhooks.evolution');

    Route::get('/audio/tts/{token}', [TtsAudioController::class, 'stream'])
        ->name('audio.tts.stream');
//});
