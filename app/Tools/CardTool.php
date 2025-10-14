<?php

namespace App\Tools;

use Prism\Prism\Tool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Arr;
use App\Services\ApiConsumerService;
use App\Services\PinGeneratorService;

class CardTool extends Tool
{
    private $apiService;
    protected $pinService;
    private ?string $kw = null;
    protected ?string $conversationId = null;

    public function __construct(ApiConsumerService $apiService, PinGeneratorService $pinService )
    {
        $this->as('card_lookup')
            ->for('Recupera informação da carterinha do cliente pelo CPF informado e após o cliente fazer login no sistema para obter a chave de acesso.')
            ->withStringParameter('cpf', 'CPF do cliente')
            ->using($this);

        $this->apiService = $apiService;
        $this->pinService = $pinService;
    }

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function setKw(?string $kw): self
    {
        $this->kw = $kw;

        return $this;
    }

    public function __invoke(string $cpf, ?string $kw = null)
    {
        $normalizedCpf = $this->normalizeCpf($cpf);
        //Log::info('kw CardTool ' . $this->kw);
        //Log::info('cpf CardTool ' . $normalizedCpf);
        if (!$normalizedCpf) {
            $storedCpf = $this->getStoredCpf();

            if (!$storedCpf) {
                $this->clearCardData();
                $this->setKwStatus(null);
                return "O CPF fornecido é inválido.";
            }

            $cpf = $storedCpf;
        } else {
            $cpf = $normalizedCpf;
            $this->refreshStoredCpf($cpf);
        }

        if (!preg_match('/^\d{11}$/', $cpf)) {
            $this->clearCardData();
            $this->setKwStatus(null);
            return "O CPF fornecido é inválido.";
        }

        $kw = $kw ?? $this->kw ?? $this->getStoredKw();

        if (empty($kw)) {
            Log::warning('CardTool executada sem kw.');
            $this->clearCardData();
            $this->setKwStatus(null);
            return "Não foi possível consultar a informação da carterinha porque o acesso não foi confirmado.";
        }

        $this->clearCardData();

        $url = env('CLIENT_API_BASE_URL').'/tsmadesao/beneficiario';
        $data = ['cpf' => $cpf, 'kw' => $kw];

        $responseDataClient = $this->apiService->apiConsumer($data, $url);

        if ($responseDataClient['success']) {
            $this->setKwStatus('valid', $kw);
            if (($responseDataClient['result']['quantidade'] ?? 0) !== 0) {
                $beneficiariesInformation = $this->formatBeneficiaries($responseDataClient['result']['planos'] ?? []);

                $this->storeCardData($beneficiariesInformation);

                if (!empty($beneficiariesInformation)) {
                    return "Carteirinha encontrada. Lista pronta para exibição.";
                }
            }

            $this->storeCardData([]);
            return "Nenhuma informação da carterinha foi encontrada para o CPF do cliente {$cpf}.";
        }

        $httpCode = $responseDataClient['httpcode'] ?? null;
        $errorMessage = $this->extractErrorMessage($responseDataClient);

        if ($this->isKwInvalid($httpCode, $errorMessage)) {
            $this->setKwStatus('invalid', $kw);
            $this->clearCardData();
            return "KW inválida.";
        }

        if ($this->isNoPlanFound($httpCode, $errorMessage)) {
            $this->setKwStatus('valid', $kw);
            $this->storeCardData([]);
            return "Nenhuma informação da carterinha foi encontrada para o CPF do cliente {$cpf}.";
        }

        $this->setKwStatus(null);
        $this->clearCardData();
        return "Não foi possível consultar a informação da carterinha para o CPF do cliente {$cpf}, ocorreu um erro técnico.";
    }

