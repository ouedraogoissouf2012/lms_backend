{{-- #1705 : l'export des données d'un élève (droit d'accès, loi 2013-450). Une section par source. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Données de {{ $eleve->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 4px; border-bottom: 1px solid #999; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th, td { border: 1px solid #ccc; padding: 3px; text-align: left; vertical-align: top; word-break: break-word; }
        th { background: #f0f0f0; }
        .vide { color: #777; font-style: italic; }
    </style>
</head>
<body>
    <h1>Données détenues sur {{ $eleve->name }}</h1>
    <p>Export du {{ $genere_le->format('d/m/Y H:i') }}, à la demande de l'élève (loi n° 2013-450).</p>

    @foreach ($donnees as $cle => $lignes)
        <h2>{{ $titres[$cle] ?? $cle }}</h2>
        @php($lignes = array_is_list($lignes) ? $lignes : [$lignes])
        @if (count($lignes) === 0)
            <p class="vide">Aucune donnée.</p>
        @else
            <table>
                <tr>@foreach (array_keys($lignes[0]) as $colonne)<th>{{ $colonne }}</th>@endforeach</tr>
                @foreach ($lignes as $ligne)
                    <tr>@foreach ($ligne as $valeur)<td>{{ is_scalar($valeur) || $valeur === null ? $valeur : json_encode($valeur, JSON_UNESCAPED_UNICODE) }}</td>@endforeach</tr>
                @endforeach
            </table>
        @endif
    @endforeach
</body>
</html>
