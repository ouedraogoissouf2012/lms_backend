{{-- #1662 : la page d'un document de scolarité, tirée de son instantané (`contenu`). Échappée par Blade ({{ }}). --}}
@php
  $c = $document->contenu;
  // Les compléments facultatifs, composés ici : des directives Blade collées (`@endif@if`) ne se compilent pas.
  $periode = empty($c['periode']) ? '' : $c['periode'];
  $depuis = empty($c['inscrit_le']) ? '' : \Illuminate\Support\Carbon::parse($c['inscrit_le'])->format('d/m/Y');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $document->type->titre() }} — {{ $document->numero }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 32px; }
  .ecole { font-size: 16px; font-weight: bold; margin: 0; }
  .numero { margin: 4px 0 24px; color: #444; }
  h1 { font-size: 20px; text-align: center; margin: 24px 0; text-transform: uppercase; }
  p.corps { line-height: 1.7; }
  .verification { margin-top: 48px; font-size: 10px; color: #444; border-top: 1px solid #999; padding-top: 8px; }
</style>
</head>
<body>
<p class="ecole">{{ $c['ecole'] ?? '' }}</p>
<p class="numero">N° {{ $document->numero }} — émis le {{ $document->created_at->format('d/m/Y') }}</p>

<h1>{{ $document->type->titre() }}</h1>

<p class="corps">
@if ($document->type === \App\Enums\TypeDeDocument::CertificatScolarite)
  L'établissement {{ $c['ecole'] ?? '' }} certifie que <strong>{{ $c['titulaire'] ?? '' }}</strong> est régulièrement
  inscrit(e) dans la classe <strong>{{ $c['classe'] ?? '' }}</strong>{{ $periode !== '' ? ', pour la période '.$periode : '' }}.
@else
  L'établissement {{ $c['ecole'] ?? '' }} atteste que <strong>{{ $c['titulaire'] ?? '' }}</strong> fréquente la classe
  <strong>{{ $c['classe'] ?? '' }}</strong>{{ $periode !== '' ? ', période '.$periode : '' }}{{ $depuis !== '' ? ', depuis le '.$depuis : '' }}.
@endif
</p>
<p class="corps">Ce document est délivré pour servir et valoir ce que de droit.</p>

<p class="verification">
  Vérification : code <strong>{{ $document->code_verification }}</strong> —
  {{ rtrim((string) config('app.url'), '/') }}/api/documents/verification/{{ $document->code_verification }}
</p>
</body>
</html>
