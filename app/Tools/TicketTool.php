<?php

namespace App\Tools;

use Exception;
use Prism\Prism\Tool;
use Illuminate\Support\Str;
use App\Services\ApiConsumerService;
use App\Services\PinGeneratorService;
use Illuminate\Support\Facades\Cache;

class TicketTool extends Tool
{
    private $apiService;
    protected $pinService;
    protected ?string $conversationId = null;

    public function __construct(ApiConsumerService $apiService, PinGeneratorService $pinService )
    {
        $this->as('ticket_lookup')
            ->for('Recupera informação do boleto do cliente pelo CPF informado.')
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

    public function __invoke(string $cpf)
    {
        // Chamada API Corpe

        $normalizedCpf = $this->normalizeCpf($cpf);

        if (!$normalizedCpf || !$this->isValidCpf($normalizedCpf)) {
            $storedCpf = $this->getStoredCpf();

            if (!$storedCpf || !$this->isValidCpf($storedCpf)) {
                $this->signalTicketError('cpf_invalid');
                $this->clearTicketData();
                return "CPF inválido.";
            }

            $cpf = $storedCpf;
        } else {
            $cpf = $normalizedCpf;
            $this->refreshStoredCpf($cpf);
        }

        $this->clearTicketData();

        $pinResult = $this->generatePinSafely($cpf);
        if (!$pinResult['success']) {
            $this->signalTicketError($pinResult['error'] ?? 'pin_invalid');
            return $pinResult['message'] ?? "PIN inválido.";
        }

        $pin = $pinResult['pin'];

        //Busca informação sobre a cobrança do cliente
        $url = env('CLIENT_API_BASE_URL').'/tsmadesao/cobrancas';
        $data = ['cpf' => $cpf, 'pin' => $pin];

        $responseCollection = $this->apiService->apiConsumer($data, $url);
        if ($responseCollection['success']){

            if ($responseCollection['result']['quantidade'] !== 0){

                $arrayTemp = [];

                //Busca informação do boleto do cliente
                $url = env('CLIENT_API_BASE_URL').'/tsmboletos/boleto';

                foreach ($responseCollection['result']['cobrancas'] as $key => $cobranca) {
                    $codigocobranca = $cobranca['codigo'];

                    $data = ['cpf' => $cpf, 'codigocobranca' => $codigocobranca, 'pin' => $pin];
                    $responseTicket = $this->apiService->apiConsumer($data, $url);

                    if ($responseTicket['success']){
                        array_push($arrayTemp, $responseTicket['result']);
                    } else {
                        $handled = $this->handleTicketErrorResponse($responseTicket);
                        if ($handled) {
                            $this->signalTicketError($handled['code'], $handled['detail'] ?? null);
                            $this->storeTicketData([]);
                            return $handled['message'];
                        }
                    }
                }
                if (!empty($arrayTemp)){
                    $tickets = $this->formatTicketResponse($arrayTemp);
                }else{
                    $tickets = [];
                }

                $this->storeTicketData($tickets);

                if (!empty($tickets)) {
                    $count = count($tickets);
                    return $count > 1
                        ? "Boletos encontrados: {$count}. Lista pronta para exibição."
                        : "Boleto encontrado. Lista pronta para exibição.";
                }

                return "Nenhum boleto encontrado para o CPF {$cpf}.";

            } else {

                $this->storeTicketData([]);
                return "Nenhum boleto encontrado para o CPF {$cpf}.";
            }
        }else{

            $handled = $this->handleTicketErrorResponse($responseCollection);
            if ($handled) {
                $this->signalTicketError($handled['code'], $handled['detail'] ?? null);
                $this->clearTicketData();
                return $handled['message'];
            }

            $this->signalTicketError('technical_error');
            $this->clearTicketData();
            return "Não foi possível consultar o boleto para o CPF {$cpf}, ocorreu um erro técnico.";
        }
    }

    private function formatTicketResponse($ticketsData)
    {
        $tickets = [];

        foreach ($ticketsData as $ticket) {
            if (isset($ticket['message'])) {
                continue;
            }

            if (isset($ticket['linhaDigitavel']) && isset($ticket['boleto'])) {
                // Gerar token único para o boleto
                $token = Str::random(32);

                // Armazenar o base64 no cache por 1 hora
                Cache::put("boleto_pdf_{$token}", $ticket['boleto'], 3600);

                // Gerar link para download
                $downloadLink = url("/api/boleto/download/{$token}");

                $tickets[] = [
                    'linha_digitavel' => $this->formatLinhaDigitavel($ticket['linhaDigitavel']),
                    'link' => $downloadLink,
                ];
            }
        }

        return $tickets;
    }


    private function formatLinhaDigitavel($linha)
    {
        // Formatar a linha digitável com espaços para melhor legibilidade
        // Ex: 23790.19801 90001.134262 84018.901508 8 91390000066508
        return preg_replace('/(\d{5})(\d{5})(\d{5})(\d{6})(\d{5})(\d{6})(\d)(\d{14})/',
                           '$1.$2 $3.$4 $5.$6 $7 $8',
                           str_replace(['.', ' '], '', $linha));
    }

    private function storeTicketData(array $tickets): void
    {
        if (!$this->conversationId) {
            return;
        }

        Cache::put("conv:{$this->conversationId}:boletos", $tickets, 3600);
        Cache::put("conv:{$this->conversationId}:last_tool", 'ticket', 3600);
    }

    private function clearTicketData(): void
    {
        if (!$this->conversationId) {
            return;
        }

        Cache::forget("conv:{$this->conversationId}:boletos");
        Cache::forget("conv:{$this->conversationId}:last_tool");
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

    private function isValidCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf)) {
            return false;
        }

