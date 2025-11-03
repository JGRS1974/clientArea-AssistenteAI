<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Repositories\KwCacheRepository;
use App\Services\ApiConsumerService;
use App\Services\CanonicalConversationService;
use App\Services\PinGeneratorService;
use App\Services\WhatsApp\LoginLinkService;
use App\Services\WhatsApp\MessageChunker;
use App\Services\WhatsApp\WhatsAppMessageFormatter;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WhatsappLoginController extends Controller
{
    public function __construct(
        private LoginLinkService $links,
        private ApiConsumerService $api,
        private KwCacheRepository $kwRepo,
        private WhatsAppSender $sender,
        private WhatsAppMessageFormatter $formatter,
        private MessageChunker $chunker,
        private CanonicalConversationService $canonicalConversations,
        private PinGeneratorService $pinService,
    ) {
    }

    public function show(Request $request, string $token)
    {
        if (!$request->hasValidSignature()) {
            abort(403);
        }

        $phone = $this->links->resolvePhoneByToken($token);
        if (!$phone) {
            abort(403);
        }

        return view('whatsapp.login', [
            'token' => $token,
            'phone' => $phone,
        ]);
    }

    public function submit(Request $request, string $token)
    {
        if (!$request->hasValidSignature()) {
            abort(403);
        }

        $phone = $this->links->resolvePhoneByToken($token);
        if (!$phone) {
            abort(403);
        }

        $data = $request->only(['cpf', 'password']);
        $v = Validator::make($data, [
            'cpf' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        $cpfDigits = preg_replace('/\D/', '', $data['cpf']);

        // Chamada ao endpoint de login do sistema externo/corpe.
        // Ajuste a URL conforme o backend já usado pelo front atual.
        $url = rtrim(env('IR_API_BASE_URL'), '/') . '/v2/corpore/wscorp.dll/datasnap/rest/tsmadesao/login';
        $pinResult = $this->pinService->generatePinWithDailyCache($cpfDigits);
        if (!($pinResult['success'] ?? false) || empty($pinResult['pin'])) {
            return back()->withErrors(['cpf' => 'Não foi possível gerar o PIN. Verifique o CPF e tente novamente.'])->withInput();
        }

        $payload = [
            'cpf' => $cpfDigits,
            'senha' => $data['password'],
            'pin' => $pinResult['pin'],
        ];
        Log::info('payload login whatsapp',[$payload]);
        $response = $this->api->apiConsumer($payload, $url, [], 30);

        if (!($response['success'] ?? false)) {
            return back()->withErrors(['cpf' => 'Não foi possível autenticar. Verifique os dados e tente novamente.'])->withInput();
        }

        $kw = $response['result']['kw'] ?? null;
        if (!$kw) {
            return back()->withErrors(['cpf' => 'Falha ao obter credenciais.'])->withInput();
        }

        // Persiste a KW e o CPF no cache da conversa (até 23:59)
        $this->kwRepo->putUntilEndOfDay($phone, $kw);
        $this->kwRepo->rememberCpf($phone, $cpfDigits);

        $canonical = $this->canonicalConversations->linkPhoneToCpf($phone, $cpfDigits);
        $this->kwRepo->putUntilEndOfDay($canonical, $kw);
        $this->kwRepo->rememberCpf($canonical, $cpfDigits);

        // Envia ao modelo a mensagem: "já fiz login e meu cpf é xxxx" com o kw
        $chatResponse = $this->dispatchToChat([
            'text' => sprintf('já fiz login e meu cpf é %s', $cpfDigits),
            'conversation_id' => $canonical,
            'kw' => $kw,
        ]);

        // Entrega o retorno ao WhatsApp do usuário
        $payload = $chatResponse['json'] ?? [];
        $messages = $this->formatter->toTextMessages($payload, null);
        $messages = $this->chunker->chunk($messages);
        foreach ($messages as $msg) {
            $this->sender->sendText($phone, $msg);
            usleep(200000);
        }

        // Esquece o token para evitar reuso
        $this->links->forgetToken($token);

        // Tela de sucesso simples
        return view('whatsapp.login-success');
    }

    private function dispatchToChat(array $data): array
    {
        $symfony = Request::create('/api/chat', 'POST', $data);
        $response = app()->handle($symfony);
        $content = $response->getContent();
        $json = null;
        try {
            $json = json_decode($content, true);
        } catch (\Throwable $e) {
            $json = null;
        }
        return ['status' => $response->getStatusCode(), 'json' => $json];
    }
}
