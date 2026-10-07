@extends('layout')

@section('title', 'Shugoi WebSocket Lab')

@section('content')
<section class="hero">
    <p class="eyebrow">PHP SDK · TRANSPORT WEBSOCKET</p>
    <h1>Un terrain de test<br><span>pour le vrai trafic.</span></h1>
    <p class="intro">Cette petite app Laravel permet de vérifier le parcours navigateur → WebSocket same-origin → sidecar OpenSwoole → rendu HTML, sur un domaine séparé.</p>
    <div class="status-row"><span class="pulse"></span> Prêt à tester · domaine annexe · clés Shugoi de test</div>
</section>

<section class="grid" aria-label="Pages de test">
    <a class="card" href="{{ route('protected.page', ['page' => 'alpha']) }}">
        <span class="card-index">01</span>
        <h2>Page protégée Alpha</h2>
        <p>Charge une réponse HTML protégée. Le marqueur de rendu confirme que le contenu a été récupéré via le transport configuré.</p>
        <span class="card-link">Lancer le test <b>↗</b></span>
    </a>
    <a class="card" href="{{ route('protected.page', ['page' => 'beta']) }}">
        <span class="card-index">02</span>
        <h2>Page protégée Beta</h2>
        <p>Répète le parcours sur une autre URL et un autre identifiant de requête pour vérifier que chaque rendu fonctionne.</p>
        <span class="card-link">Lancer le test <b>↗</b></span>
    </a>
</section>

<section class="guide">
    <div>
        <p class="eyebrow">VÉRIFICATION NAVIGATEUR</p>
        <h2>Ce qu’il faut voir</h2>
    </div>
    <ol>
        <li>Ouvre les DevTools, onglet <strong>Network</strong>, filtre <strong>WS</strong>.</li>
        <li>Ouvre Alpha ou Beta. Les sockets Shugoi doivent répondre <code>101 Switching Protocols</code>.</li>
        <li>Vérifie <code>/api/v1/ws-wlc</code> pour le WLC puis <code>/__shugoi/render/ws</code> pour le HTML final.</li>
        <li>La page finale affiche un identifiant de rendu neuf à chaque chargement.</li>
    </ol>
</section>

<p class="health"><span class="dot"></span> <a href="{{ route('health') }}">Vérifier la route de santé JSON</a> <code>/api/ws-demo-health</code></p>
@endsection
