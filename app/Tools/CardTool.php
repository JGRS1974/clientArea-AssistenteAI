<?php

namespace App\Tools;

use Exception;
use Prism\Prism\Tool;
use App\Services\ApiConsumerService;
use App\Services\PinGeneratorService;

class CardTool extends Tool
{
    private $apiService;
    protected $pinService;

    public function __construct(ApiConsumerService $apiService, PinGeneratorService $pinService )
    {
        $this->as('card_lookup')
            ->for('Recupera informação da carterinha do cliente pelo CPF informado e após o cliente fazer login no sistema para obter a chave de acesso.')
            ->withStringParameter('cpf', 'CPF do cliente')
            ->withStringParameter('kw', 'Chave de acesso do cliente após fazer login no sistema. ')
            ->using($this);

        $this->apiService = $apiService;
        $this->pinService = $pinService;
    }

    public function __invoke(string $cpf, string $kw)
    {
        // Chamada API Corpe

        if (!preg_match('/^\d{11}$/', $cpf)) {
            return "O CPF fornecido é inválido.";
        }

        //Busca informação sobre a cobrança do cliente
        $url = env('CLIENT_API_BASE_URL').'/beneficiario';
        $data = ['cpf' => $cpf, 'kw' => $kw];
        ds('data: ' , $data);
        $responseDataClient = $this->apiService->apiConsumer($data, $url);

        if ($responseDataClient['success']){

            if ($responseDataClient['result']['quantidade'] !== 0){
                $beneficiariesInformation = [];
                foreach ($responseDataClient['result']['planos'] as $key => $plano) {
                    foreach ($plano['beneficiarios'] as $key => $beneficiario) {
                        if ($beneficiario['numerocarteira'] !== ''){
                            array_push($beneficiariesInformation, $beneficiario);
                        }
                    }
                }
                //ds('Array Beneficiarios: ' , $beneficiariesInformation);

                // Formatar a resposta como string legível
                if (count($beneficiariesInformation) > 0) {
                    $response = "Informações da carterinha encontradas:\n\n";

                    foreach ($beneficiariesInformation as $index => $beneficiario) {
                        $response .= "📋 Beneficiário " . ($index + 1) . ":\n";
                        $response .= "• Nome: " . $beneficiario['nome'] . "\n";
                        $response .= "• Tipo: " . $beneficiario['tipo'] . "\n";
                        $response .= "• CPF: " . $beneficiario['cpf'] . "\n";
                        $response .= "• Data de Nascimento: " . date('d/m/Y', strtotime($beneficiario['datanascimento'])) . "\n";
                        $response .= "• Número da Carteira: " . $beneficiario['numerocarteira'] . "\n";

                        if (!empty($beneficiario['numerocarteiraodonto'])) {
                            $response .= "• Carteira Odonto: " . $beneficiario['numerocarteiraodonto'] . "\n";
                        }

                        $response .= "\n";
                    }
                    ds('Response Beneficiarios: ' , $response);
                    return $response;
                }

            } else {

                return "Nenhuma informação da carterinha foi encontrada para o CPF do cliente {$cpf}.";
            }
        }else{

            return "Não foi possível consultar a informação da carterinha para o CPF do cliente {$cpf}, ocorreu um erro técnico.";
        }
    }

}
