<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Présences séance {{ $seance->id }}</title></head>
<body>
<h1>Présences — séance {{ $seance->id }}</h1>
<table>
<thead><tr><th>Nom</th><th>Prénom</th><th>Email</th><th>Statut visio</th><th>Entrée</th><th>Sortie</th></tr></thead>
<tbody>
@foreach ($rows as $row)
<tr>
<td>{{ $row->nom }}</td>
<td>{{ $row->prenom }}</td>
<td>{{ $row->email }}</td>
<td>{{ $row->status }}</td>
<td>{{ $row->joined_at }}</td>
<td>{{ $row->left_at }}</td>
</tr>
@endforeach
</tbody>
</table>
</body>
</html>
