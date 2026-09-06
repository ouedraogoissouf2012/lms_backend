<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Services\Seances\KlassciEmploiTempsSeances;
use App\Services\Seances\KlassciPayload;
use App\Services\Seances\SeancesWindow;

/**
 * Résout, pour un enseignant, les séances de chacune de ses matières.
 *
 * ## Issue #515 — élimine le N+1 HTTP
 *
 * Extrait de `KlassciSeancesSyncService` pour garder le fichier sous la limite
 * §1.1 (≤300 lignes) : cette classe a une seule responsabilité — obtenir les
 * séances par matière — sans couplage avec la logique d'upsert.
 *
 * ## La source a changé, et c'était vital
 *
 * Cette classe interrogeait `matieres/{id}` pour en lire
 * `data.seances_programmees`, une clé **toujours vide** chez KLASSCI (mesuré le
 * 2026-09-05). La synchronisation ne confirmait donc JAMAIS aucune séance :
 * `SeanceSyncStamper` n'estampillait rien, et
 * {@see StaleSeanceArchiver} — qui archive tout ce dont `synced_at` est nul ou
 * antérieur au cycle — désactivait l'intégralité des séances actives du tenant
 * à chaque passage, sous le motif `supprimee_klassci`. Aucune garde de famine
 * n'existe : seule une EXCEPTION souille le cycle et suspend l'archivage.
 *
 * La source est désormais l'emploi du temps, la même que celle des listes
 * affichées, sur la même fenêtre ({@see SeancesWindow}) : ce que l'utilisateur
 * voit est exactement ce que la synchronisation confirme.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §1.6 D (DI strict)
 */
final class TeacherMatieresResolver
{
    public function __construct(
        private readonly KlassciEmploiTempsSeances $emploiTemps,
    ) {}

    /**
     * Récupère les détails de chaque matière listée, via UN SEUL appel batch
     * (pool HTTP parallèle) plutôt que N appels séquentiels.
     *
     * Les matières sans ID exploitable sont écartées avant l'appel batch
     * (comportement inchangé, jamais compté comme erreur). Les matières AVEC
     * un ID exploitable mais absentes du résultat batch (échec HTTP individuel
     * — tolérance partielle de `KlassciBatchFetcher`) sont distinguées via
     * `failedMatiereIds`, pour que l'appelant puisse restaurer le comptage
     * `stats->errors` qu'assurait l'ancien code séquentiel (#515 — sinon un
     * échec de fetch redevient invisible côté supervision).
     *
     * @param  array<int, array<string, mixed>>  $matieresList
     */
    public function resolve(array $matieresList, string $teacherToken): TeacherMatieresResolution
    {
        $matieresById = KlassciPayload::keyById(
            $matieresList,
            fn (array $matiere): ?int => KlassciPayload::toInt($matiere['id'] ?? null),
        );
        if ($matieresById === []) {
            return new TeacherMatieresResolution([], []);
        }

        [$dateDebut, $dateFin] = SeancesWindow::rolling();

        $seancesParMatiere = $this->emploiTemps->fetchByMatiere(
            $teacherToken,
            array_keys($matieresById),
            $dateDebut,
            $dateFin,
        );

        // Toute matière de l'enseignant est résolue, y compris celles sans
        // séance dans la fenêtre : c'est une information, pas un échec. Une
        // matière « vide » doit être parcourue pour que ses séances locales
        // devenues absentes de KLASSCI soient bien archivées.
        $resolved = [];
        foreach ($matieresById as $matiereId => $matiere) {
            $resolved[$matiereId] = new ResolvedMatiere($matiere, $seancesParMatiere[$matiereId] ?? []);
        }

        // Plus d'échec PARTIEL possible : l'emploi du temps est UN appel. Soit il
        // aboutit et couvre toutes les matières, soit il lève — et l'appelant
        // souille alors le cycle, ce qui suspend l'archivage (#582).
        return new TeacherMatieresResolution($resolved, []);
    }
}
