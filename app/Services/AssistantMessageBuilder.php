<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class AssistantMessageBuilder
{
    private ?AssistantToneAgent $toneAgent;

    private ?string $conversationId = null;

    private ?string $intent = null;

    public function __construct(?AssistantToneAgent $toneAgent = null)
    {
        $this->toneAgent = $toneAgent;
    }

    public function setConversation(?string $conversationId, ?string $intent = null): void
    {
        $this->conversationId = $conversationId;
        $this->intent = $intent;
    }

    public function followUp(?string $variant = null, array $context = []): string
    {
        $variant ??= config('assistant.follow_up_variant', 'default');

        return $this->render("assistant.follow_up.{$variant}", [], $context + ['variant' => $variant]);
    }

    public function withFollowUp(string $message, ?string $variant = null, array $context = []): string
    {
        $followUp = $this->followUp($variant, $context);
        if ($followUp === '') {
            return $message;
        }

        $lineBreak = (string) config('assistant.line_break', '<br>');
        $trimmed = rtrim($message);

        if ($trimmed === '') {
            return $followUp;
        }

        if (!Str::endsWith($trimmed, $lineBreak)) {
            $message = $trimmed . $lineBreak;
        }

        return $message . $followUp;
    }

    public function ticketNone(): string
    {
        return $this->render('assistant.ticket.none');
    }

    public function ticketMixed(): string
    {
        return $this->render('assistant.ticket.mixed');
    }

    public function ticketExpired(): string
    {
        return $this->render('assistant.ticket.expired');
    }

    public function cardNotFound(string $field): string
    {
        return $this->render("assistant.card.{$field}.not_found", [], ['field' => $field]);
    }

    public function cardPartial(string $field): string
    {
        return $this->render("assistant.card.{$field}.partial", [], ['field' => $field]);
    }

    public function cardPlanFilterMissed(array $terms): string
    {
        $display = $this->formatFilterTerms($terms);

        if ($display === '') {
            return $this->cardNotFound('planos');
        }

        return $this->render('assistant.card.planos.filtered_not_found', ['terms' => $display], [
            'terms' => $display,
        ]);
    }

    public function cardFinanceNoEntries(array $financial): string
    {
        $planNames = [];

        foreach ($financial as $item) {
            $planName = $item['plano'] ?? ($item['contrato']['plano'] ?? '');
            if ($planName !== '') {
                $planNames[$planName] = $planName;
            }
        }

        if (empty($planNames)) {
            return $this->cardNotFound('fichafinanceira');
        }

        $names = array_values($planNames);
        $formatted = $this->formatList($names);
        $label = $this->buildPlansLabel($names, $formatted);

        return $this->render('assistant.card.fichafinanceira.no_entries', ['plans_label' => $label], [
            'plans_label' => $label,
        ]);
    }

    public function cardCoparticipationNoEntries(array $coparticipation): string
    {
        $planNames = [];

        foreach ($coparticipation as $item) {
            $planName = $item['plano'] ?? ($item['contrato']['plano'] ?? '');
            if ($planName !== '') {
                $planNames[$planName] = $planName;
            }
        }

        if (empty($planNames)) {
            return $this->cardNotFound('coparticipacao');
        }

        $names = array_values($planNames);
        $formatted = $this->formatList($names);
        $label = $this->buildPlansLabel($names, $formatted);

        return $this->render('assistant.card.coparticipacao.no_entries', ['plans_label' => $label], [
            'plans_label' => $label,
        ]);
    }

    public function requestCpfForField(?string $field): string
    {
        $key = $this->resolveCpfFieldKey($field);

        return $this->render("assistant.cpf_request.{$key}");
    }

    public function loginRequired(string $labelKeyOrValue, array $context = []): string
    {
        $label = $this->resolveLabel($labelKeyOrValue);

        return $this->render('assistant.login.required.generic', ['label' => $label], $context + ['label' => $label]);
    }

    public function loginRequiredWithCpf(string $labelKeyOrValue, array $context = []): string
    {
        $label = $this->resolveLabel($labelKeyOrValue);

        return $this->render('assistant.login.required.combined', ['label' => $label], $context + ['label' => $label]);
    }

    public function loginRequiredIr(array $context = []): string
    {
        return $this->render('assistant.login.required.ir', [], $context);
    }

    private function resolveLabel(string $labelKeyOrValue): string
    {
        $key = "assistant.labels.{$labelKeyOrValue}";
        $value = Lang::get($key);

        if ($value !== $key) {
            return (string) $value;
        }

        if ($labelKeyOrValue !== '') {
            return $labelKeyOrValue;
        }

        $fallback = Lang::get('assistant.labels.default');

        return $fallback !== 'assistant.labels.default' ? (string) $fallback : 'sua carteirinha';
    }

    private function render(string $key, array $params = [], array $context = []): string
    {
        $raw = Lang::get($key);

        if (is_array($raw)) {
            $raw = $this->chooseVariant($key, $raw, $context);
        }

        if (!is_string($raw) || $raw === '') {
            return '';
        }

        if (!empty($params)) {
            foreach ($params as $name => $value) {
                $raw = str_replace(':' . $name, $value, $raw);
            }
        }

        if ($this->toneAgent instanceof AssistantToneAgent) {
            return $this->toneAgent->humanize($key, $raw, $context + [
                'intent' => $this->intent,
                'conversation_id' => $this->conversationId,
            ]);
        }

        return $raw;
    }

    private function chooseVariant(string $key, array $options, array $context = []): string
    {
        $variants = array_values(array_filter(Arr::wrap($options), static fn ($item) => is_string($item) && $item !== ''));

        if (empty($variants)) {
            return '';
        }

        $shouldRotate = Str::startsWith($key, 'assistant.follow_up');

        if ($shouldRotate && $this->conversationId) {
            $variantName = $context['variant'] ?? 'default';
            $cacheKey = sprintf(
                'assistant_follow_up_index:%s:%s',
                $this->conversationId,
                md5($variantName ?: 'default')
            );

            $currentIndex = Cache::get($cacheKey);
            if ($currentIndex === null) {
                $nextIndex = 0;
            } else {
                $nextIndex = ($currentIndex + 1) % count($variants);
            }

            Cache::put($cacheKey, $nextIndex, 3600);

            return $variants[$nextIndex];
        }

        $seed = $context['seed'] ?? $this->conversationId;

        if ($seed === null) {
            return $variants[array_rand($variants)];
        }

        $index = crc32($seed . '|' . $key) % count($variants);

        return $variants[$index];
    }

    private function formatFilterTerms(array $terms): string
    {
        $clean = [];

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            $clean[] = '"' . Str::title($term) . '"';
        }

        $clean = array_values(array_unique($clean));

        return $this->formatList($clean);
    }

    private function formatList(array $items): string
    {
        $items = array_values(array_filter($items, static fn ($item) => $item !== ''));
        $count = count($items);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $items[0];
        }

        if ($count === 2) {
            return implode(' e ', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' e ' . $last;
    }

    private function buildPlansLabel(array $names, string $formatted): string
    {
        if ($formatted === '') {
            return '';
        }

        return count($names) > 1 ? 'os planos ' . $formatted : 'o plano ' . $formatted;
    }

    private function resolveCpfFieldKey(?string $field): string
    {
        return match ($field) {
            'planos' => 'planos',
            'fichafinanceira' => 'fichafinanceira',
            'coparticipacao' => 'coparticipacao',
            default => 'default',
        };
    }
}
