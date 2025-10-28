<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; line-height: 1.6; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #10b981; padding-bottom: 20px; }
        h1 { color: #065f46; margin: 0; font-size: 24px; }
        .meta { color: #6b7280; font-size: 11px; margin-top: 10px; }
        .stats-grid { display: table; width: 100%; margin: 20px 0; }
        .stat-box { display: table-cell; width: 25%; text-align: center; padding: 15px; background: #f3f4f6; border: 1px solid #e5e7eb; }
        .stat-label { font-size: 10px; color: #6b7280; text-transform: uppercase; }
        .stat-value { font-size: 20px; font-weight: bold; color: #1f2937; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        thead { background: #10b981; color: white; }
        th, td { padding: 10px; text-align: left; border: 1px solid #e5e7eb; }
        th { font-weight: 600; font-size: 11px; }
        tr:nth-child(even) { background: #f9fafb; }
        .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <div class="meta">
            Période : {{ $date_start }} - {{ $date_end }}<br>
            Généré le : {{ $generated_at }}
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Total Soumissions</div>
            <div class="stat-value">{{ $stats['total_submissions'] }}</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Moyenne Générale</div>
            <div class="stat-value" style="color: #3b82f6;">{{ $stats['average'] }}/20</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Note la Plus Haute</div>
            <div class="stat-value" style="color: #10b981;">{{ $stats['highest'] }}/20</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Note la Plus Basse</div>
            <div class="stat-value" style="color: #ef4444;">{{ $stats['lowest'] }}/20</div>
        </div>
    </div>

    <h2 style="color: #065f46; margin-top: 30px;">Détails par Étudiant</h2>

    <table>
        <thead>
            <tr>
                <th>Étudiant</th>
                <th>Email</th>
                <th>Nb Évaluations</th>
                <th>Moyenne</th>
                <th>Meilleure</th>
                <th>Moins Bonne</th>
            </tr>
        </thead>
        <tbody>
            @foreach($students as $student)
            <tr>
                <td>{{ $student['name'] }}</td>
                <td>{{ $student['email'] }}</td>
                <td>{{ $student['total_evaluations'] }}</td>
                <td style="font-weight: 600;">{{ $student['average'] }}/20</td>
                <td style="color: #10b981;">{{ $student['highest'] }}/20</td>
                <td style="color: #ef4444;">{{ $student['lowest'] }}/20</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Document généré automatiquement par le système LMS
    </div>
</body>
</html>
