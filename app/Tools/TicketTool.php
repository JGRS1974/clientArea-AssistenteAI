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

        if (!$normalizedCpf) {
            $storedCpf = $this->getStoredCpf();

            if (!$storedCpf) {
                $this->clearTicketData();
                return "O CPF fornecido é inválido.";
            }

            $cpf = $storedCpf;
        } else {
            $cpf = $normalizedCpf;
            $this->refreshStoredCpf($cpf);
        }

        if (!preg_match('/^\d{11}$/', $cpf)) {
            $this->clearTicketData();
            return "O CPF fornecido é inválido.";
        }

        $this->clearTicketData();

        $pin = $this->pinService->generatePinWithDailyCache($cpf);

        //Busca informação sobre a cobrança do cliente
        $url = env('CLIENT_API_BASE_URL').'/tsmadesao/cobrancas';
        $data = ['cpf' => $cpf, 'pin' => $pin['pin']];

        $responseCollection = $this->apiService->apiConsumer($data, $url);
        if ($responseCollection['success']){

            if ($responseCollection['result']['quantidade'] !== 0){

                $arrayTemp = [];

                //Busca informação do boleto do cliente
                $url = env('CLIENT_API_BASE_URL').'/tsmboletos/boleto';

                foreach ($responseCollection['result']['cobrancas'] as $key => $cobranca) {
                    $codigocobranca = $cobranca['codigo'];

                    $data = ['cpf' => $cpf, 'codigocobranca' => $codigocobranca, 'pin' => $pin['pin']];
                    $responseTicket = $this->apiService->apiConsumer($data, $url);

                    if ($responseTicket['success']){
                        array_push($arrayTemp, $responseTicket['result']);
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
}
