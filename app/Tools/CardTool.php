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
            ->for('Recupera dados do cliente (carteirinha, planos, ficha financeira e coparticipação) após login confirmado e com CPF válido.')
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
        $storedCpf = $this->getStoredCpf();
        $normalizedCpf = $this->normalizeCpf($cpf);

        if ($storedCpf) {
            $cpf = $storedCpf;
        } elseif ($normalizedCpf) {
            $cpf = $normalizedCpf;
            $this->refreshStoredCpf($cpf);
        } else {
            $this->clearCardData();
            $this->setKwStatus(null);
            return "O CPF fornecido é inválido.";
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
            $planos = $responseDataClient['result']['planos'] ?? [];

            if (($responseDataClient['result']['quantidade'] ?? 0) !== 0 && !empty($planos)) {
                $beneficiariesInformation = $this->formatBeneficiaries($planos);
                $contractsInformation = $this->formatContracts($planos);
                $financialInformation = $this->formatFinancialReport($planos);
                $coparticipationInformation = $this->formatCoparticipation($planos);

                $this->storeCardDataExtended(
                    $beneficiariesInformation,
                    $contractsInformation,
                    $financialInformation,
                    $coparticipationInformation
                );

                if (
                    !empty($beneficiariesInformation) ||
                    !empty($contractsInformation) ||
                    !empty($financialInformation) ||
                    !empty($coparticipationInformation)
                ) {
                    return "Carteirinha encontrada. Lista pronta para exibição.";
                }
            }

            $this->storeCardDataExtended([], [], [], []);
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
            $this->storeCardDataExtended([], [], [], []);
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
                    'entidade' => $plano['contrato']['entidade'] ?? '',
                    'operadora' => $plano['contrato']['operadora'] ?? '',
                    'plano' => $plano['contrato']['plano'] ?? '',
                    'coparticipacao' => $plano['contrato']['coparticipacao'] ?? '',
                    'datavigencia' => date('m-d-Y', strtotime($plano['contrato']['datavigencia'])) ?? ''
                ];
            }
        }
        //Log::info('Beneficiarios', $beneficiariesInformation);
        return $beneficiariesInformation;
    }

    private function formatContracts(array $planos): array
    {
        $contracts = [];

        foreach ($planos as $plano) {
            $contract = $plano['contrato'] ?? null;

            if (is_array($contract) && !empty($contract)) {
                $contracts[] = $contract;
            }
        }
        //Log::info('planos', $contracts);
        return $contracts;
    }

    private function formatFinancialReport(array $planos): array
    {
        $report = [];

        foreach ($planos as $plano) {
            $financial = $plano['fichafinanceira'] ?? [];
            $report[] = [
                'plano' => $plano['contrato']['plano'] ?? '',
                'contrato' => $plano['contrato'] ?? [],
                'fichafinanceira' => is_array($financial) ? array_values($financial) : [],
            ];
        }
        //Log::info('financeiro', $report);
        return $report;
    }

    private function formatCoparticipation(array $planos): array
    {
        $coparticipation = [];

        foreach ($planos as $plano) {
            $copart = $plano['coparticipacao'] ?? [];
            $coparticipation[] = [
                'plano' => $plano['contrato']['plano'] ?? '',
                'contrato' => $plano['contrato'] ?? [],
                'coparticipacao' => is_array($copart) ? array_values($copart) : [],
            ];
        }
        //Log::info('coparticipacao', $coparticipation);
        return $coparticipation;
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

    private function storeCardDataExtended(array $beneficiaries, array $contracts, array $financial, array $coparticipation): void
    {
        $map = [
            'beneficiarios' => $beneficiaries,
            'planos' => $contracts,
            'fichafinanceira' => $financial,
            'coparticipacao' => $coparticipation,
        ];

        foreach ($map as $suffix => $value) {
            $key = $this->getCacheKey($suffix);
            if ($key) {
                Cache::put($key, $value, 3600);
            }
        }

        $lastToolKey = $this->getCacheKey('last_tool');
        if ($lastToolKey) {
            Cache::put($lastToolKey, 'card', 3600);
        }
    }

    private function clearCardData(): void
    {
        foreach (['beneficiarios', 'planos', 'fichafinanceira', 'coparticipacao', 'last_tool'] as $suffix) {
            $key = $this->getCacheKey($suffix);
            if ($key) {
                Cache::forget($key);
            }
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
