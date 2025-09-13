<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;
use function PHPUnit\Framework\fileExists;
use Prism\Prism\Exceptions\PrismException;
use Exception;

class ImageAnalysisService
{
    /**
     * Analisa imagem usando Prism com OpenAI Vision
     */
    public function analyzeImage(UploadedFile $imageFile): string
    {
        // Valida o arquivo
        if (!$this->isValidImageFile($imageFile)) {
            throw new Exception('Arquivo de imagem inválido ou muito grande');
        }

        // Se for PDF, extrai texto
        if ($imageFile->getClientOriginalExtension() === 'pdf') {
            return $this->extractTextFromPDF($imageFile);
        }

        $tempPath = $this->storeTemporaryFile($imageFile);

        try {
            // Cria objeto Image do Prism
            $imageObject = Image::fromLocalPath($tempPath);

            $response = Prism::text()
                ->using(Provider::OpenAI, 'gpt-4o-mini')
                ->withMessages([
                    new UserMessage('', [
                        new Text('Analise esta imagem e extraia todas as informações relevantes. Seja detalhado e organize as informações de forma clara.'),
                        $imageObject
                    ])
                ])
                ->asText();

            return $response->text;

        } catch (PrismException $e) {
            throw new Exception('Falha na análise da imagem: ' . $e->getMessage());
        } finally {
            // Remove arquivo temporário
            if (fileExists($tempPath)) unlink($tempPath);
            //Storage::delete(str_replace(storage_path('app/'), '', $tempPath));
        }
    }

    /**
     * Extrai texto de PDF (método simplificado)
     */
    private function extractTextFromPDF(UploadedFile $pdfFile): string
    {
        $tempPath = $this->storeTemporaryFile($pdfFile);

        try {
            // Usando pdftotext se disponível (poppler-utils)
            $command = "pdftotext " . escapeshellarg($tempPath) . " -";
            $output = shell_exec($command);

            if ($output) {
                return "Texto extraído do PDF:\n" . trim($output);
            }

            // Fallback: converte PDF para imagem e usa OCR via GPT-4V
            return $this->convertPDFToImageAndAnalyze($tempPath);

        } finally {
            // Remove arquivo temporário
            if (fileExists($tempPath)) unlink($tempPath);
            //Storage::delete(str_replace(storage_path('app/'), '', $tempPath));
        }
    }

    /**
     * Converte PDF para imagem e analisa (fallback)
     */
    private function convertPDFToImageAndAnalyze(string $pdfPath): string
    {
        try {
            // Converte primeira página do PDF para imagem usando ImageMagick
            $imagePath = str_replace('.pdf', '.png', $pdfPath);
            $command = "convert " . escapeshellarg($pdfPath . "[0]") . " " . escapeshellarg($imagePath);
            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($imagePath)) {
                $imageObject = Image::fromLocalPath($imagePath);

                $response = Prism::text()
                    ->using(Provider::OpenAI, 'gpt-4o-mini')
                    ->withMessages([
                        new UserMessage('', [
                            new Text('Esta é uma imagem convertida de um PDF. Extraia todo o texto e informações visíveis, organizando de forma clara.'),
                            $imageObject
                        ])
                    ])
                    ->asText();

                // Remove arquivo de imagem temporário
                if (fileExists($imagePath)) unlink($imagePath);

                return $response->text;
            }

            throw new Exception('Falha na conversão do PDF');

        } catch (Exception $e) {
            throw new Exception('Falha no processamento do PDF: ' . $e->getMessage());
        }
    }

    /**
     * Armazena arquivo temporariamente
     */
    private function storeTemporaryFile(UploadedFile $file): string
    {
        $filename = 'temp_image_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('temp', $filename, 'private_images');

        return storage_path('app/image/' . $path);
    }

    /**
     * Valida se o arquivo de imagem é compatível
     */
    private function isValidImageFile(UploadedFile $file): bool
    {
        $allowedMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'application/pdf'
        ];

        return in_array($file->getMimeType(), $allowedMimeTypes) &&
               $file->getSize() <= 5 * 1024 * 1024; // 5MB limite
    }
}
