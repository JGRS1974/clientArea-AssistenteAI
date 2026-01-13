<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class EvolutionWebhookNormalizer
{
    /**
     * Normaliza um webhook da Evolution API para o formato interno do chat:
     *  - text: texto da mensagem
     *  - phone: número E.164 sem sufixos do WhatsApp
     *  - media: ['type' => 'audio|image|document', 'url' => ...] quando existir
     */
    public function normalize(array $payload): array
    {
        $fromMe = (bool) Arr::get($payload, 'data.key.fromMe', false);

        $remoteJid = Arr::get($payload, 'data.key.remoteJid');
        $remoteJidAlt = Arr::get($payload, 'data.key.remoteJidAlt');
        $addressingMode = Arr::get($payload, 'data.key.addressingMode');

        $chosenFrom = null;
        $chosenFromSource = null;

        // Quando o addressingMode for "lid", o remoteJid pode vir como "<id>@lid" (ex.: self-chat).
        // Nesses casos, preferimos o remoteJidAlt quando disponível (ex.: "<e164>@s.whatsapp.net"),
        // pois é o identificador que pode ser usado para enviar mensagens de volta.
        $remoteJidStr = is_string($remoteJid) ? $remoteJid : '';
        $isLid = ($remoteJidStr !== '' && Str::endsWith(Str::lower($remoteJidStr), '@lid'))
            || (is_string($addressingMode) && Str::lower($addressingMode) === 'lid');

        if ($isLid && is_string($remoteJidAlt) && trim($remoteJidAlt) !== '') {
            $chosenFrom = $remoteJidAlt;
            $chosenFromSource = 'data.key.remoteJidAlt';
        } elseif ($isLid && $fromMe && is_string(Arr::get($payload, 'sender')) && trim((string) Arr::get($payload, 'sender')) !== '') {
            // Fallback conservador para self-chat quando não há remoteJidAlt.
            $chosenFrom = Arr::get($payload, 'sender');
            $chosenFromSource = 'sender';
        } else {
            $chosenFrom = $remoteJid;
            $chosenFromSource = 'data.key.remoteJid';
        }

        // Prioriza o remoteJid (número do usuário) em vez de sender (número da instância)
        $from = $chosenFrom
            ?? Arr::get($payload, 'data.key.participant') // grupos não serão usados, mas mantém como fallback
            ?? Arr::get($payload, 'from')
            ?? Arr::get($payload, 'message.from')
            ?? Arr::get($payload, 'sender') // último recurso
            ?? '';

        $phone = $this->normalizePhone($from);
        $text = $this->extractText($payload);

        // Log breve para auditoria da seleção do telefone (sem despejar payload)
        try {
            Log::info('Normalizer.phone', [
                'event' => Arr::get($payload, 'event'),
                'remoteJid' => Arr::get($payload, 'data.key.remoteJid'),
                'remoteJidAlt' => Arr::get($payload, 'data.key.remoteJidAlt'),
                'addressingMode' => Arr::get($payload, 'data.key.addressingMode'),
                'participant' => Arr::get($payload, 'data.key.participant'),
                'sender' => Arr::get($payload, 'sender'),
                'chosen_from_source' => $chosenFromSource,
                'chosen_from' => $from,
                'phone' => $phone,
                'from_me' => (bool) Arr::get($payload, 'data.key.fromMe', false),
            ]);
        } catch (\Throwable $e) {
            // ignora falhas de log
        }

        [$media, $messageType] = $this->extractMedia($payload);

        if ($text === null && $media && isset($media['caption'])) {
            $text = $media['caption'];
        }

        if (is_string($text)) {
            $text = trim($text);
            if ($text === '') {
                $text = null;
            }
        }

        return [
            'text' => $text,
            'phone' => $phone,
            'media' => $media,
            'type' => $messageType,
            'from_me' => $fromMe,
        ];
    }

    private function extractText(array $payload): ?string
    {
        $paths = [
            'data.message.conversation',
            'data.message.extendedTextMessage.text',
            'data.message.buttonsResponseMessage.selectedDisplayText',
            'data.message.listResponseMessage.title',
            'data.message.imageMessage.caption',
            'data.message.videoMessage.caption',
            'data.message.documentMessage.caption',
            'text',
            'message.text',
            'body',
        ];

        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?array, 1: ?string}
     */
    private function extractMedia(array $payload): array
    {
        $messageType = Arr::get($payload, 'data.messageType')
            ?? Arr::get($payload, 'messageType')
            ?? Arr::get($payload, 'type');

        $messageType = is_string($messageType) ? Str::lower($messageType) : null;

        $media = null;

        // Diagnóstico: presença de base64/urls sem despejar conteúdo pesado
        try {
            Log::info('Normalizer.extractMedia.enter', [
                'messageType' => $messageType,
                'hasAudioB64' => (bool) Arr::get($payload, 'data.message.audioMessage.base64'),
                'hasImageB64' => (bool) Arr::get($payload, 'data.message.imageMessage.base64'),
                'hasVideoB64' => (bool) Arr::get($payload, 'data.message.videoMessage.base64'),
                'hasDocB64' => (bool) Arr::get($payload, 'data.message.documentMessage.base64'),
                'hasMsgLevelB64' => (bool) Arr::get($payload, 'data.message.base64'),
                'hasRootB64' => (bool) Arr::get($payload, 'base64'),
                'audioUrl' => Arr::get($payload, 'data.message.audioMessage.url'),
                'imageUrl' => Arr::get($payload, 'data.message.imageMessage.url'),
                'videoUrl' => Arr::get($payload, 'data.message.videoMessage.url'),
                'docUrl' => Arr::get($payload, 'data.message.documentMessage.url'),
            ]);
        } catch (\Throwable $e) {
            // ignora falhas de log
        }

        $audio = Arr::get($payload, 'data.message.audioMessage')
            ?? Arr::get($payload, 'message.audioMessage');
        if (!$media && is_array($audio)) {
            $base64 = Arr::get($audio, 'base64')
                ?: Arr::get($payload, 'data.message.base64')
                ?: Arr::get($payload, 'base64');
            if (!empty($base64)) {
                $media = [
                    'type' => 'audio',
                    'base64' => $base64,
                    'mimetype' => Arr::get($audio, 'mimetype'),
                    'ptt' => (bool) Arr::get($audio, 'ptt', false),
                ];
                $messageType = 'audio';
            } elseif (!empty($audio['url'])) {
                $media = [
                    'type' => 'audio',
                    'url' => $audio['url'],
                    'mimetype' => Arr::get($audio, 'mimetype'),
                    'ptt' => (bool) Arr::get($audio, 'ptt', false),
                ];
                $messageType = 'audio';
            }
            if (!$media && Arr::get($payload, 'data.message.base64')) {
                Log::info('Normalizer.extractMedia.note', [
                    'hint' => 'audio base64 encontrado em data.message.base64, mas nó audioMessage sem base64/url',
                ]);
            }
        }

        $image = Arr::get($payload, 'data.message.imageMessage')
            ?? Arr::get($payload, 'message.imageMessage');
        if (!$media && is_array($image)) {
            $base64 = Arr::get($image, 'base64')
                ?: Arr::get($payload, 'data.message.base64')
                ?: Arr::get($payload, 'base64');
            if (!empty($base64)) {
                $media = [
                    'type' => 'image',
                    'base64' => $base64,
                    'mimetype' => Arr::get($image, 'mimetype'),
                    'caption' => Arr::get($image, 'caption'),
                ];
                $messageType = 'image';
            } elseif (!empty($image['url'])) {
                $media = [
                    'type' => 'image',
                    'url' => $image['url'],
                    'mimetype' => Arr::get($image, 'mimetype'),
                    'caption' => Arr::get($image, 'caption'),
                ];
                $messageType = 'image';
            }
            if (!$media && Arr::get($payload, 'data.message.base64')) {
                Log::info('Normalizer.extractMedia.note', [
                    'hint' => 'image base64 encontrado em data.message.base64, mas nó imageMessage sem base64/url',
                ]);
            }
        }

        $document = Arr::get($payload, 'data.message.documentMessage')
            ?? Arr::get($payload, 'message.documentMessage');
        if (!$media && is_array($document)) {
            $base64 = Arr::get($document, 'base64')
                ?: Arr::get($payload, 'data.message.base64')
                ?: Arr::get($payload, 'base64');
            if (!empty($base64)) {
                $media = [
                    'type' => 'document',
                    'base64' => $base64,
                    'mimetype' => Arr::get($document, 'mimetype'),
                    'caption' => Arr::get($document, 'caption'),
                    'fileName' => Arr::get($document, 'fileName'),
                ];
                $messageType = 'document';
            } elseif (!empty($document['url'])) {
                $media = [
                    'type' => 'document',
                    'url' => $document['url'],
                    'mimetype' => Arr::get($document, 'mimetype'),
                    'caption' => Arr::get($document, 'caption'),
                    'fileName' => Arr::get($document, 'fileName'),
                ];
                $messageType = 'document';
            }
            if (!$media && Arr::get($payload, 'data.message.base64')) {
                Log::info('Normalizer.extractMedia.note', [
                    'hint' => 'document base64 encontrado em data.message.base64, mas nó documentMessage sem base64/url',
                ]);
            }
        }

        $video = Arr::get($payload, 'data.message.videoMessage')
            ?? Arr::get($payload, 'message.videoMessage');
        if (!$media && is_array($video)) {
            $base64 = Arr::get($video, 'base64')
                ?: Arr::get($payload, 'data.message.base64')
                ?: Arr::get($payload, 'base64');
            if (!empty($base64)) {
                $media = [
                    'type' => 'video',
                    'base64' => $base64,
                    'mimetype' => Arr::get($video, 'mimetype'),
                    'caption' => Arr::get($video, 'caption'),
                ];
                $messageType = 'video';
            } elseif (!empty($video['url'])) {
                $media = [
                    'type' => 'video',
                    'url' => $video['url'],
                    'mimetype' => Arr::get($video, 'mimetype'),
                    'caption' => Arr::get($video, 'caption'),
                ];
                $messageType = 'video';
            }
            if (!$media && Arr::get($payload, 'data.message.base64')) {
                Log::info('Normalizer.extractMedia.note', [
                    'hint' => 'video base64 encontrado em data.message.base64, mas nó videoMessage sem base64/url',
                ]);
            }
        }

        if (!$media && ($genericUrl = Arr::get($payload, 'mediaUrl'))) {
            $kind = match ($messageType) {
                'audio', 'ptt' => 'audio',
                'image' => 'image',
                'document', 'pdf' => 'document',
                'video' => 'video',
                default => 'document',
            };

            $media = [
                'type' => $kind,
                'url' => $genericUrl,
            ];
        }

        // Fallback final: se houver base64 em nível raiz e o tipo indicar mídia,
        // cria um registro de mídia baseado no tipo inferido
        if (!$media && ($rootB64 = Arr::get($payload, 'base64'))) {
            $kind = match ($messageType) {
                'audio', 'ptt', 'audiomessage' => 'audio',
                'image', 'imagemessage' => 'image',
                'document', 'pdf', 'documentmessage' => 'document',
                'video', 'videomessage' => 'video',
                default => null,
            };
            if ($kind) {
                $media = [
                    'type' => $kind,
                    'base64' => $rootB64,
                ];
            }
        }

        try {
            Log::info('Normalizer.extractMedia.result', [
                'messageType' => $messageType,
                'media' => [
                    'type' => $media['type'] ?? null,
                    'has_base64' => isset($media['base64']),
                    'base64_len' => isset($media['base64']) ? strlen($media['base64']) : 0,
                    'has_url' => !empty($media['url'] ?? null),
                    'mimetype' => $media['mimetype'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            // ignora falhas de log
        }

        return [$media, $messageType];
    }

    private function normalizePhone(?string $from): string
    {
        $from = (string) $from;
        // Exemplos: "551199999999@s.whatsapp.net" ou "551199999999"
        $from = preg_replace('/@.*/', '', $from);
        $digits = preg_replace('/\D/', '', $from);
        return $digits ?? '';
    }
}
