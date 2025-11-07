<?php

namespace App\Services\WhatsApp;

class WhatsAppMessageFormatter
{
    /**
     * Converte o payload retornado pelo controller /api/chat
     * em uma lista de mensagens de texto a serem enviadas no WhatsApp.
     */
    public function toTextMessages(array $payload, ?string $loginUrl = null): array
    {
        $messages = [];

        $text = trim((string) ($payload['text'] ?? ''));
        $intro = '';
        $outro = '';
        if ($text !== '') {
            // Divide o texto em partes usando <br> como separador, para enviar
            // a saudação antes dos dados e o encerramento depois.
            $parts = preg_split('/<br\s*\/?>/i', $text);
            if (is_array($parts) && count($parts) > 0) {
                $intro = trim((string) array_shift($parts));
                $rest = array_values(array_filter(array_map(static fn ($p) => trim((string) $p), $parts), static fn ($p) => $p !== ''));
                if (!empty($rest)) {
                    $outro = implode("\n", $rest);
                }
            } else {
                $intro = $text;
            }

            // Quando exigir login, mantém o padrão de enviar o link logo após a saudação
            if ($loginUrl && ($payload['login'] ?? false) === true) {
                $messages[] = ($intro !== '' ? $intro . "\nFaça login aqui:" : "Faça login aqui:");
                $messages[] = $loginUrl;
            } else {
                if ($intro !== '') {
                    $messages[] = $intro;
                }
                // O $outro será enviado após os dados (boletos/card/ir)
            }
        }

        // Boletos
        if (!empty($payload['boletos']) && is_array($payload['boletos'])) {
            foreach ($payload['boletos'] as $boleto) {
                foreach ($this->formatBoleto($boleto) as $line) {
                    $messages[] = $line;
                }
            }
        }

        // Carteirinha / Planos / Financeiro / Coparticipação
        $this->appendCardSection($messages, $payload);

        // IR (Informe de Rendimentos) – formatação multi-linha
        if (!empty($payload['ir']['documentos']) && is_array($payload['ir']['documentos'])) {
            foreach ($payload['ir']['documentos'] as $doc) {
                $operadora = trim((string) ($doc['nomeOperadora'] ?? ''));
                $ano       = $doc['anoCalendario'] ?? null;
                $titular   = trim((string) ($doc['nomeTitular'] ?? ''));
                $cpf       = trim((string) ($doc['cpfTitular'] ?? ''));
                $link      = $doc['link'] ?? null;

                $lines = [];
                if ($operadora !== '') { $lines[] = $operadora; }
                if ($ano) { $lines[] = "Ano de exercício: {$ano}"; }
                if ($titular !== '') { $lines[] = "Titular: {$titular}"; }
                if ($cpf !== '') { $lines[] = "CPF do titular: {$cpf}"; }
                if ($link) {
                    $lines[] = 'Link informe de rendimentos:';
                    $lines[] = $link;
                }

                $msg = implode("\n", array_filter($lines, static fn ($v) => trim((string) $v) !== ''));
                if ($msg !== '') {
                    $messages[] = $msg;
                }
            }
        }

        // Depois de anexar os dados, envia o(s) trecho(s) de encerramento
        if ($outro !== '' && !($loginUrl && ($payload['login'] ?? false) === true)) {
            $messages[] = $outro;
        }

        return array_values(array_filter($messages));
    }

