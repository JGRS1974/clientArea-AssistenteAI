<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class IntentClassifierService
{
    /**
     * Classifica a intenção da mensagem do usuário.
     * Retorna um array com:
     * - intent: 'ticket'|'card'|'ir'|'unknown'
     * - confidence: float (0..1)
     * - slots: array (ex.: ['year' => '2024'])
     */
    public function classify(string $text, array $history = [], ?string $channel = null, array $context = []): array
    {
        // Primeiro, tenta classificação via LLM (Prism). Fallback para heurística local.
        try {
            $normalized = $this->normalize($text);

            $messages = [];
            $messages[] = new SystemMessage(view('prompts.intent-classifier', [
                'channel' => $channel ?: 'web',
                'context' => [
                    'previous_intent' => $context['previous_intent'] ?? null,
                    'last_tool' => $context['last_tool'] ?? null,
                    'last_card_primary_field' => $context['last_card_primary_field'] ?? null,
                    'last_requested_fields' => $context['last_requested_fields'] ?? [],
                ],
            ])->render());

            // Inclui somente o texto atual explicitamente
            $messages[] = new UserMessage($normalized);

            // Opcional: pode-se incluir breve contexto de turns anteriores do usuário
            // Mantemos simples para reduzir custo e latência

            Log::info('IntentClassifier.request', [
                'channel' => $channel ?: 'web',
                'norm_len' => mb_strlen($normalized),
            ]);
            $resp = Prism::text()
                ->using(Provider::OpenAI, 'gpt-4.1')
                ->withMessages($messages)
                ->withMaxSteps(1)
                ->withProviderOptions(['temperature' => 0.0])
                ->asText();

            $raw = is_object($resp) && isset($resp->text) ? (string) $resp->text : (string) $resp;
            $parsed = json_decode(trim($raw), true);

            if (is_array($parsed)) {
                $intent = $parsed['intent'] ?? 'unknown';
                $confidence = (float) ($parsed['confidence'] ?? 0.0);
                $slots = is_array($parsed['slots'] ?? null) ? $parsed['slots'] : [];

                if (in_array($intent, ['ticket', 'card', 'ir', 'unknown'], true)) {
                    Log::info('IntentClassifier.result', [
                        'source' => 'llm',
                        'intent' => $intent,
                        'confidence' => $confidence,
                        'slots' => $slots,
                    ]);
                    return [
                        'intent' => $intent,
                        'confidence' => max(0.0, min(1.0, $confidence)),
                        'slots' => $slots,
                    ];
                }
            }

            // Se saída inválida, cai para heurística
            Log::warning('IntentClassifier LLM returned invalid JSON', ['raw' => mb_substr($raw ?? '', 0, 500)]);
        } catch (PrismException $e) {
            Log::warning('IntentClassifier PrismException', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('IntentClassifier generic failure', ['error' => $e->getMessage()]);
        }

        // Fallback heurístico (tolerante a typos comuns)
        $normalized = $this->normalize($text);
        $previousIntent = $context['previous_intent'] ?? null;
        if ($this->isTicketStrong($normalized)) {
            $out = ['intent' => 'ticket', 'confidence' => 0.8, 'slots' => []];
            Log::info('IntentClassifier.result', ['source' => 'fallback', 'intent' => $out['intent'], 'confidence' => $out['confidence']]);
            return $out;
        }
        if ($this->isIrStrong($normalized) || $this->isIrModerate($normalized)) {
            $out = ['intent' => 'ir', 'confidence' => 0.75, 'slots' => ['year' => $this->extractYear($normalized)]];
            Log::info('IntentClassifier.result', ['source' => 'fallback', 'intent' => $out['intent'], 'confidence' => $out['confidence']]);
            return $out;
        }
        if ($this->isCardStrong($normalized) || $this->isCardModerate($normalized)) {
            $out = ['intent' => 'card', 'confidence' => 0.7, 'slots' => []];
            Log::info('IntentClassifier.result', ['source' => 'fallback', 'intent' => $out['intent'], 'confidence' => $out['confidence']]);
            return $out;
        }
        // Heurística contextual: mensagem elíptica após 'card'
        if (is_string($previousIntent) && $previousIntent === 'card') {
            if (preg_match('/\b(cancelad[oa]s?|vigent[ea]s?|ativos?)\b/u', $normalized)) {
                $out = ['intent' => 'card', 'confidence' => 0.7, 'slots' => ['subfields' => ['planos']]];
                Log::info('IntentClassifier.result', ['source' => 'fallback_ctx', 'intent' => $out['intent'], 'confidence' => $out['confidence']]);
                return $out;
            }
        }
        if ($this->mentionsSomethingKnown($normalized)) {
            $out = ['intent' => 'unknown', 'confidence' => 0.45, 'slots' => []];
            Log::info('IntentClassifier.result', ['source' => 'fallback', 'intent' => $out['intent'], 'confidence' => $out['confidence']]);
            return $out;
        }
        $out = ['intent' => 'unknown', 'confidence' => 0.2, 'slots' => []];
        Log::info('IntentClassifier.result', ['source' => 'fallback', 'intent' => $out['intent'], 'confidence' => $out['confidence']]);
        return $out;
    }

    private function normalize(string $text): string
    {
        $text = (string) $text;
        // Remove marcadores como "[Áudio transcrito]:" e "[Imagem analisada]:"
        $text = preg_replace('/\[[^\]]+\]:?/u', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = Str::ascii($text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function isTicketStrong(string $t): bool
    {
        // boleto / cobrança / fatura / segunda via
        return (bool) (
            preg_match('/\b(boletos?|cobrancas?|faturas?)\b/u', $t) ||
            preg_match('/\b(segunda\s+via|2a\s*via)\b/u', $t)
        );
    }

    private function isIrStrong(string $t): bool
    {
        return (bool) (
            preg_match('/\b(informes?\s*(?:de)?\s*rendimentos?)\b/u', $t) ||
            preg_match('/\b(informe\s*ir|ir\s*20\d{2}|irpf)\b/u', $t) ||
            preg_match('/\b(imposto\s*de\s*renda|dirf|comprovante\s*(?:do\s*)?imposto\s*de\s*renda)\b/u', $t)
        );
    }

    private function isCardStrong(string $t): bool
    {
        return (bool) (
            preg_match('/carteir|cart[\x{00E3}a]o\s*virtual|documento\s*digital/u', $t) ||
            preg_match('/\b(planos?|contratos?)\b/u', $t) ||
            preg_match('/relat[\x{00F3}o]rio\s*financeir[oa]|ficha\s*financeir[oa]|\bfinanceir[oa]\b/u', $t) ||
            preg_match('/co[-\s]?participa[c\x{00E7}][a\x{00E3}]o/u', $t)
        );
    }

    private function isIrModerate(string $t): bool
    {
        // Tolerante a typos: "impost de renda", "impto renda"
        if (preg_match('/\bimpost\b/u', $t) && preg_match('/\brenda\b/u', $t)) {
            return true;
        }
        if (preg_match('/\bimpto\b/u', $t) && preg_match('/\brenda\b/u', $t)) {
            return true;
        }
        if (preg_match('/\binforme\s*(?:de\s*)?rend/u', $t)) {
            return true;
        }
        if (preg_match('/\bir\b/u', $t) && preg_match('/\b(20\d{2})\b/u', $t)) {
            return true;
        }
        return false;
    }

    private function isCardModerate(string $t): bool
    {
        if (preg_match('/\b(minha|meu|sua|seu)\s+carteir/u', $t)) {
            return true;
        }
        if (preg_match('/\bdados\s+do\s+plano\b/u', $t)) {
            return true;
        }
        if (preg_match('/\b(meus?|seus?)\s+pagamentos\b/u', $t)) {
            return true;
        }
        return false;
    }

    private function mentionsSomethingKnown(string $t): bool
    {
        return (bool) (
            preg_match('/bolet|cobran|fatur/u', $t) ||
            preg_match('/impost|renda|irpf|informe/u', $t) ||
            preg_match('/carteir|plano|contrato|financeir|copart/u', $t)
        );
    }

    private function extractYear(string $t): ?string
    {
        if (preg_match('/\b(20\d{2})\b/u', $t, $m)) {
            return $m[1];
        }
        return null;
    }
}
