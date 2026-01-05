<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Liste de Présence - Séance #{{ $seance->klassci_seance_id }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #4F46E5;
        }

        .header h1 {
            font-size: 20px;
            color: #4F46E5;
            margin-bottom: 8px;
            font-weight: bold;
        }

        .header .subtitle {
            font-size: 12px;
            color: #666;
        }


        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        thead {
            background-color: #4F46E5;
            color: white;
        }

        thead th {
            padding: 10px 6px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }

        tbody tr {
            border-bottom: 1px solid #E5E7EB;
        }

        tbody tr:hover {
            background-color: #F9FAFB;
        }

        tbody td {
            padding: 8px 6px;
            font-size: 10px;
        }

        tbody tr:nth-child(even) {
            background-color: #F9FAFB;
        }

        .status-present {
            display: inline-block;
            padding: 3px 8px;
            background-color: #10B981;
            color: white;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
        }

        .status-absent {
            display: inline-block;
            padding: 3px 8px;
            background-color: #EF4444;
            color: white;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #E5E7EB;
            font-size: 9px;
            color: #666;
            text-align: center;
        }

        .role-badge {
            display: inline-block;
            padding: 2px 6px;
            background-color: #E0E7FF;
            color: #4F46E5;
            border-radius: 8px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>LISTE DE PRÉSENCE</h1>
        <div class="subtitle">Séance #{{ $seance->klassci_seance_id }}</div>
    </div>

    <!-- Informations séance en colonnes -->
    <table style="width: 100%; margin-bottom: 20px; border-collapse: collapse; border: 1px solid #E5E7EB;">
        <thead>
            <tr style="background-color: #F3F4F6; border-bottom: 2px solid #4F46E5;">
                <th style="padding: 10px; text-align: center; font-size: 10px; font-weight: bold; color: #4F46E5; border-right: 1px solid #E5E7EB;">MATIÈRE</th>
                <th style="padding: 10px; text-align: center; font-size: 10px; font-weight: bold; color: #4F46E5; border-right: 1px solid #E5E7EB;">CLASSE</th>
                <th style="padding: 10px; text-align: center; font-size: 10px; font-weight: bold; color: #4F46E5; border-right: 1px solid #E5E7EB;">ENSEIGNANT</th>
                <th style="padding: 10px; text-align: center; font-size: 10px; font-weight: bold; color: #4F46E5; border-right: 1px solid #E5E7EB;">DATE</th>
                <th style="padding: 10px; text-align: center; font-size: 10px; font-weight: bold; color: #4F46E5;">HORAIRE</th>
            </tr>
        </thead>
        <tbody>
            <tr style="background-color: white;">
                <td style="padding: 12px; text-align: center; font-size: 11px; color: #333; border-right: 1px solid #E5E7EB;">{{ $seance->matiere_nom ?? 'N/A' }}</td>
                <td style="padding: 12px; text-align: center; font-size: 11px; color: #333; border-right: 1px solid #E5E7EB;">{{ $seance->classe_nom ?? 'N/A' }}</td>
                <td style="padding: 12px; text-align: center; font-size: 11px; color: #333; border-right: 1px solid #E5E7EB;">{{ $teacher->name ?? 'N/A' }}</td>
                <td style="padding: 12px; text-align: center; font-size: 11px; color: #333; border-right: 1px solid #E5E7EB;">{{ $seance->visio_started_at ? $seance->visio_started_at->format('d/m/Y') : 'N/A' }}</td>
                <td style="padding: 12px; text-align: center; font-size: 11px; color: #333;">
                    {{ $seance->visio_started_at ? $seance->visio_started_at->format('H:i') : '' }}
                    -
                    {{ $seance->visio_ended_at ? $seance->visio_ended_at->format('H:i') : 'En cours' }}
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Tableau des présences -->
    @php
        // Calculer la durée totale de la séance en minutes
        $seanceDuration = 0;
        if ($seance->visio_ended_at && $seance->visio_started_at) {
            $seanceDuration = $seance->visio_started_at->diffInMinutes($seance->visio_ended_at);
        }
    @endphp
    <table>
        <thead>
            <tr>
                <th style="width: 5%">#</th>
                <th style="width: 25%">Nom et Prénom</th>
                <th style="width: 12%">Taux</th>
                <th style="width: 12%">Arrivée</th>
                <th style="width: 12%">Départ</th>
                <th style="width: 12%">Durée</th>
                <th style="width: 12%">Statut</th>
                <th style="width: 10%">Signature</th>
            </tr>
        </thead>
        <tbody>
            @foreach($attendances as $index => $attendance)
                @if($attendance->user)
                    @php
                        $isPresent = ($attendance->duration_minutes ?? 0) > 0 || $attendance->status === 'connected';
                        // Calculer le taux de participation
                        $participationRate = $seanceDuration > 0
                            ? round((($attendance->duration_minutes ?? 0) / $seanceDuration) * 100, 1)
                            : 0;
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $attendance->user->name }}</td>
                        <td style="text-align: center; font-weight: bold; color: {{ $participationRate >= 75 ? '#10B981' : ($participationRate >= 50 ? '#F59E0B' : '#EF4444') }};">
                            {{ $participationRate }}%
                        </td>
                        <td>{{ $attendance->joined_at ? $attendance->joined_at->format('H:i:s') : 'N/A' }}</td>
                        <td>{{ $attendance->left_at ? $attendance->left_at->format('H:i:s') : 'En cours' }}</td>
                        <td>{{ $attendance->duration_minutes ?? 0 }} min</td>
                        <td>
                            @if($isPresent)
                                <span class="status-present">✓ Présent</span>
                            @else
                                <span class="status-absent">✗ Absent</span>
                            @endif
                        </td>
                        <td></td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <!-- Statistiques en ligne -->
    <table style="width: 100%; margin-bottom: 25px; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #F3F4F6; border-bottom: 2px solid #4F46E5;">
                <th style="padding: 12px; text-align: center; font-size: 11px; font-weight: bold; color: #4F46E5; border-right: 1px solid #E5E7EB;">TOTAL ÉTUDIANTS</th>
                <th style="padding: 12px; text-align: center; font-size: 11px; font-weight: bold; color: #10B981; border-right: 1px solid #E5E7EB;">PRÉSENTS</th>
                <th style="padding: 12px; text-align: center; font-size: 11px; font-weight: bold; color: #EF4444; border-right: 1px solid #E5E7EB;">ABSENTS</th>
                <th style="padding: 12px; text-align: center; font-size: 11px; font-weight: bold; color: #4F46E5;">TAUX DE PRÉSENCE</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding: 15px; text-align: center; font-size: 24px; font-weight: bold; color: #4F46E5; border-right: 1px solid #E5E7EB;">{{ $stats['total'] }}</td>
                <td style="padding: 15px; text-align: center; font-size: 24px; font-weight: bold; color: #10B981; border-right: 1px solid #E5E7EB;">{{ $stats['present'] }}</td>
                <td style="padding: 15px; text-align: center; font-size: 24px; font-weight: bold; color: #EF4444; border-right: 1px solid #E5E7EB;">{{ $stats['absent'] }}</td>
                <td style="padding: 15px; text-align: center; font-size: 24px; font-weight: bold; color: #4F46E5;">{{ $stats['rate'] }}%</td>
            </tr>
        </tbody>
    </table>

    <!-- Signatures sur une seule ligne -->
    <table style="width: 100%; margin-top: 40px;">
        <tr>
            <td style="width: 48%; text-align: center; vertical-align: bottom; border-top: 2px solid #333; padding-top: 8px;">
                <div style="font-size: 11px; color: #666; font-weight: bold;">Signature de l'Enseignant</div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; text-align: center; vertical-align: bottom; border-top: 2px solid #333; padding-top: 8px;">
                <div style="font-size: 11px; color: #666; font-weight: bold;">Signature du Coordinateur</div>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <div class="footer">
        Document généré le {{ $generated_at }} par {{ $generated_by }}<br>
        {{ $school_name }} LMS - Liste de Présence Officielle
    </div>
</body>
</html>
