<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class ApiConsumerService
{
    /**
     * Consume uma API via POST com dados JSON
     *
     * @param array $data Dados para enviar na requisição
     * @param string $url URL da API
     * @param array $headers Headers adicionais (opcional)
     * @param int $timeout Timeout em segundos (opcional)
     * @return array
     */
    public function apiConsumer(array $data, string $url, array $headers = [], int $timeout = 30): array
    {
        try {
            $curl = curl_init($url);

            //Log::info('API Payload', [
            //    'url' => $url,
            //    'data' => $data,
            //    'headers' => $headers,
            //]);

            // Configurações básicas do cURL
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            // Headers padrão
            $defaultHeaders = [
                'Content-Type: application/json',
                'Accept: application/json'
            ];

            // Merge com headers customizados
            $allHeaders = array_merge($defaultHeaders, $headers);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $allHeaders);

            $startTime = microtime(true);
            $request = curl_exec($curl);
            $endTime = microtime(true);

            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $responseTime = round(($endTime - $startTime), 4);
            $error = curl_error($curl);
            $curlInfo = curl_getinfo($curl);

            curl_close($curl);

            // Decodifica a resposta se for JSON válido
            $decodedResult = null;
            if ($request && !$error) {
                $decodedResult = json_decode($request, true);
            }

            $response = [
                'success' => $httpcode >= 200 && $httpcode < 300 && !$error,
                'httpcode' => $httpcode,
                'responseTime' => $responseTime,
                'error' => $error,
                'request' => $request,
                'result' => $decodedResult,
                'curl_info' => $curlInfo,
                'sent_data' => $data,
                'url' => $url
            ];

            // Log da requisição para debug
            $this->logRequest($response);
            return $response;

        } catch (Exception $e) {
            $errorResponse = [
                'success' => false,
                'httpcode' => 0,
                'responseTime' => 0,
                'error' => $e->getMessage(),
                'result' => null,
                'result' => null,
                'curl_info' => null,
                'sent_data' => $data,
                'url' => $url
            ];

            Log::error('ApiConsumerService Exception', $errorResponse);
            return $errorResponse;
        }
    }

    /**
     * Método GET para consumir APIs
     *
     * @param string $url URL da API
     * @param array $headers Headers adicionais (opcional)
     * @param int $timeout Timeout em segundos (opcional)
     * @return array
     */
    public function apiGet(string $url, array $headers = [], int $timeout = 30): array
    {
        try {
            $curl = curl_init($url);

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $defaultHeaders = [
                'Accept: application/json',
                'User-Agent: Laravel-ApiConsumer/1.0'
            ];

            $allHeaders = array_merge($defaultHeaders, $headers);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $allHeaders);

            $startTime = microtime(true);
            $request = curl_exec($curl);
            $endTime = microtime(true);

            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $responseTime = round(($endTime - $startTime), 4);
            $error = curl_error($curl);
            $curlInfo = curl_getinfo($curl);

            curl_close($curl);

            $decodedResult = null;
            if ($request && !$error) {
                $decodedResult = json_decode($request, true);
            }

            $response = [
                'success' => $httpcode >= 200 && $httpcode < 300 && !$error,
                'httpcode' => $httpcode,
                'responseTime' => $responseTime,
                'error' => $error,
                'result' => $request,
                'decoded_result' => $decodedResult,
                'curl_info' => $curlInfo,
                'url' => $url
            ];

            $this->logRequest($response);

            return $response;

        } catch (Exception $e) {
            $errorResponse = [
                'success' => false,
                'httpcode' => 0,
                'responseTime' => 0,
                'error' => $e->getMessage(),
                'result' => null,
                'decoded_result' => null,
                'curl_info' => null,
                'url' => $url
            ];

            Log::error('ApiConsumerService GET Exception', $errorResponse);

            return $errorResponse;
        }
    }

    /**
     * Log das requisições para debug
     *
     * @param array $response
     * @return void
     */
    private function logRequest(array $response): void
    {
        if (config('app.debug')) {
            Log::info('API Request', [
                'url' => $response['url'],
                'httpcode' => $response['httpcode'],
                'responseTime' => $response['responseTime'],
                'success' => $response['success'],
                //'result' => $response['success'] == true ? $response['result'] : null,
                'error' => $response['error'] ?? null
            ]);
        }
    }

    /**
     * Método auxiliar para verificar se a resposta foi bem-sucedida
     *
     * @param array $response
     * @return bool
     */
    public function isSuccessful(array $response): bool
    {
        return $response['success'] ?? false;
    }

    /**
     * Método auxiliar para obter apenas os dados decodificados
     *
     * @param array $response
     * @return mixed
     */
    public function getDecodedData(array $response)
    {
        return $response['decoded_result'] ?? null;
    }
}
