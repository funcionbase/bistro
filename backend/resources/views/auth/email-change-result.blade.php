<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Cambio de correo — {{ config('app.name') }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #F4F5F7; color: #1E232E; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #fff; border: 1px solid #E5E7EB; border-radius: 16px; padding: 32px; max-width: 460px; width: 100%; box-shadow: 0 10px 30px rgba(30,35,46,0.06); }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { font-size: 15px; line-height: 1.55; color: #3F4754; margin: 0 0 16px; }
        .email { display: inline-block; background: #C0FD79; color: #1E232E; font-weight: 600; padding: 4px 10px; border-radius: 9999px; }
        .btn { display: inline-block; width: 100%; box-sizing: border-box; text-align: center; background: #1E232E; color: #fff; border-radius: 10px; padding: 13px 18px; font-size: 15px; font-weight: 600; text-decoration: none; }
        .muted { font-size: 13px; color: #6B7280; margin-top: 18px; }
        a { color: #0052FF; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        @if ($ok)
            <h1>¡Listo! Tu cuenta se movió</h1>
            <p>Ahora entrás a tu cuenta de {{ config('app.name') }} con <span class="email">{{ $newEmail }}</span>. Iniciá sesión con Google usando ese correo.</p>
            <a class="btn" href="{{ $loginUrl }}">Iniciar sesión</a>
        @else
            <h1>No se pudo completar el cambio</h1>
            <p>{{ $message }}</p>
            <a class="btn" href="{{ $loginUrl }}">Ir a {{ config('app.name') }}</a>
            <p class="muted">Si el problema sigue, escribinos a <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
        @endif
    </div>
</div>
</body>
</html>
