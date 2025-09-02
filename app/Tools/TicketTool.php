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
        //ds('cpf: ' , $cpf);
        $pin = $this->pinService->generatePinWithDailyCache($cpf);
        //ds('Pin:' , $pin);

        //Busca informação sobre a cobrança do cliente
        $url = env('CLIENT_API_BASE_URL').'/cobrancas';
        $data = ['cpf' => $cpf, 'pin' => $pin['pin']];
        //ds('data: ' , $data);
        $responseCollection = $this->apiService->apiConsumer($data, $url);

        ds('ResponseCollection: ' , $responseCollection);

        if ($responseCollection['success']){

            if ($responseCollection['result']['quantidade'] !== 0){

                //Busca informação do boleto do cliente
                $url = env('CLIENT_API_BASE_URL').'/boleto';
                $codigocobranca = $responseCollection['return']['cobrancas']['codigo'];
                $data = ['cpf' => $cpf, 'codigocobranca' => $codigocobranca, 'pin' => $pin['pin']];

                $responseTicket = $this->apiService->apiConsumer($data, $url);
                ds('responseTicket: ' , $responseTicket);

                return $this->formatTicketResponse($responseTicket['result']);

            } else {

                return "Nenhum boleto encontrado para o CPF {$cpf}.";
            }
        }else{

            return "Não foi possível consultar o boleto para o CPF {$cpf}, ocorreu um erro técnico.";
        }
    }

    private function formatTicketResponse($ticketData)
    {
        // Verificar se há mensagem de erro
        if (isset($ticketData['message'])) {
            return "❌ " . $ticketData['message'];
        }

        // Verificar se tem os dados do boleto
        if (isset($ticketData['linhaDigitavel']) && isset($ticketData['boleto'])) {

            // Gerar token único para o boleto
            $token = Str::random(32);

            // Armazenar o base64 no cache por 1 hora
            Cache::put("boleto_pdf_{$token}", $ticketData['boleto'], 3600);

            // Gerar link para download
            $downloadLink = url("/api/boleto/download/{$token}");

            // Formatar a linha digitável para melhor legibilidade
            $linhaDigitavel = $this->formatLinhaDigitavel($ticketData['linhaDigitavel']);

            $response = "✅ **Boleto encontrado!**\n\n";
            $response .= "📋 **Linha Digitável:**\n";
            $response .= "`{$linhaDigitavel}`\n\n";
            $response .= "📄 **Download do PDF:**\n";
            $response .= "[Clique aqui para baixar o boleto]({$downloadLink})\n\n";
            $response .= "💡 **Dica:** Você pode copiar a linha digitável acima para pagar o boleto no internet banking ou app do seu banco.\n";
            $response .= "⏰ **Atenção:** O link para download expira em 1 hora.";

            return $response;
        }

        return "Erro ao processar informações do boleto.";
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
