@extends('layout')

@section('title', 'Rendu ' . $page . ' · Shugoi WebSocket Lab')

@section('content')
<section class="result" data-shugoi-ws-payload="{{ $requestId }}">
    <p class="eyebrow">CONTENU HTML SERVI PAR L’APPLICATION</p>
    <div class="result-icon">✓</div>
    <h1>Rendu <span>{{ $page }}</span> reçu.</h1>
    <p class="intro">Si tu vois cette page après l’écran de démarrage Shugoi, le navigateur a reçu le document protégé. Vérifie dans Network que le rendu est arrivé sur le WebSocket attendu.</p>
    <dl class="facts">
        <div><dt>Identifiant du rendu</dt><dd><code>{{ $requestId }}</code></dd></div>
        <div><dt>Généré côté serveur</dt><dd><code>{{ $generatedAt }}</code></dd></div>
        <div><dt>Chemin testé</dt><dd><code>/protected/{{ strtolower($page) }}</code></dd></div>
    </dl>
    <a class="button" href="{{ route('home') }}">Retour au labo <span>↗</span></a>
</section>
@endsection
