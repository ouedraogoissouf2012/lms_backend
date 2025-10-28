<?php

/**
 * CORRECTIF POUR BUG KLASSCI: date_cours → date_seance
 *
 * CE FICHIER DOIT ÊTRE PLACÉ SUR LE SERVEUR KLASSCI
 *
 * Localisation: /home/c2569688c/public_html/presentation/app/Http/Controllers/API/LMSDataController.php
 * Fonction à corriger: emploiTemps() (autour de la ligne 468)
 *
 * INSTRUCTIONS:
 * 1. Ouvrir le fichier mentionné ci-dessus sur le serveur KLASSCI
 * 2. Chercher la fonction emploiTemps()
 * 3. Remplacer TOUTES les occurrences de 'date_cours' par 'date_seance'
 * 4. Sauvegarder
 *
 * AVANT (bugué):
 * ----------------
 * public function emploiTemps(Request $request)
 * {
 *     $dateDebut = $request->input('date_debut');
 *     $dateFin = $request->input('date_fin');
 *     $enseignantId = $request->input('enseignant_id');
 *     $classeId = $request->input('classe_id');
 *     $anneeId = $request->user()->annee_universitaire_active_id ?? 1;
 *
 *     $query = SeanceCours::query()
 *         ->whereBetween('date_cours', [$dateDebut, $dateFin])  // ❌ ERREUR ICI
 *         ->whereHas('emploiTemps', function ($q) use ($anneeId, $enseignantId, $classeId) {
 *             $q->where('annee_universitaire_id', $anneeId)
 *               ->where('is_active', true);
 *
 *             if ($enseignantId) {
 *                 $q->where('enseignant_id', $enseignantId);
 *             }
 *             if ($classeId) {
 *                 $q->where('classe_id', $classeId);
 *             }
 *         })
 *         ->with(['emploiTemps.matiere', 'emploiTemps.classe', 'emploiTemps.enseignant'])
 *         ->orderBy('date_cours', 'asc')  // ❌ ERREUR ICI
 *         ->orderBy('heure_debut', 'asc')
 *         ->get();
 *
 *     return response()->json([
 *         'success' => true,
 *         'data' => $query
 *     ]);
 * }
 *
 *
 * APRÈS (corrigé):
 * ----------------
 * public function emploiTemps(Request $request)
 * {
 *     $dateDebut = $request->input('date_debut');
 *     $dateFin = $request->input('date_fin');
 *     $enseignantId = $request->input('enseignant_id');
 *     $classeId = $request->input('classe_id');
 *     $anneeId = $request->user()->annee_universitaire_active_id ?? 1;
 *
 *     $query = SeanceCours::query()
 *         ->whereBetween('date_seance', [$dateDebut, $dateFin])  // ✅ CORRIGÉ
 *         ->whereHas('emploiTemps', function ($q) use ($anneeId, $enseignantId, $classeId) {
 *             $q->where('annee_universitaire_id', $anneeId)
 *               ->where('is_active', true);
 *
 *             if ($enseignantId) {
 *                 $q->where('enseignant_id', $enseignantId);
 *             }
 *             if ($classeId) {
 *                 $q->where('classe_id', $classeId);
 *             }
 *         })
 *         ->with(['emploiTemps.matiere', 'emploiTemps.classe', 'emploiTemps.enseignant'])
 *         ->orderBy('date_seance', 'asc')  // ✅ CORRIGÉ
 *         ->orderBy('heure_debut', 'asc')
 *         ->get();
 *
 *     return response()->json([
 *         'success' => true,
 *         'data' => $query
 *     ]);
 * }
 *
 *
 * CHANGEMENTS REQUIS:
 * - Ligne whereBetween: 'date_cours' → 'date_seance'
 * - Ligne orderBy: 'date_cours' → 'date_seance'
 *
 * C'EST TOUT! Seulement 2 mots à changer.
 *
 * TEMPS ESTIMÉ: 2 minutes
 */

echo "Ce fichier contient les instructions pour corriger le bug KLASSCI.\n";
echo "Lisez les instructions ci-dessus.\n";
