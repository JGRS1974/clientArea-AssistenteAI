<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PinGeneratorService
{
    /**
     * Gera o PIN baseado no CPF e data atual
     *
     * @param string $cpf
     * @param string|null $date Data opcional (formato Y-m-d), usa hoje se não informado
     * @return array
     */
    public function generatePin(string $cpf, ?string $date = null): array
    {
        try {
            // Limpa o CPF removendo caracteres não numéricos
            $cleanCpf = $this->cleanCpf($cpf);

            // Valida o CPF
            if (!$this->isValidCpf($cleanCpf)) {
                throw new \InvalidArgumentException('CPF inválido');
            }

            // Define a data (hoje se não informado)
            $targetDate = $date ? Carbon::parse($date) : Carbon::now();
            $formattedDate = $targetDate->format('Ymd');

            // Monta o PIN antes do hash
            $pinString = "PN{$cleanCpf}@{$formattedDate}";

            // Gera o hash MD5
            $pinHash = md5($pinString);

            // Log da operação
            Log::info('PIN gerado', [
                'cpf_masked' => $this->maskCpf($cleanCpf),
                'date' => $formattedDate,
                'pin' => $pinHash,
                'pin_string' => $pinString,
                'cpf_clean' => $cleanCpf
            ]);

            return [
                'success' => true,
                'pin' => $pinHash
                //'data' => [
                //    'pin' => $pinHash,
                //    'pin_string' => $pinString,
                //    'cpf_clean' => $cleanCpf,
                //    'cpf_masked' => $this->maskCpf($cleanCpf),
                //    'date' => $formattedDate,
                //    'date_formatted' => $targetDate->format('d/m/Y'),
                //    'generated_at' => now()->toISOString()
                //]
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao gerar PIN', [
                'error' => $e->getMessage(),
                'cpf' => isset($cleanCpf) ? $this->maskCpf($cleanCpf) : 'inválido'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Gera PIN com cache (evita recalcular o mesmo PIN no mesmo dia)
     *
     * @param string $cpf
     * @param int $cacheMinutes
     * @return array
     */
    public function generatePinWithCache(string $cpf, int $cacheMinutes = 60): array
    {
        $cleanCpf = $this->cleanCpf($cpf);
        $today = Carbon::now()->format('Ymd');
        $cacheKey = "pin_generator_{$cleanCpf}_{$today}";

        return Cache::remember($cacheKey, $cacheMinutes * 60, function () use ($cpf) {
            return $this->generatePin($cpf);
        });
    }

    /**
     * Gera PIN com cache que expira no final do dia
     * Ideal para PINs que devem ser os mesmos durante todo o dia
     *
     * @param string $cpf
     * @param string|null $date Data opcional (formato Y-m-d)
     * @return array
     */
    public function generatePinWithDailyCache(string $cpf, ?string $date = null): array
    {
        $cleanCpf = $this->cleanCpf($cpf);
        $targetDate = $date ? Carbon::parse($date) : Carbon::now();
        $dateString = $targetDate->format('Ymd');
        $cacheKey = "pin_daily_{$cleanCpf}_{$dateString}";

        // Calcula quantos segundos faltam até o final do dia
        $endOfDay = $targetDate->copy()->endOfDay();
        $secondsUntilEndOfDay = $endOfDay->diffInSeconds(Carbon::now());

        // Se a data é no passado ou futuro, cache por 24 horas
        if (!$targetDate->isToday()) {
            $secondsUntilEndOfDay = 24 * 60 * 60; // 24 horas
        }

        return Cache::remember($cacheKey, $secondsUntilEndOfDay, function () use ($cpf, $date) {
            return $this->generatePin($cpf, $date);
        });
    }

    /**
     * Verifica se já existe um PIN no cache para o CPF na data atual
     *
     * @param string $cpf
     * @param string|null $date
     * @return array|null
     */
    public function getPinFromCache(string $cpf, ?string $date = null): ?array
    {
        $cleanCpf = $this->cleanCpf($cpf);
        $targetDate = $date ? Carbon::parse($date)->format('Ymd') : Carbon::now()->format('Ymd');
        $cacheKey = "pin_daily_{$cleanCpf}_{$targetDate}";

        return Cache::get($cacheKey);
    }

    /**
     * Remove PIN do cache (útil para invalidar cache manualmente)
     *
     * @param string $cpf
     * @param string|null $date
     * @return bool
     */
    public function clearPinCache(string $cpf, ?string $date = null): bool
    {
        $cleanCpf = $this->cleanCpf($cpf);
        $targetDate = $date ? Carbon::parse($date)->format('Ymd') : Carbon::now()->format('Ymd');

        // Remove dos dois tipos de cache
        $dailyCacheKey = "pin_daily_{$cleanCpf}_{$targetDate}";
        $regularCacheKey = "pin_generator_{$cleanCpf}_{$targetDate}";

        $cleared1 = Cache::forget($dailyCacheKey);
        $cleared2 = Cache::forget($regularCacheKey);

        return $cleared1 || $cleared2;
    }

    /**
     * Valida múltiplos CPFs de uma vez
     *
     * @param array $cpfs
     * @return array
     */
    public function generateMultiplePins(array $cpfs): array
    {
        $results = [];

        foreach ($cpfs as $cpf) {
            $results[] = $this->generatePin($cpf);
        }

        return [
            'total' => count($cpfs),
            'successful' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count(array_filter($results, fn($r) => !$r['success'])),
            'results' => $results
        ];
    }

    /**
     * Limpa o CPF removendo caracteres não numéricos
     *
     * @param string $cpf
     * @return string
     */
    private function cleanCpf(string $cpf): string
    {
        return preg_replace('/\D/', '', $cpf);
    }

    /**
     * Valida se o CPF é válido
     *
     * @param string $cpf
     * @return bool
     */
    private function isValidCpf(string $cpf): bool
    {
        // Remove caracteres não numéricos
        $cpf = preg_replace('/\D/', '', $cpf);

        // Verifica se tem 11 dígitos
        if (strlen($cpf) !== 11) {
            return false;
        }

        // Verifica se todos os dígitos são iguais
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        // Calcula os dígitos verificadores
        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mascara o CPF para logs (xxx.xxx.xxx-xx)
     *
     * @param string $cpf
     * @return string
     */
    private function maskCpf(string $cpf): string
    {
        return substr($cpf, 0, 3) . '.***.**' . substr($cpf, -2);
    }

    /**
     * Formata CPF com pontos e hífen
     *
     * @param string $cpf
     * @return string
     */
    public function formatCpf(string $cpf): string
    {
        $cleanCpf = $this->cleanCpf($cpf);
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cleanCpf);
    }
}