    private function appendCardSection(array &$messages, array $payload): void
    {
        // Beneficiários
        if (!empty($payload['beneficiarios']) && is_array($payload['beneficiarios'])) {
            foreach ($payload['beneficiarios'] as $b) {
                $nome = $b['nome'] ?? '';
                $tipo = $b['tipo'] ?? '';
                $carteira = $b['numerocarteira'] ?? '';
                $plano = $b['plano'] ?? '';
                $vig = $b['datavigencia'] ?? '';
                // Formatação multi-linha para beneficiário em uma única mensagem
                $header = trim($nome . ($tipo ? " ({$tipo})" : ''));
                $lines = [];
                if ($header !== '') { $lines[] = $header; }
                if ($carteira !== '') { $lines[] = " • Carteira: {$carteira}"; }
                if ($plano !== '') { $lines[] = " • Plano: {$plano}"; }
                if ($vig !== '') { $lines[] = " • Vigência: {$vig}"; }
                $messages[] = implode("\n", $lines);
            }
        }

        // Planos (contratos)
        if (!empty($payload['planos']) && is_array($payload['planos'])) {
            foreach ($payload['planos'] as $c) {
                $oper = trim((string) ($c['operadora'] ?? ''));
                $plano = trim((string) ($c['plano'] ?? ''));
                $cop = trim((string) ($c['coparticipacao'] ?? ''));
                $vigRaw = $c['datavigencia'] ?? null;
                $vig = $this->formatDate(is_string($vigRaw) ? $vigRaw : null) ?? (is_string($vigRaw) ? trim($vigRaw) : '');
                $sit = trim((string) ($c['situacao'] ?? ''));

                $lines = [];
                if ($oper !== '') { $lines[] = "• {$oper}"; }
                if ($plano !== '') { $lines[] = "• {$plano}"; }
                if ($cop !== '') { $lines[] = "Copart.: {$cop}"; }
                if ($vig !== '') { $lines[] = "Vigencia: {$vig}"; }
                if ($sit !== '') { $lines[] = "Situação: {$sit}"; }

                if (!empty($lines)) {
                    $messages[] = implode("\n", $lines);
                }
            }
        }

        // Financeiro (Ficha financeira)
        if (!empty($payload['fichafinanceira']) && is_array($payload['fichafinanceira'])) {
            foreach ($payload['fichafinanceira'] as $item) {
                $plano = $item['plano'] ?? '';
                $entries = $item['fichafinanceira'] ?? [];

                // Bloco de lançamentos (quatro linhas por item: ref, valor, venc, pag + separador)
                $lines = [];
                foreach ($entries as $e) {
                    $ref = $e['referencia'] ?? null;

                    // valorDocumento no payload pode vir como 'valordocumento' (snakecase minúsculo)
                    $valorDoc = $e['valordocumento'] ?? ($e['valor'] ?? ($e['valorDocumento'] ?? null));
                    $valorFmt = $valorDoc !== null ? $this->formatCurrency($valorDoc) : null;

                    $venc = $e['dataVencimento'] ?? ($e['datavencimento'] ?? null);
                    $pag  = $e['dataPagamento'] ?? ($e['datapagamento'] ?? null);

                    $vencFmt = $this->formatDate($venc);
                    $pagFmt  = $this->formatDate($pag);

                    // Linhas separadas conforme solicitado
                    $lineRef   = $ref ? "Ref.: {$ref}" : '';
                    $lineValor = $valorFmt ? "Valor: {$valorFmt}" : '';
                    $lineVenc  = $vencFmt ? "Venc.: {$vencFmt}" : '';
                    $linePag   = $pagFmt ? "Pag.: {$pagFmt}" : '';

                    if ($lineRef !== '')   { $lines[] = $lineRef; }
                    if ($lineValor !== '') { $lines[] = $lineValor; }
                    if ($lineVenc !== '')  { $lines[] = $lineVenc; }
                    if ($linePag !== '')   { $lines[] = $linePag; }

                    // Linha separadora – maior que a maior das linhas visíveis
                    if ($lineRef !== '' || $lineValor !== '' || $lineVenc !== '' || $linePag !== '') {
                        $lengths = [];
                        foreach ([$lineRef, $lineValor, $lineVenc, $linePag] as $ln) {
                            if ($ln !== '') {
                                $lengths[] = function_exists('mb_strlen') ? mb_strlen($ln, 'UTF-8') : strlen($ln);
                            }
                        }
                        $baseLen = !empty($lengths) ? max($lengths) : 26;
                        $sepLen = max(26, min(200, $baseLen + 4));
                        $lines[] = str_repeat('-', $sepLen);
                    }
                }

                // Só envia se houve lançamentos
                if (!empty($lines)) {
                    if (trim($plano) !== '') {
                        $messages[] = "• Plano: {$plano}";
                    }
                    $messages[] = implode("\n", $lines);
                }
            }
        }

        // Coparticipação
        if (!empty($payload['coparticipacao']) && is_array($payload['coparticipacao'])) {
            foreach ($payload['coparticipacao'] as $item) {
                $plano = $item['plano'] ?? '';
                $entries = $item['coparticipacao'] ?? [];
                foreach ($entries as $e) {
                    $ref = $e['referencia'] ?? null;
                    $total = $e['total'] ?? ($e['valor'] ?? null);
                    $valorFmt = $total !== null ? $this->formatCurrency($total) : null;

                    // Cabeçalho por referência (sem o termo "Coparticipação")
                    $headerParts = [];
                    if ($plano !== '') { $headerParts[] = "Plano: {$plano}"; }
                    if ($ref) { $headerParts[] = "Ref.: {$ref}"; }
                    if ($valorFmt) { $headerParts[] = "Valor: {$valorFmt}"; }
                    $messages[] = implode(' • ', $headerParts);

                    // Bloco de detalhes (quebra automática pelo chunker)
                    $detalhes = $e['detalhes'] ?? [];
                    $detailLines = [];
                    if (is_array($detalhes)) {
                        foreach ($detalhes as $d) {
                            $ben = trim((string) ($d['beneficiario'] ?? ''));
                            $local = trim((string) ($d['local'] ?? ''));
                            // Linha 1: beneficiário (apenas nome)
                            $linha1 = $ben;

                            $data = $d['dataevento'] ?? null;
                            $dataFmt = null;
                            if (is_string($data) && $data !== '') {
                                $ts = strtotime($data);
                                $dataFmt = $ts ? date('d/m/Y', $ts) : $data;
                            }

                            $qtd = $d['quantidade'] ?? null;
                            $vTot = $d['valortotal'] ?? ($d['total'] ?? ($d['valor'] ?? null));
                            $vTotFmt = $vTot !== null ? $this->formatCurrency($vTot) : null;

                            // Linha 2: local (somente local)
                            $linha2 = $local;

                            // Linha 3: data do evento (apenas a data)
                            $linha3 = $dataFmt ? "Data: {$dataFmt}" : 'Data: ';

                            // Linha 4: quantidade e valor total
                            $linha4Parts = [];
                            if ($qtd !== null && $qtd !== '') { $linha4Parts[] = "Qtd: {$qtd}"; }
                            if ($vTotFmt) { $linha4Parts[] = "Valor: {$vTotFmt}"; }
                            $linha4 = implode(' • ', $linha4Parts);

                            if ($linha1 !== '') { $detailLines[] = $linha1; }
                            if ($linha2 !== '') { $detailLines[] = $linha2; }
                            if ($linha3 !== '') { $detailLines[] = $linha3; }
                            if ($linha4 !== '') { $detailLines[] = $linha4; }
                            // Linha separadora fixa entre registros
                            $detailLines[] = str_repeat('-', 44);
                        }
                    }

                    if (!empty($detailLines)) {
                        $messages[] = implode("\n", $detailLines);
                    }
                }
            }
        }
    }

