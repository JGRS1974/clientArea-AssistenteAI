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

    public function __construct(ApiConsumerService $apiService, PinGeneratorService $pinService )
    {
        $this->as('ticket_lookup')
            ->for('Recupera informação do boleto do cliente pelo CPF informado.')
            ->withStringParameter('cpf', 'CPF do cliente')
            ->using($this);

        $this->apiService = $apiService;
        $this->pinService = $pinService;
    }

    public function __invoke(string $cpf)
    {
        // Chamada API Corpe

        if (!preg_match('/^\d{11}$/', $cpf)) {
            return "O CPF fornecido é inválido.";
        }

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
                    $tickets = "Nenhum boleto encontrado para o CPF {$cpf}.";
                }

                return $tickets;

            } else {

                return "Nenhum boleto encontrado para o CPF {$cpf}.";
            }
        }else{

            return "Não foi possível consultar o boleto para o CPF {$cpf}, ocorreu um erro técnico.";
        }
    }

    private function formatTicketResponse($ticketsData)
    {
        $response = '';
        $validTickets = 0;
        $ticketResponses = [];

        // Primeiro, processar todos os boletos e contar os válidos
        foreach ($ticketsData as $key => $ticket) {
            $ticketResponse = '';

            // Verificar se há mensagem de erro
            if (isset($ticket['message'])) {
                $ticketResponse = "❌ Boleto " . ($key + 1) . ": " . $ticket['message'] . "\n\n";
                $ticketResponses[] = $ticketResponse;
                continue;
            }

            // Verificar se tem os dados do boleto
            if (isset($ticket['linhaDigitavel']) && isset($ticket['boleto'])) {
                $validTickets++;

                // Gerar token único para o boleto
                $token = Str::random(32);

                // Armazenar o base64 no cache por 1 hora
                Cache::put("boleto_pdf_{$token}", $ticket['boleto'], 3600);

                // Gerar link para download
                $downloadLink = url("/api/boleto/download/{$token}");

                // Formatar a linha digitável para melhor legibilidade
                $linhaDigitavel = $this->formatLinhaDigitavel($ticket['linhaDigitavel']);

                if (count($ticketsData) > 1) {
                    $ticketResponse = "✅ **Boleto " . ($key + 1) . " encontrado!**\n\n";
                } else {
                    $ticketResponse = "✅ **Boleto encontrado!**\n\n";
                }

                $ticketResponse .= "📋 **Linha Digitável:**\n";
                $ticketResponse .= "`{$linhaDigitavel}`\n\n";
                $ticketResponse .= "📄 **Download do PDF:**\n";
                $ticketResponse .= "Clique no seguinte link para baixar o boleto: {$downloadLink}\n\n";
                $ticketResponse .= "💡 **Dica:** Você pode copiar a linha digitável acima para pagar o boleto no internet banking ou app do seu banco.\n";
                $ticketResponse .= "⏰ **Atenção:** O link para download expira em 1 hora.\n\n";

                $ticketResponses[] = $ticketResponse;
            }
        }

        // Montar a resposta final
        if ($validTickets > 1) {
            $response = "✅ **{$validTickets} Boletos encontrados!**\n\n";
        } elseif ($validTickets == 1) {
            $response = ""; // Será adicionado individualmente
        }

        // Adicionar todas as respostas dos boletos
        $response .= implode("---\n\n", $ticketResponses);

        if (!empty($response)) {
            return trim($response);
        } else {
            return "Erro ao processar informações dos boletos.";
        }
    }


    private function formatLinhaDigitavel($linha)
    {
        // Formatar a linha digitável com espaços para melhor legibilidade
        // Ex: 23790.19801 90001.134262 84018.901508 8 91390000066508
        return preg_replace('/(\d{5})(\d{5})(\d{5})(\d{6})(\d{5})(\d{6})(\d)(\d{14})/',
                           '$1.$2 $3.$4 $5.$6 $7 $8',
                           str_replace(['.', ' '], '', $linha));
    }

}
