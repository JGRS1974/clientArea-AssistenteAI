<?php

namespace App\Tools;

use Prism\Prism\Tool;
use Illuminate\Support\Facades\Log;
use App\Services\ApiConsumerService;
use App\Services\PinGeneratorService;
use Illuminate\Support\Facades\Cache;

class IrInformTool extends Tool
{
    private ApiConsumerService $apiService;
    private PinGeneratorService $pinService;
    private ?string $conversationId = null;
    private ?string $kw = null;

    public function __construct(ApiConsumerService $apiService, PinGeneratorService $pinService)
    {
        $this->apiService = $apiService;
        $this->pinService = $pinService;

        $this->as('ir_inform_lookup')
            ->for('Recupera o Informe de Rendimentos (IR) do cliente (lista de documentos e link do PDF).')
            ->withStringParameter('cpf', 'CPF do cliente (somente números).')
            ->withStringParameter('ano', 'Ano calendário do informe solicitado (opcional).')
            ->using($this);
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

    /**
     * @param string      $cpf CPF informado na conversa
     * @param string|null $ano Ano calendário desejado (ex.: "2024")
     * @param string|null $kw  Chave de acesso; se omitida tenta reaproveitar a salva no contexto
     */
    public function __invoke(string $cpf, ?string $ano = null, ?string $kw = null)
    {
        $cpfDigits = $this->normalizeCpf($cpf);

        if (!$cpfDigits) {
            $cpfDigits = $this->getStoredCpf();
        }

        if (!$cpfDigits) {
            $this->clearIrData();
            return "CPF inválido.";
        }

        $kwValue = $kw ?? $this->kw ?? $this->getStoredKw();

        if (empty($kwValue)) {
            $this->setKwStatus('invalid', null);
            $this->clearIrData();
            return "KW inválida.";
        }

        $pinResult = $this->pinService->generatePinWithDailyCache($cpfDigits);
        if (!($pinResult['success'] ?? false)) {
            $this->clearIrData();
            return "PIN inválido.";
        }

        $formattedCpf = $this->pinService->formatCpf($cpfDigits);
        $pin = $pinResult['pin'];
        $baseUrl = rtrim(env('IR_API_BASE_URL', env('CLIENT_API_BASE_URL')), '/');
        //Log::info('baseUrl ir ' . $baseUrl);
        // Primeira chamada: lista de informes
        $listPayload = [
            'cpf' => $formattedCpf,
            'kw' => $kwValue,
            'pin' => $pin,
        ];

        $listResponse = $this->apiService->apiConsumer($listPayload, "{$baseUrl}/v2/getListInform");
        $listHttpCode = $listResponse['httpcode'] ?? null;
        //Log::info('listResponde', $listResponse);
        if ($listHttpCode === 401) {
            $this->setKwStatus('invalid', $kwValue);
            $this->clearIrData();
            return "KW inválida.";
        }

        if ($listHttpCode === 400) {
            $this->setKwStatus(null, null);
            $this->clearIrData();
            return "Não foi possível listar os documentos.";
        }

        if (!($listResponse['success'] ?? false)) {
            $this->clearIrData();
            return "Erro técnico ao consultar IR.";
        }

        $documentos = $listResponse['result']['documentos'] ?? [];

        if (empty($documentos)) {
            $this->setKwStatus('valid', $kwValue);
            $this->storeIrData([
                'quantidade' => 0,
                'documentos' => [],
            ]);
            $this->setLastTool();
            return "Não encontrei informes de IR para este CPF.";
        }

        $documentosAlvo = $this->filterDocumentosPorAno($documentos, $ano);
        //Log::info('documentosAlvo', $documentosAlvo);
        if (empty($documentosAlvo)) {
            $this->setKwStatus('valid', $kwValue);
            $this->storeIrData([
                'quantidade' => 0,
                'documentos' => [],
            ]);
            $this->setLastTool();
            return "Não encontrei informes de IR para o ano informado.";
        }

        $documentosEnriquecidos = [];

        foreach ($documentosAlvo as $documento) {
            $resultado = $this->buscarDetalheDoInforme($documento, $formattedCpf, $kwValue, $pin, $baseUrl);

            if ($resultado['status'] === 'kw_invalid') {
                $this->setKwStatus('invalid', $kwValue);
                $this->clearIrData();
                return "KW inválida.";
            }

            $documentosEnriquecidos[] = $resultado['documento'];
        }

        $consolidado = [
            'quantidade' => count($documentosAlvo),
            'documentos' => $documentosEnriquecidos,
        ];
        //Log::info('consolidado', $consolidado);
        $this->setKwStatus('valid', $kwValue);
        $this->storeIrData($consolidado);
        $this->setLastTool();

        if ($consolidado['quantidade'] > 1) {
            return "Informes de IR localizados. Links disponíveis.";
        }

        return "Informe de IR localizado. Link disponível.";
    }

    private function filterDocumentosPorAno(array $documentos, ?string $ano): array
    {
        if ($ano === null || $ano === '') {
            return array_values($documentos);
        }

        return array_values(array_filter($documentos, static function ($documento) use ($ano) {
            return (string)($documento['anoCalendario'] ?? '') === (string)$ano;
        }));
    }

    private function buscarDetalheDoInforme(array $documento, string $cpf, string $kw, string $pin, string $baseUrl): array
    {
        $payload = [
            'codigoir' => $documento['codigo'],
            'cpf' => $cpf,
            'kw' => $kw,
            'pin' => $pin,
        ];

        $response = $this->apiService->apiConsumer($payload, "{$baseUrl}/v2/getInform");
        $httpCode = $response['httpcode'] ?? null;

        if ($httpCode === 401) {
            return [
                'status' => 'kw_invalid',
                'documento' => [],
            ];
        }

        if ($httpCode === 404) {
            return [
                'status' => 'ok',
                'documento' => $this->anexarDadosDoInforme($documento, null, 404, $this->extrairMensagem($response, 'Documento indisponível.')),
            ];
        }

        if (!($response['success'] ?? false)) {
            $codigo = $httpCode ?? 500;
            return [
                'status' => 'ok',
                'documento' => $this->anexarDadosDoInforme($documento, null, $codigo, $this->extrairMensagem($response, 'Erro técnico ao obter documento IR.')),
            ];
        }

        $resultado = $response['result'] ?? [];
        $link = $resultado['link'] ?? null;
        $codigoResposta = $resultado['httpcode'] ?? ($httpCode ?? 200);

        return [
            'status' => 'ok',
            'documento' => $this->anexarDadosDoInforme($documento, $link, $codigoResposta, null),
        ];
    }

    private function anexarDadosDoInforme(array $documento, ?string $link, int $httpcode, ?string $erro): array
    {
        $documento['link'] = $link;
        $documento['httpcode'] = $httpcode;
        $documento['error'] = $erro;

        return $documento;
    }

    private function extrairMensagem(array $response, string $fallback): string
    {
        $result = $response['result'] ?? null;

        if (is_array($result)) {
            if (!empty($result['message'])) {
                return (string) $result['message'];
            }

            if (!empty($result['error'])) {
                return (string) $result['error'];
            }
        }

        if (is_string($result) && $result !== '') {
            return $result;
        }

        if (!empty($response['error'])) {
            return (string) $response['error'];
        }

        return $fallback;
    }

    private function normalizeCpf(?string $cpf): ?string
    {
        if ($cpf === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cpf);

        return strlen($digits) === 11 ? $digits : null;
    }

    private function getStoredCpf(): ?string
    {
        $key = $this->cacheKey('last_cpf');

        if (!$key) {
            return null;
        }

        $cpf = Cache::get($key);

        if ($cpf) {
            Cache::put($key, $cpf, 3600);
        }

        return $cpf ?: null;
    }

    private function getStoredKw(): ?string
    {
        $key = $this->cacheKey('kw_value');

        if (!$key) {
            return null;
        }

        $kw = Cache::get($key);

        if ($kw) {
            Cache::put($key, $kw, 3600);
        }

        return $kw ?: null;
    }

    private function storeIrData(array $dados): void
    {
        $listaKey = $this->cacheKey('ir_documentos');

        if ($listaKey) {
            Cache::put($listaKey, $dados, 3600);
        }
    }

    private function clearIrData(): void
    {
        foreach (['ir_documentos', 'ir_documento', 'last_tool'] as $suffix) {
            $key = $this->cacheKey($suffix);
            if ($key) {
                Cache::forget($key);
            }
        }
    }

    private function setLastTool(): void
    {
        $key = $this->cacheKey('last_tool');

        if ($key) {
            Cache::put($key, 'ir', 3600);
        }
    }

    private function setKwStatus(?string $status, ?string $kwValue): void
    {
        $statusKey = $this->cacheKey('kw_status');
        $hashKey = $this->cacheKey('kw_hash');
        $valueKey = $this->cacheKey('kw_value');

        if (!$statusKey || !$hashKey || !$valueKey) {
            return;
        }

        if ($status === null) {
            Cache::forget($statusKey);
            Cache::forget($hashKey);
            Cache::forget($valueKey);
            return;
        }

        Cache::put($statusKey, $status, 3600);

        if ($kwValue !== null) {
            Cache::put($valueKey, $kwValue, 3600);
            Cache::put($hashKey, hash('sha256', $kwValue), 3600);
        }
    }

    private function cacheKey(string $suffix): ?string
    {
        if (!$this->conversationId) {
            return null;
        }

        return "conv:{$this->conversationId}:{$suffix}";
    }
}
