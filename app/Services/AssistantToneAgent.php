<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AssistantToneAgent
{
    public function enabled(): bool
    {
        return (bool) config('assistant.tone_agent.enabled', false);
    }

    public function humanize(string $messageKey, string $baseText, array $context = []): string
    {
        if (!$this->enabled()) {
            return $baseText;
        }

        try {
            $systemPrompt = new SystemMessage('Você é um agente de comunicação cuja função é ajustar mensagens de atendimento para soar humana, cordial e objetiva. Mantenha o significado e preserve marcações HTML como <br>.');

            $details = $this->buildUserPrompt($messageKey, $baseText, $context);

            $response = Prism::text()
                ->using(Provider::OpenAI, config('assistant.tone_agent.model', 'gpt-4.1-mini'))
                ->withMessages([
                    $systemPrompt,
                    new UserMessage($details),
                ])
                ->withMaxSteps(config('assistant.tone_agent.max_steps', 1))
                ->asText();

            $text = trim($response->text ?? '');

            return $text !== '' ? $text : $baseText;
        } catch (\Throwable $exception) {
            Log::warning('AssistantToneAgent unable to humanize message', [
                'key' => $messageKey,
                'error' => $exception->getMessage(),
            ]);

            return $baseText;
        }
    }

    private function buildUserPrompt(string $messageKey, string $baseText, array $context): string
    {
        $tone = $context['tone'] ?? 'acolhedor, empático e direto';
        $audience = $context['audience'] ?? 'clientes em atendimento digital';
        $intent = $context['intent'] ?? null;

        $notes = [];
        if ($intent) {
            $notes[] = "Intenção atual: {$intent}.";
        }

        if (isset($context['label'])) {
            $notes[] = "Contexto do dado: {$context['label']}.";
        }

        if (isset($context['conversation_id'])) {
            $notes[] = 'A mensagem pertence à conversa ' . $context['conversation_id'] . '. Não personalize com esse identificador.';
        }

        $notes[] = 'Não invente dados, apenas reorganize o texto.';
        $notes[] = 'Se o texto já estiver natural, devolva-o sem alterações.';

        $notesText = implode("\n", $notes);

        return <<<PROMPT
Preciso que você reformule a mensagem abaixo para soar mais humana e natural, mantendo instruções e marcações HTML intactas.

Tom esperado: {$tone}.
Público: {$audience}.

{$notesText}

Texto original:
"""
{$baseText}
"""
PROMPT;
    }
}
