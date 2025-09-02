<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class BilletController extends Controller
{
    public function downloadPdf($token)
    {
        // Recuperar o base64 do cache usando o token
        $base64Data = Cache::get("boleto_pdf_{$token}");

        if (!$base64Data) {
            return response()->json(['error' => 'Boleto não encontrado ou expirado'], 404);
        }

        // Decodificar o base64
        $pdfContent = base64_decode($base64Data);

        // Retornar o PDF
        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="boleto.pdf"');
    }

    public function viewPdf($token)
    {
        $base64Data = Cache::get("boleto_pdf_{$token}");

        if (!$base64Data) {
            return response()->json(['error' => 'Boleto não encontrado ou expirado'], 404);
        }

        $pdfContent = base64_decode($base64Data);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="boleto.pdf"');
    }

}
