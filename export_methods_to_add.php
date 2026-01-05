    /**
     * Export liste de présence d'une séance en PDF
     * GET /api/lms/seances/{seanceId}/export/presences/pdf
     */
    public function exportSeancePresencesPdf(int $seanceId, Request $request)
    {
        try {
            $user = $request->user();

            // Vérifier les permissions (enseignant ou coordinateur uniquement)
            if (!in_array($user->role, ['enseignant', 'teacher', 'coordinateur'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé. Réservé aux enseignants et coordinateurs.'
                ], 403);
            }

            // Récupérer la séance
            $seance = \App\Models\Seance::where('klassci_seance_id', $seanceId)
                ->with(['matiere', 'classe'])
                ->first();

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Récupérer les participants (excluant les observateurs)
            $attendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
                ->where('is_observer', false)
                ->with('user')
                ->orderBy('joined_at')
                ->get();

            // Trouver l'enseignant
            $teacher = $attendances->first(function ($att) {
                return $att->user && in_array($att->user->role, ['enseignant', 'teacher']);
            });

            // Calculer les statistiques
            $totalStudents = $attendances->filter(function ($att) {
                return $att->user && $att->user->role === 'etudiant';
            })->count();

            $presentStudents = $attendances->filter(function ($att) {
                return $att->user &&
                       $att->user->role === 'etudiant' &&
                       $att->status === 'connected' ||
                       ($att->duration_minutes ?? 0) > 0;
            })->count();

            $absentStudents = $totalStudents - $presentStudents;
            $presenceRate = $totalStudents > 0 ? round(($presentStudents / $totalStudents) * 100, 1) : 0;

            // Préparer les données pour le PDF
            $data = [
                'seance' => $seance,
                'attendances' => $attendances,
                'teacher' => $teacher?->user,
                'stats' => [
                    'total' => $totalStudents,
                    'present' => $presentStudents,
                    'absent' => $absentStudents,
                    'rate' => $presenceRate
                ],
                'generated_at' => now()->format('d/m/Y H:i'),
                'generated_by' => $user->name
            ];

            // Générer le PDF
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.seance-presences', $data);
            $pdf->setPaper('a4', 'portrait');

            $filename = 'presences_seance_' . $seanceId . '_' . now()->format('Ymd_His') . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            Log::error('Erreur export PDF présences', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export liste de présence d'une séance en Excel
     * GET /api/lms/seances/{seanceId}/export/presences/excel
     */
    public function exportSeancePresencesExcel(int $seanceId, Request $request)
    {
        try {
            $user = $request->user();

            // Vérifier les permissions
            if (!in_array($user->role, ['enseignant', 'teacher', 'coordinateur'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé. Réservé aux enseignants et coordinateurs.'
                ], 403);
            }

            // Récupérer la séance
            $seance = \App\Models\Seance::where('klassci_seance_id', $seanceId)
                ->with(['matiere', 'classe'])
                ->first();

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Récupérer les participants
            $attendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
                ->where('is_observer', false)
                ->with('user')
                ->orderBy('joined_at')
                ->get();

            // Créer le fichier Excel
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // En-tête du document
            $sheet->setCellValue('A1', 'LISTE DE PRÉSENCE - SÉANCE #' . $seanceId);
            $sheet->mergeCells('A1:G1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Informations séance
            $row = 3;
            $sheet->setCellValue('A' . $row, 'Matière:');
            $sheet->setCellValue('B' . $row, $seance->matiere->nom ?? 'N/A');
            $row++;
            $sheet->setCellValue('A' . $row, 'Classe:');
            $sheet->setCellValue('B' . $row, $seance->classe->nom ?? 'N/A');
            $row++;
            $sheet->setCellValue('A' . $row, 'Date:');
            $sheet->setCellValue('B' . $row, $seance->visio_started_at ? $seance->visio_started_at->format('d/m/Y') : 'N/A');
            $row++;
            $sheet->setCellValue('A' . $row, 'Horaire:');
            $sheet->setCellValue('B' . $row,
                ($seance->visio_started_at ? $seance->visio_started_at->format('H:i') : '') . ' - ' .
                ($seance->visio_ended_at ? $seance->visio_ended_at->format('H:i') : 'En cours')
            );

            // En-tête du tableau
            $row += 2;
            $headers = ['Nom', 'Prénom', 'Rôle', 'Arrivée', 'Départ', 'Durée (min)', 'Statut'];
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $row, $header);
                $sheet->getStyle($col . $row)->getFont()->setBold(true);
                $sheet->getStyle($col . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('4F46E5');
                $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
                $col++;
            }

            // Données des participants
            $row++;
            foreach ($attendances as $att) {
                if (!$att->user) continue;

                $nameParts = explode(' ', $att->user->name, 2);
                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';

                $sheet->setCellValue('A' . $row, strtoupper($firstName));
                $sheet->setCellValue('B' . $row, $lastName);
                $sheet->setCellValue('C' . $row, ucfirst($att->user->role));
                $sheet->setCellValue('D' . $row, $att->joined_at ? $att->joined_at->format('H:i:s') : 'N/A');
                $sheet->setCellValue('E' . $row, $att->left_at ? $att->left_at->format('H:i:s') : 'En cours');
                $sheet->setCellValue('F' . $row, $att->duration_minutes ?? 0);

                $isPresent = ($att->duration_minutes ?? 0) > 0 || $att->status === 'connected';
                $sheet->setCellValue('G' . $row, $isPresent ? 'Présent' : 'Absent');

                // Colorer en vert si présent, rouge si absent
                $color = $isPresent ? '10B981' : 'EF4444';
                $sheet->getStyle('G' . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($color);
                $sheet->getStyle('G' . $row)->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

                $row++;
            }

            // Statistiques
            $row += 2;
            $totalStudents = $attendances->filter(fn($att) => $att->user && $att->user->role === 'etudiant')->count();
            $presentStudents = $attendances->filter(fn($att) =>
                $att->user && $att->user->role === 'etudiant' && (($att->duration_minutes ?? 0) > 0 || $att->status === 'connected')
            )->count();
            $presenceRate = $totalStudents > 0 ? round(($presentStudents / $totalStudents) * 100, 1) : 0;

            $sheet->setCellValue('A' . $row, 'STATISTIQUES');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            $sheet->setCellValue('A' . $row, 'Total étudiants:');
            $sheet->setCellValue('B' . $row, $totalStudents);
            $row++;
            $sheet->setCellValue('A' . $row, 'Présents:');
            $sheet->setCellValue('B' . $row, $presentStudents);
            $row++;
            $sheet->setCellValue('A' . $row, 'Absents:');
            $sheet->setCellValue('B' . $row, $totalStudents - $presentStudents);
            $row++;
            $sheet->setCellValue('A' . $row, 'Taux de présence:');
            $sheet->setCellValue('B' . $row, $presenceRate . '%');

            // Auto-ajuster les colonnes
            foreach (range('A', 'G') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Générer le fichier
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = 'presences_seance_' . $seanceId . '_' . now()->format('Ymd_His') . '.xlsx';
            $tempFile = sys_get_temp_dir() . '/' . $filename;

            $writer->save($tempFile);

            return response()->download($tempFile, $filename)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Erreur export Excel présences', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du fichier Excel',
                'error' => $e->getMessage()
            ], 500);
        }
    }