    private function formatBeneficiaries(array $planos): array
    {
        $beneficiariesInformation = [];
        $index = 0;

        foreach ($planos as $plano) {
            $beneficiarios = $plano['beneficiarios'] ?? [];

            foreach ($beneficiarios as $beneficiario) {
                if (($beneficiario['numerocarteira'] ?? '') === '') {
                    continue;
                }

                $beneficiariesInformation[] = [
                    'id' => $index++,
                    'nome' => $beneficiario['nome'] ?? '',
                    'tipo' => $beneficiario['tipo'] ?? '',
                    'cpf' => $beneficiario['cpf'] ?? '',
                    'datanascimento' => $this->formatBirthDate($beneficiario['datanascimento'] ?? null),
                    'numerocarteira' => $beneficiario['numerocarteira'],
                    'numerocarteiraodonto' => $beneficiario['numerocarteiraodonto'] ?? '',
                ];
            }
        }

        return $beneficiariesInformation;
    }

    private function formatBirthDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    private function storeCardData(array $beneficiaries): void
    {
        $dataKey = $this->getCacheKey('beneficiarios');
        $lastToolKey = $this->getCacheKey('last_tool');

        if (!$dataKey || !$lastToolKey) {
            return;
        }

        Cache::put($dataKey, $beneficiaries, 3600);
        Cache::put($lastToolKey, 'card', 3600);
    }

    private function clearCardData(): void
    {
        $dataKey = $this->getCacheKey('beneficiarios');
        $lastToolKey = $this->getCacheKey('last_tool');

        if ($dataKey) {
            Cache::forget($dataKey);
        }

        if ($lastToolKey) {
            Cache::forget($lastToolKey);
        }
    }

    private function getCacheKey(string $suffix): ?string
    {
        if (!$this->conversationId) {
            return null;
        }

        return "conv:{$this->conversationId}:{$suffix}";
    }

    private function setKwStatus(?string $status, ?string $kwValue = null): void
    {
        $statusKey = $this->getCacheKey('kw_status');
        $hashKey = $this->getCacheKey('kw_hash');
        //Log::info('kw_hash CardTool ' . $hashKey);
        if (!$statusKey || !$hashKey) {
            return;
        }

        if ($status === null) {
            Cache::forget($statusKey);
            Cache::forget($hashKey);
            return;
        }

        Cache::put($statusKey, $status, 3600);

        if ($kwValue !== null) {
            Cache::put($hashKey, hash('sha256', $kwValue), 3600);
        }
    }

    private function extractErrorMessage(array $response): string
    {
        $message = '';

        $result = $response['result'] ?? null;

        if (is_array($result)) {
            $message = Arr::get($result, 'message')
                ?? Arr::get($result, 'error')
                ?? json_encode($result);
        } elseif (is_string($result) && $result !== '') {
            $message = $result;
        }

        if (!$message && !empty($response['request'])) {
            $message = (string) $response['request'];
        }

        if (!$message && !empty($response['error'])) {
            $message = (string) $response['error'];
        }

        return $message;
    }

    private function isKwInvalid(?int $httpCode, string $message): bool
    {
        if ($httpCode === 401) {
            return true;
        }

        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'kw inválid');
    }

    private function isNoPlanFound(?int $httpCode, string $message): bool
    {
        if ($httpCode === 404) {
            return true;
        }

        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'plano ativo');
    }

    private function getStoredKw(): ?string
    {
        if (!$this->conversationId) {
            return null;
        }

        $cacheKey = $this->getCacheKey('kw_value');
        $kw = Cache::get($cacheKey);

        if ($kw) {
            Cache::put($cacheKey, $kw, 3600);
        }

        return $kw ?: null;
    }

    private function normalizeCpf(?string $cpf): ?string
    {
        if (!$cpf) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cpf);

        return strlen($digits) === 11 ? $digits : null;
    }

    private function getStoredCpf(): ?string
    {
        if (!$this->conversationId) {
            return null;
        }

        $cacheKey = "conv:{$this->conversationId}:last_cpf";
        $cpf = Cache::get($cacheKey);

        if ($cpf) {
            Cache::put($cacheKey, $cpf, 3600);
        }

        return $cpf ?: null;
    }

    private function refreshStoredCpf(string $cpf): void
    {
        if (!$this->conversationId) {
            return;
        }

        Cache::put("conv:{$this->conversationId}:last_cpf", $cpf, 3600);
    }
}
