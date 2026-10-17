<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso MCP | {{ config('app.name') }}</title>
</head>
<body>
    <main>
        <h1>Autorizar BalanzaPro en ChatGPT</h1>
        <p>Inicie sesion con la cuenta de administrador autorizada.</p>
        <form method="POST" action="{{ route('mcp.login.store') }}">
            @csrf
            <label for="email">Correo</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
            <label for="password">Contrasena</label>
            <input id="password" name="password" type="password" required>
            @error('email')<p>{{ $message }}</p>@enderror
            <button type="submit">Continuar</button>
        </form>
    </main>
</body>
</html>
