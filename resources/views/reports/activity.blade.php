<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; line-height: 1.6; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #f59e0b; padding-bottom: 20px; }
        h1 { color: #92400e; margin: 0; font-size: 24px; }
        .meta { color: #6b7280; font-size: 11px; margin-top: 10px; }
        .section { margin: 30px 0; padding: 20px; background: #f9fafb; border-left: 4px solid #f59e0b; }
        h2 { color: #92400e; margin: 0 0 15px 0; font-size: 16px; }
        .info-grid { display: table; width: 100%; }
        .info-row { display: table-row; }
        .info-label { display: table-cell; padding: 8px; font-weight: 600; width: 40%; }
        .info-value { display: table-cell; padding: 8px; }
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

    <div class="section">
        <h2>Utilisateurs</h2>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nouveaux utilisateurs (période)</div>
                <div class="info-value">{{ $users['new'] }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Total utilisateurs</div>
                <div class="info-value">{{ $users['total'] }}</div>
            </div>
            @foreach($users['by_role'] as $role => $count)
            <div class="info-row">
                <div class="info-label">{{ ucfirst($role) }}</div>
                <div class="info-value">{{ $count }}</div>
            </div>
            @endforeach
        </div>
    </div>

    <div class="section">
        <h2>Évaluations</h2>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nouvelles évaluations (période)</div>
                <div class="info-value">{{ $evaluations['new'] }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Total évaluations</div>
                <div class="info-value">{{ $evaluations['total'] }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Soumissions (période)</div>
                <div class="info-value">{{ $evaluations['submissions'] }}</div>
            </div>
        </div>
    </div>

    <div class="footer">
        Document généré automatiquement par le système LMS
    </div>
</body>
</html>
