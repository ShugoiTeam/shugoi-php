<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>@yield('title', 'Shugoi WebSocket Lab')</title>
    <link rel="stylesheet" href="/demo.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="{{ route('home') }}"><span class="brand-mark">S</span> SHUGOI <span class="muted">/ WS LAB</span></a>
        <span class="environment"><i></i> SITE DE TEST</span>
    </header>
    <main>@yield('content')</main>
    <footer>Environnement de démonstration · Aucun service de production n’est modifié.</footer>
</body>
</html>
