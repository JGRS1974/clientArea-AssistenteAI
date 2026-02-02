<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;
use App\Services\ApiConsumerService;

class WhatsAppSender
{
    public function __construct(private ApiConsumerService $api)
    {
    }

    /**
     * Envia uma mensagem de texto via Evolution API.
     */
    public function sendText(string $phone, string $text): array
    {
        $base = rtrim(trim((string) env('EVOLUTION_BASE_URL')), '/');
        $instance = trim((string) env('EVOLUTION_INSTANCE'));
        $token = env('EVOLUTION_API_KEY');

        //$url = sprintf('%s/v2/%s/message/send-text', $base, $instance);
        $url = sprintf('%s/message/sendText/%s', $base, $instance);

        $payload = [
            'number' => $phone,
            'text' => $text,
        ];
        Log::info('sendText payload',[$payload]);
        $headers = [
            'apikey: ' . $token,
        ];

        return $this->api->apiConsumer($payload, $url, $headers, 30);
    }

    /**
     * Envia um áudio (ex.: TTS gerado pelo backend) via Evolution API..
     * $audioUrl deve apontar para um arquivo acessível publicamente.
     */
    public function sendAudioByUrl(string $phone, string $audioUrl, bool $asPtt = true): array
    {
        $base = rtrim(trim((string) env('EVOLUTION_BASE_URL')), '/');
        $instance = trim((string) env('EVOLUTION_INSTANCE'));
        $token = env('EVOLUTION_API_KEY');

        //$url = sprintf('%s/v2/%s/message/send-audio', $base, $instance);
        //$url = sprintf('%s/message/sendAudio/%s', $base, $instance);
        $url = sprintf('%s/message/sendWhatsAppAudio/%s', $base, $instance);
        $payload = [
            'number' => $phone,
            'audio' => $audioUrl,
            'ptt' => $asPtt,
        ];

        $headers = [
            'apikey: ' . $token,
        ];

        return $this->api->apiConsumer($payload, $url, $headers, 60);
    }

    public function sendButton(string $phone, string $text, array $buttons, ?string $footer = null): array
    {
        $base = rtrim(trim((string) env('EVOLUTION_BASE_URL')), '/');
        $instance = trim((string) env('EVOLUTION_INSTANCE'));
        $token = env('EVOLUTION_API_KEY');

        $url = sprintf('%s/message/sendButtons/%s', $base, $instance);

        $payload = [
            'number' => $phone,
            'title' => 'Seu documento está pronto 📄',
            'description' => 'Clique no botão abaixo para abrir o PDF no navegador.',
            'footer' => 'Envio automático do sistema',
            'buttons' => $buttons,
        ];

        if ($footer !== null && $footer !== '') {
            $payload['footer'] = $footer;
        }

        $headers = [
            'apikey: ' . $token,
        ];

        Log::info('sendButtons payload', [$payload]);

        return $this->api->apiConsumer($payload, $url, $headers, 30);
    }
}
