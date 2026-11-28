{{-- #1656 : la page d'un export PDF, tirée du même Tableau que le CSV. Échappée par Blade ({{ }}). --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $tableau->titre }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
  h1 { font-size: 16px; margin: 0 0 4px; }
  p.edite { margin: 0 0 12px; color: #555; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
  th { background: #eee; }
</style>
</head>
<body>
<h1>{{ $tableau->titre }}</h1>
<p class="edite">Édité le {{ $edite }} — {{ count($tableau->lignes) }} ligne(s)</p>
<table>
<thead><tr>@foreach ($tableau->entetes as $entete)<th>{{ $entete }}</th>@endforeach</tr></thead>
<tbody>
@foreach ($tableau->lignes as $ligne)
<tr>@foreach ($ligne as $cellule)<td>{{ $cellule }}</td>@endforeach</tr>
@endforeach
</tbody>
</table>
</body>
</html>
