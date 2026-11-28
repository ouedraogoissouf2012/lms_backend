<?php

declare(strict_types=1);

namespace App\Services\EmploiDuTemps;

use App\Exceptions\BusinessException;
use App\Models\EmploiDuTemps;
use App\Models\LigneEmploiDuTemps;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * La semaine type d'un emploi du temps (#1669), remplacée d'un bloc (`PUT …/lignes`) :
 * - une ligne de COURS porte une matière du PROGRAMME de la classe (422 sinon) et son enseignant, par défaut celui du
 *   programme (ARRÊT 1, Q2), sinon un enseignant de l'école ; une PAUSE porte un libellé ;
 * - aucune ligne ne chevauche une autre le même jour (422 `grille_en_conflit`) ;
 * - une ligne inchangée garde son id, donc ses séances ; une ligne changée ou disparue voit ses séances FUTURES et non
 *   tenues retirées ({@see SeancesRetirables}), puis s'efface. Les séances tenues restent, sans lien.
 */
final class GrilleDeLEmploi
{
    public const HORS_PROGRAMME = 'matiere_hors_programme';

    public const EN_CONFLIT = 'grille_en_conflit';

    public const ENSEIGNANT_INCONNU = 'enseignant_hors_ecole';

    /** Les champs qui disent si une ligne est inchangée. */
    private const CHAMPS = ['jour' => 0, 'debut' => '', 'fin' => '', 'matiere_id' => null, 'enseignant_id' => null, 'type_seance' => null, 'libelle' => null];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SeancesRetirables $retirables,
    ) {}

    /**
     * @param  list<array{jour: int, debut: string, fin: string, matiere_id: ?int, enseignant_id: ?int, type_seance: ?string, libelle: ?string}>  $lignes
     * @return int les séances retirées
     *
     * @throws BusinessException 422
     */
    public function remplacer(EmploiDuTemps $emploi, array $lignes): int
    {
        $voulues = array_map(fn (array $l): array => $this->resolue($l, $emploi), $lignes);
        self::exigerSansChevauchement($voulues);
        $retirees = $this->db->transaction(fn (): int => $this->synchroniser($emploi, $voulues));

        return is_int($retirees) ? $retirees : 0;
    }

    /**
     * Garder les lignes inchangées, retirer les autres (et leurs séances futures non tenues), créer les nouvelles.
     *
     * @param  list<array<string, mixed>>  $voulues
     */
    private function synchroniser(EmploiDuTemps $emploi, array $voulues): int
    {
        $aCreer = [];
        foreach ($voulues as $ligne) {
            $aCreer[self::cle($ligne)][] = $ligne;
        }
        $retirees = 0;
        foreach (LigneEmploiDuTemps::query()->where('emploi_du_temps_id', $emploi->id)->get() as $ancienne) {
            $cle = self::cle($ancienne->only(array_keys(self::CHAMPS)));
            if (($aCreer[$cle] ?? []) !== []) {
                array_pop($aCreer[$cle]);

                continue;
            }
            $retirees += $this->retirables->retirerDeLaLigne($ancienne);
            $ancienne->delete();
        }
        foreach ($aCreer as $lignes) {
            foreach ($lignes as $ligne) {
                LigneEmploiDuTemps::query()->create(['emploi_du_temps_id' => $emploi->id] + $ligne);
            }
        }

        return $retirees;
    }

    /**
     * @param  array{jour: int, debut: string, fin: string, matiere_id: ?int, enseignant_id: ?int, type_seance: ?string, libelle: ?string}  $ligne
     * @return array{jour: int, debut: string, fin: string, matiere_id: ?int, enseignant_id: ?int, type_seance: ?string, libelle: ?string}
     */
    private function resolue(array $ligne, EmploiDuTemps $emploi): array
    {
        $ligne['debut'] = substr($ligne['debut'], 0, 5).':00';
        $ligne['fin'] = substr($ligne['fin'], 0, 5).':00';
        if ($ligne['matiere_id'] === null) {
            return array_merge($ligne, ['enseignant_id' => null, 'type_seance' => null]);
        }
        $programme = $this->db->table('classe_matiere')
            ->where('classe_id', $emploi->classe_id)->where('matiere_id', $ligne['matiere_id'])->first(['enseignant_id']);
        if ($programme === null) {
            throw new BusinessException('Cette matière n\'est pas au programme de la classe.', 422, null, self::HORS_PROGRAMME);
        }
        $parDefaut = is_numeric($programme->enseignant_id ?? null) ? (int) $programme->enseignant_id : null;
        $ligne['enseignant_id'] ??= $parDefaut;
        $ligne['libelle'] = null;
        if ($ligne['enseignant_id'] !== null) {
            $this->exigerEnseignantDeLEcole($ligne['enseignant_id'], $emploi->institution_id);
        }

        return $ligne;
    }

    private function exigerEnseignantDeLEcole(int $id, int $ecole): void
    {
        $enseignant = User::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)->whereKey($id)->first();
        if (! $enseignant instanceof User || ! $enseignant->isTeacher()) {
            throw new BusinessException('Cet enseignant n\'appartient pas à l\'établissement.', 422, null, self::ENSEIGNANT_INCONNU);
        }
    }

    /** @param  list<array{jour: int, debut: string, fin: string}>  $lignes */
    private static function exigerSansChevauchement(array $lignes): void
    {
        foreach ($lignes as $i => $a) {
            foreach (array_slice($lignes, $i + 1) as $b) {
                if ($a['jour'] === $b['jour'] && $a['debut'] < $b['fin'] && $b['debut'] < $a['fin']) {
                    throw new BusinessException(sprintf('Deux lignes se chevauchent le jour %d.', $a['jour']), 422, null, self::EN_CONFLIT);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $ligne */
    private static function cle(array $ligne): string
    {
        $champs = array_intersect_key($ligne + self::CHAMPS, self::CHAMPS);
        ksort($champs);

        return json_encode($champs, JSON_THROW_ON_ERROR);
    }
}
