<?php

namespace App\Services;

use Exception;
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\ValueObjects\Media\Audio;

use Prism\Prism\Exceptions\PrismException;

class AudioTranscriptionService
{
    /**
     * Transcreve áudio usando Prism com OpenAI Whisper
     */
    public function transcribe(UploadedFile $audioFile): string
    {
        // Valida o arquivo
        if (!$this->isValidAudioFile($audioFile)) {
            throw new Exception('Arquivo de áudio inválido ou muito grande');
        }

        $tempPath = $this->storeTemporaryFile($audioFile);

        try {
            // Cria objeto Audio do Prism a partir do arquivo local

            $audioObject = Audio::fromLocalPath(path: $tempPath);

            $response = Prism::audio()
                ->using(Provider::OpenAI, 'whisper-1')
                ->withInput($audioObject)
                ->withProviderOptions([
                    'language' => 'pt', // Português
                ])
                ->asText();

            $transcribedText = trim($response->text);

            // Processa CPF se encontrado
            $processedText = $this->processCpfInText($transcribedText);

            return $processedText;

        } catch (PrismException $e) {
            throw new Exception('Falha na transcrição: ' . $e->getMessage());
        } finally {
            // Remove arquivo temporário
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            //Storage::delete($tempPath);
        }
    }

    /**
     * Armazena arquivo temporariamente
     */
    private function storeTemporaryFile(UploadedFile $file): string
    {
        $filename = 'temp_audio_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('temp', $filename, 'private_files');

        return storage_path('app/audio/' . $path);
    }

    /**
     * Valida se o arquivo de áudio é compatível
     */
    private function isValidAudioFile(UploadedFile $file): bool
    {
        $allowedMimeTypes = [
            'audio/mpeg', 'audio/mp3',
            'audio/wav', 'audio/wave',
            'audio/webm',
            'audio/m4a', 'audio/x-m4a',
            'audio/ogg',
            'audio/flac',
            'audio/aac'
        ];

        return in_array($file->getMimeType(), $allowedMimeTypes) &&
               $file->getSize() <= 25 * 1024 * 1024; // 25MB limite OpenAI
    }

    /**
     * Processa texto transcrito para normalizar CPF
     */
    private function processCpfInText(string $text): string
    {
        // Padrão para detectar números separados por hífen, espaço, ponto ou falados individualmente
        // Ex: "3-0-2-6-5-5-9-8-8-1-8" ou "3 0 2 6 5 5 9 8 8 1 8" ou "302.655.988-18"
        $pattern = '/(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)[\s\-\.]*(\d)/';

        if (preg_match($pattern, $text, $matches)) {
            // Remove o primeiro elemento (match completo) e concatena os dígitos
            array_shift($matches);
            $cpf = implode('', $matches);

            // Valida se tem 11 dígitos
            if (strlen($cpf) === 11 && ctype_digit($cpf)) {
                Log::info('CPF detectado e normalizado: ' . $cpf);
                return preg_replace($pattern, $cpf, $text);
            }
        }

        // Se não encontrou CPF válido, retorna texto original
        return $text;
    }
}
