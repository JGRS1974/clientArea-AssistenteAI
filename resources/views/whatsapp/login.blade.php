<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar</title>
    <!-- Materialize CSS (para visual igual ao exemplo) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css" rel="stylesheet"/>
    <style>
        .modal-card { max-width: 420px; margin: 40px auto; border-radius: 6px; overflow: hidden; box-shadow: 0 3px 12px rgba(0,0,0,.2); }
        .modal-header { background: #c62828; color: #fff; padding: 14px 16px; font-size: 18px; }
        .modal-body { background: #fff; padding: 16px; }
        .modal-actions { display: flex; justify-content: space-between; align-items: center; padding: 8px 16px 16px; }
        .cancel-link { color: #666; }
        .field { margin-bottom: 12px; }
        .field label { color: #666; }
    </style>
</head>
<body class="grey lighten-4">
<div class="modal-card">
    <div class="modal-header">Entrar</div>
    <div class="modal-body">
        <form method="POST" action="{{ URL::temporarySignedRoute('whatsapp.login.submit', now()->addMinutes(15), ['token' => $token]) }}">
            @csrf
            <div class="field input-field">
                <input id="cpf" name="cpf" type="text" value="{{ old('cpf') }}" maxlength="20">
                <label for="cpf">CPF</label>
                @error('cpf')<span class="red-text text-darken-2" style="font-size: 0.9rem;">{{ $message }}</span>@enderror
            </div>
            <div class="field input-field">
                <input id="password" name="password" type="password">
                <label for="password">Senha</label>
                @error('password')<span class="red-text text-darken-2" style="font-size: 0.9rem;">{{ $message }}</span>@enderror
            </div>
            <div class="modal-actions">
                <a class="cancel-link" href="#" onclick="window.close(); return false;">Cancelar</a>
                <button type="submit" class="btn blue">Entrar</button>
            </div>
        </form>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>