        if (preg_match('/^(\\d)\\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += intval($cpf[$i]) * (($t + 1) - $i);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if (intval($cpf[$t]) !== $digit) {
                return false;
            }
        }

        return true;
    }

    private function generatePinSafely(string $cpf): array
    {
        try {
            $pin = $this->pinService->generatePinWithDailyCache($cpf);

            if (!isset($pin['pin'])) {
                return [
                    'success' => false,
                    'error' => 'pin_invalid',
                    'message' => "PIN inválido.",
                ];
            }

            return [
                'success' => true,
                'pin' => $pin['pin'],
            ];
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'cpf') !== false) {
                return [
                    'success' => false,
                    'error' => 'cpf_invalid',
                    'message' => "CPF inválido.",
                ];
            }

            return [
                'success' => false,
                'error' => 'pin_invalid',
                'message' => "PIN inválido.",
            ];
        }
    }

    private function handleTicketErrorResponse(array $response): ?array
    {
        $httpCode = $response['httpcode'] ?? null;
        $message = $this->extractErrorMessage($response);
        $normalizedMessage = mb_strtolower($message);

        if ($httpCode === 401 || str_contains($normalizedMessage, 'pin inválido')) {
            return [
                'code' => 'pin_invalid',
                'message' => "PIN inválido.",
            ];
        }

        if ($httpCode === 404 && str_contains($normalizedMessage, 'boleto indisponível')) {
            $detail = $this->extractDetailFromMessage($message);
            return [
                'code' => 'boleto_indisponivel',
                'message' => "Boleto indisponível.",
                'detail' => $detail,
            ];
        }

        if ($httpCode === 400 && str_contains($normalizedMessage, 'cpf inválido')) {
            return [
                'code' => 'cpf_invalid',
                'message' => "CPF inválido.",
            ];
        }

        if ($httpCode && $httpCode >= 400) {
            return [
                'code' => 'technical_error',
                'message' => "Erro técnico ao consultar boletos.",
            ];
        }

        return null;
    }

    private function extractErrorMessage(array $response): string
    {
        $result = $response['result'] ?? null;

        if (is_array($result)) {
            return $result['message'] ?? $result['error'] ?? json_encode($result);
        }

        if (is_string($result) && $result !== '') {
            return $result;
        }

        if (!empty($response['request'])) {
            return (string) $response['request'];
        }

        if (!empty($response['error'])) {
            return (string) $response['error'];
        }

        return '';
    }

    private function extractDetailFromMessage(string $message): ?string
    {
        if (preg_match('/vencido há\\s+\\d+\\s+dias/i', $message, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function signalTicketError(string $code, ?string $detail = null): void
    {
        if (!$this->conversationId) {
            return;
        }

        Cache::put("conv:{$this->conversationId}:ticket_error", $code, 3600);

        if ($detail) {
            Cache::put("conv:{$this->conversationId}:ticket_error_detail", $detail, 3600);
        } else {
            Cache::forget("conv:{$this->conversationId}:ticket_error_detail");
        }
    }
}