    private function formatBoleto(array $boleto): array
    {
        $lines = [];

        $status = strtolower((string) ($boleto['status'] ?? ''));
        $isDisponivel = $status === 'disponivel';
        $cabecalho = $isDisponivel ? 'Boleto disponível' : 'Boleto indisponível';

        $vencimento = $boleto['dataVencimento'] ?? null;
        $valor = $boleto['valorDocumento'] ?? null;
        $mensagem = $boleto['mensagem'] ?? null;
        $linhaDigitavel = $boleto['linha_digitavel'] ?? ($boleto['linhaDigitavel'] ?? null);
        $pdf = $boleto['link'] ?? null;

        $valorFormatado = $valor !== null ? $this->formatCurrency($valor) : null;
        $linhaFormatada = $linhaDigitavel ? $this->formatLinhaDigitavel($linhaDigitavel) : null;

        $topLines = [];
        $topLines[] = $cabecalho;
        $prefix = $isDisponivel ? '' : '* ';

        if ($vencimento) {
            $topLines[] = $prefix . "Venc.: {$vencimento}";
        }
        if ($valorFormatado) {
            $topLines[] = $prefix . "Valor: {$valorFormatado}";
        }
        if (!$isDisponivel && $mensagem) {
            $topLines[] = '* ' . $mensagem;
        }

        if ($linhaFormatada) {
            $topLines[] = $prefix . 'Linha digitável:';
        }


        $lines[] = implode("\n", array_filter($topLines));

        if ($linhaFormatada) {
            $lines[] = $linhaFormatada;
        }

        if ($pdf && $isDisponivel) {
            $lines[] = 'Clique no link abaixo para abrir o PDF';
            $lines[] = $pdf;
        }

        return array_values(array_filter($lines, static fn ($line) => trim((string) $line) !== ''));
    }

    private function formatCurrency($valor): string
    {
        $number = is_string($valor) ? (float) str_replace(',', '.', $valor) : (float) $valor;
        return 'R$ ' . number_format($number, 2, ',', '.');
    }

    private function formatLinhaDigitavel(string $linha): string
    {
        $digits = preg_replace('/\D/', '', $linha);
        if (strlen($digits) === 47) {
            return preg_replace(
                '/(\d{5})(\d{5})(\d{5})(\d{6})(\d{5})(\d{6})(\d)(\d{14})/',
                '$1.$2 $3.$4 $5.$6 $7 $8',
                $digits
            );
        }

        return $linha;
    }

    private function formatDate(?string $date): ?string
    {
        if ($date === null || trim((string) $date) === '') {
            return null;
        }

        $ts = strtotime((string) $date);
        if ($ts === false) {
            return trim((string) $date);
        }

        return date('d/m/Y', $ts);
    }
}
