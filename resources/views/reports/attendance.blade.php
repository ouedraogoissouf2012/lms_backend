<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.6;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 20px;
        }
        h1 {
            color: #1e40af;
            margin: 0;
            font-size: 24px;
        }
        .meta {
            color: #6b7280;
            font-size: 11px;
            margin-top: 10px;
        }
        .stats-grid {
            display: table;
            width: 100%;
            margin: 20px 0;
        }
        .stat-box {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 15px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
        }
        .stat-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
        }
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #1f2937;
            margin-top: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        thead {
            background: #3b82f6;
            color: white;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border: 1px solid #e5e7eb;
        }
        th {
            font-weight: 600;
            font-size: 11px;
        }
        tr:nth-child(even) {
            background: #f9fafb;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 15px;
        }
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
            <div class="stat-label">Total Séances</div>
            <div class="stat-value">{{ $stats['total'] }}</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Présents</div>
            <div class="stat-value" style="color: #10b981;">{{ $stats['presents'] }}</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Absents</div>
            <div class="stat-value" style="color: #ef4444;">{{ $stats['absents'] }}</div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Taux Présence</div>
            <div class="stat-value" style="color: #3b82f6;">{{ $stats['taux_presence'] }}%</div>
        </div>
    </div>

    <h2 style="color: #1e40af; margin-top: 30px;">Détails par Étudiant</h2>

    <table>
        <thead>
            <tr>
                <th>Étudiant</th>
                <th>Email</th>
                <th>Total</th>
                <th>Présents</th>
                <th>Absents</th>
                <th>Retards</th>
                <th>Taux</th>
            </tr>
        </thead>
        <tbody>
            @foreach($students as $student)
            <tr>
                <td>{{ $student['name'] }}</td>
                <td>{{ $student['email'] }}</td>
                <td>{{ $student['total'] }}</td>
                <td><span class="badge badge-success">{{ $student['presents'] }}</span></td>
                <td><span class="badge badge-danger">{{ $student['absents'] }}</span></td>
                <td><span class="badge badge-warning">{{ $student['retards'] }}</span></td>
                <td style="font-weight: 600;">{{ $student['taux'] }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Document généré automatiquement par le système LMS
    </div>
</body>
</html>
