<?php

declare(strict_types=1);

namespace App\Services\Suivi\Decrochage;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audience\ElevesDeLaClasse;
use App\Services\Roster\RosterAuthorityFactory;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * #1704 : les élèves d'une classe à suivre, par trois règles simples, chacune rendue avec sa valeur
 * et son seuil, sans modèle opaque :
 *
 * - `sans_connexion` : aucune connexion depuis au moins N jours (`users.last_login_at`) ; `jours`
 *   nul quand aucune connexion n'est enregistrée ;
 * - `remises_manquees` : au moins M évaluations attendues sans copie remise ({@see RemisesManquees}) ;
 * - `chapitres_non_ouverts` : au moins P % des chapitres jamais ouverts ({@see ChapitresNonOuverts}).
 *
 * Les seuils sont ceux de l'école ({@see ReglagesDuDecrochage}). Les élèves sont les inscrits de la
 * classe ({@see ElevesDeLaClasse}), sans appel KLASSCI. Cinq requêtes par classe, quel que soit
 * l'effectif. Un élève sans aucune raison n'est pas listé ; les plus signalés d'abord.
 */
final class DecrochageDeLaClasse
{
    public function __construct(
        private readonly ElevesDeLaClasse $eleves,
        private readonly RosterAuthorityFactory $autorites,
        private readonly RemisesManquees $remises,
        private readonly ChapitresNonOuverts $chapitres,
    ) {}

    /** @return array<string, mixed> */
    public function de(Classe $classe): array
    {
        $maintenant = Carbon::now();
        $ecole = (int) $classe->institution_id;
        $reglages = ReglagesDuDecrochage::deLEcole($ecole);
        $eleves = $this->eleves->de($classe, $ecole);
        $evaluations = $this->autorites->sourceForInstitution(Institution::query()->find($ecole), KlassciEvaluationsDeLaClasse::class, LocalEvaluationsDeLaClasse::class);
        $remises = $this->remises->de($evaluations->de($classe), $eleves, $ecole, $maintenant);
        $chapitres = $this->chapitres->de($classe, $eleves, $maintenant);

        $aSuivre = [];
        foreach ($eleves as $eleve) {
            $raisons = array_values(array_filter([
                $this->sansConnexion($eleve, $reglages, $maintenant),
                $this->remisesManquees($remises['attendues'], $remises['remises'][(int) $eleve->id] ?? 0, $reglages),
                $this->chapitresNonOuverts($chapitres['total'], $chapitres['ouverts'][(int) $eleve->id] ?? 0, $reglages),
            ]));
            if ($raisons !== []) {
                $aSuivre[] = ['compte_id' => (int) $eleve->id, 'nom' => (string) $eleve->name, 'derniere_connexion' => $eleve->last_login_at?->toIso8601String(), 'raisons' => $raisons];
            }
        }
        usort($aSuivre, static fn (array $a, array $b): int => [count($b['raisons']), $a['nom'], $a['compte_id']] <=> [count($a['raisons']), $b['nom'], $b['compte_id']]);

        return [
            'classe' => ['id' => (int) $classe->id, 'libelle' => (string) $classe->libelle],
            'reglages' => $reglages->enTableau(),
            'effectif' => $eleves->count(),
            'a_suivre' => $aSuivre,
        ];
    }

    /** @return array{regle: string, jours: int|null, seuil: int}|null */
    private function sansConnexion(User $eleve, ReglagesDuDecrochage $reglages, CarbonInterface $maintenant): ?array
    {
        $derniere = $eleve->last_login_at;
        $jours = $derniere === null ? null : (int) floor(max(0.0, (float) $derniere->diffInDays($maintenant, false)));

        return $jours === null || $jours >= $reglages->joursSansConnexion
            ? ['regle' => 'sans_connexion', 'jours' => $jours, 'seuil' => $reglages->joursSansConnexion]
            : null;
    }

    /** @return array{regle: string, manquees: int, attendues: int, seuil: int}|null */
    private function remisesManquees(int $attendues, int $remises, ReglagesDuDecrochage $reglages): ?array
    {
        $manquees = $attendues - $remises;

        return $manquees >= $reglages->remisesManquees
            ? ['regle' => 'remises_manquees', 'manquees' => $manquees, 'attendues' => $attendues, 'seuil' => $reglages->remisesManquees]
            : null;
    }

    /** @return array{regle: string, non_ouverts: int, total: int, part: int, seuil: int}|null */
    private function chapitresNonOuverts(int $total, int $ouverts, ReglagesDuDecrochage $reglages): ?array
    {
        $nonOuverts = $total - $ouverts;

        // Comparaison entière exacte (`non_ouverts / total >= P %`) ; la part rendue est arrondie vers le bas.
        return $total > 0 && $nonOuverts * 100 >= $reglages->partChapitresNonOuverts * $total
            ? ['regle' => 'chapitres_non_ouverts', 'non_ouverts' => $nonOuverts, 'total' => $total, 'part' => intdiv($nonOuverts * 100, $total), 'seuil' => $reglages->partChapitresNonOuverts]
            : null;
    }
}
