<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;
use App\Services\ApiConsumerService;

class WhatsAppSender
{
    /** @var ApiConsumerService */
    private $api;

    public function __construct(ApiConsumerService $api)
    {
        $this->api = $api;
    }

    private function normalizeDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?: '';
    }

    private function toRemoteJid(string $phone): string
    {
        $digits = $this->normalizeDigits($phone);
        return $digits !== '' ? ($digits . '@s.whatsapp.net') : '';
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

        $timeoutSeconds = (int) env('EVOLUTION_SEND_AUDIO_TIMEOUT_SECONDS', 60);
        return $this->api->apiConsumer($payload, $url, $headers, $timeoutSeconds > 0 ? $timeoutSeconds : 60);
    }

    /**
     * Envia presença (ex.: "composing") via Evolution API v2.
     * Útil para indicar processamento sem enviar mensagem.
     */
    public function sendPresence(string $phone, string $presence = 'composing', int $delayMs = 20000): array
    {
        $base = rtrim(trim((string) env('EVOLUTION_BASE_URL')), '/');
        $instance = trim((string) env('EVOLUTION_INSTANCE'));
        $token = env('EVOLUTION_API_KEY');

        $url = sprintf('%s/chat/sendPresence/%s', $base, $instance);
        // Evolution v2: body possui "number" e "options"
        $payload = [
            'number' => $phone,
            'options' => [
                'delay' => $delayMs,
                'presence' => $presence,
                'number' => $phone,
            ],
        ];

        $headers = [
            'apikey: ' . $token,
        ];

        return $this->api->apiConsumer($payload, $url, $headers, 10);
    }

    /**
     * Busca status de mensagem via Evolution API v2.
     * Usado para confirmar envio/ack quando o sendWhatsAppAudio estoura timeout.
     */
    public function findStatusMessage(string $phoneOrRemoteJid, ?string $messageId = null, int $limit = 5): array
    {
        $base = rtrim(trim((string) env('EVOLUTION_BASE_URL')), '/');
        $instance = trim((string) env('EVOLUTION_INSTANCE'));
        $token = env('EVOLUTION_API_KEY');

        $url = sprintf('%s/chat/findStatusMessage/%s', $base, $instance);
        $remoteJid = str_contains($phoneOrRemoteJid, '@') ? $phoneOrRemoteJid : $this->toRemoteJid($phoneOrRemoteJid);

        // Evolution v2: where é direto (não é "where.key")
        $where = array_filter([
            'remoteJid' => $remoteJid !== '' ? $remoteJid : null,
            'fromMe' => true,
            'id' => $messageId,
        ], fn($v) => $v !== null && $v !== '');

        $payload = [
            'where' => $where,
        ];

        $headers = [
            'apikey: ' . $token,
        ];

        return $this->api->apiConsumer($payload, $url, $headers, 15);
    }

    /**
     * Busca mensagens via Evolution API v2.
     * Útil para recuperar o messageId do último áudio enviado quando não o temos localmente.
     */
    public function findMessages(string $phoneOrRemoteJid, int $limit = 10): array
    {
        $base = rtrim(trim((string) env('EVOLUTION_BASE_URL')), '/');
        $instance = trim((string) env('EVOLUTION_INSTANCE'));
        $token = env('EVOLUTION_API_KEY');

        $url = sprintf('%s/chat/findMessages/%s', $base, $instance);
        $remoteJid = str_contains($phoneOrRemoteJid, '@') ? $phoneOrRemoteJid : $this->toRemoteJid($phoneOrRemoteJid);

        // Evolution v2: where.key.remoteJid
        $payload = [
            'where' => [
                'key' => [
                    'remoteJid' => $remoteJid,
                ],
            ],
        ];

        $headers = [
            'apikey: ' . $token,
        ];

        return $this->api->apiConsumer($payload, $url, $headers, 20);
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
