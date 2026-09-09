<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Les classes d'une matière — depuis ses séances ET depuis le miroir local
 * (#740).
 *
 * ## Le dernier maillon de la chaîne qui bloquait la création de leçon
 *
 * `classes_concernees` ne venait que des séances de la matière. Une matière
 * sans séance — cas réel : « Anglais » chez KLASSCI le 2026-09-06 — n'avait
 * donc aucune classe. Le frontend construit `classe_id: classes?.[0]?.id`, donc
 * `null`, et `StoreLessonRequest::authorize()` refusait : **403 « This action is
 * unauthorized »** sur une matière parfaitement légitime.
 *
 * Or la relation existe en clair dans l'autre sens : `classes/{id}` liste ses
 * `matieres`. Ce lien est désormais miroité dans `classe_matiere` par
 * `App\Services\Sync\Classes\ClasseMatieresSynchronizer`.
 *
 * ## Trois sources, dans un ordre qui est une décision
 *
 * 1. **Les séances** : immédiates, déjà en mémoire. Leurs `classe.id` viennent
 *    du payload KLASSCI et sont donc TRADUITS ici.
 * 2. **Le miroir local** : couvre les matières sans séance. Une seule requête,
 *    jamais un appel réseau.
 * 3. **KLASSCI**, en DERNIER recours — `App\Services\Matiere\KlassciMatiereClassesSource`.
 *
 * Les deux premières sont locales et peuvent être muettes ENSEMBLE : une
 * matière sans séance dont le miroir n'a jamais été alimenté. C'était le cas
 * mesuré en production le 2026-09-09 — « Anglais » rendait une liste vide, le
 * frontend envoyait `classe_id: null`, et la création de leçon échouait en 403.
 *
 * La troisième coûte 1 + N appels réseau : tant que le local sait, on ne
 * dérange pas KLASSCI. Elle alimente le miroir au passage, ce qui rend la
 * deuxième source progressivement capable de répondre seule.
 *
 * ## Les identifiants rendus sont LOCAUX
 *
 * ⚠️ Une version antérieure rendait l'espace **KLASSCI**, au motif que
 * `StoreLessonRequest` accepte les deux. Le raisonnement était faux, et le
 * piège sérieux.
 *
 * La résolution d'un identifiant entrant tranche, par construction, en faveur
 * de l'espace LOCAL — c'est le bon arbitrage quand on ignore ce que parle le
 * client. Émettre du KLASSCI revenait donc à proposer une valeur que notre
 * propre résolution relit dans l'AUTRE espace. Sur une collision — l'`id` local
 * d'une classe égal au `klassci_id` d'une autre, cas nominal puisque les deux
 * numérotations démarrent à 1 — la leçon part sur la mauvaise classe, en 201,
 * sans erreur. Et `lessons.classe_id` est le seul verrou de visibilité
 * étudiante.
 *
 * **L'API ne propose que l'espace qu'elle stocke.** Accepter deux espaces en
 * entrée est un service rendu aux clients existants ; en émettre deux, c'est
 * fabriquer soi-même l'ambiguïté.
 *
 * ## L'ordre est un contrat
 *
 * Le frontend prend `classes_concernees[0]` comme classe pré-sélectionnée.
 * L'ordre de la jambe « séances » suivait l'emploi du temps sur une fenêtre
 * GLISSANTE de ±6 mois : il changeait avec la date. Le tri alphabétique est
 * arbitraire, mais stable et explicable — l'ordre précédent était imprévisible.
 *
 * Vérifié par tests/Feature/Matiere/MatiereClassesFromMirrorTest.php et
 * tests/Feature/Matiere/MatiereClassesFromKlassciTest.php.
 */
final class MatiereClassesResolver
{
    public function __construct(
        private readonly KlassciMatiereClassesSource $klassciSource,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $seances  Séances déjà enrichies.
     * @return array<int, array{id: int, nom: string}>
     */
    public function resolve(array $seances, int $klassciMatiereId, ?int $institutionId, ?User $teacher = null): array
    {
        $classes = [];

        foreach ($this->fromSeances($seances, $institutionId) as $classe) {
            $classes[$classe['id']] ??= $classe;
        }

        foreach ($this->fromMirror($klassciMatiereId, $institutionId) as $classe) {
            $classes[$classe['id']] ??= $classe;
        }

        // Troisième jambe, en DERNIER recours : demander à KLASSCI.
        //
        // Les deux premières sont locales et peuvent être muettes ensemble —
        // c'est le cas d'une matière SANS séance dont le miroir n'a jamais été
        // alimenté. Mesure du 2026-09-09 en production : « Anglais » rendait
        // `classes_concernees = []`, le frontend envoyait `classe_id: null`, et
        // la création de leçon échouait en 403.
        //
        // Reléguée au dernier rang parce qu'elle coûte 1 + N appels : tant que
        // le local sait, on ne dérange pas KLASSCI.
        if ($classes === [] && $teacher !== null && $institutionId !== null) {
            foreach ($this->klassciSource->classesFor($teacher, $klassciMatiereId, $institutionId) as $classe) {
                $classes[$classe['id']] ??= $classe;
            }
        }

        // Le frontend pré-sélectionne `[0]` : l'ordre doit être stable.
        usort($classes, static fn (array $a, array $b): int => strcasecmp($a['nom'], $b['nom']));

        return $classes;
    }

    /**
     * Les classes portées par les séances, traduites vers l'espace local.
     *
     * La traduction est STRICTE : `classe.id` vient d'un payload KLASSCI, son
     * espace est donc connu. Passer par le résolveur dual rendrait, sur une
     * collision, la ligne de l'autre espace — c'est-à-dire deviner là où l'on
     * sait.
     *
     * Une classe absente du miroir local est écartée. Ce n'est pas une perte :
     * `StoreLessonRequest::authorize()` la refuserait en 403 puisqu'il vérifie
     * la table locale. Proposer une classe inutilisable, c'est proposer une
     * erreur.
     *
     * @param  array<int, array<string, mixed>>  $seances
     * @return array<int, array{id: int, nom: string}>
     */
    private function fromSeances(array $seances, ?int $institutionId): array
    {
        $classes = [];

        foreach (MatiereClassesExtractor::fromSeances($seances) as $classe) {
            $localId = Classe::localIdForKlassciId($classe['id'], $institutionId);

            if ($localId !== null) {
                $classes[] = ['id' => $localId, 'nom' => $classe['nom']];
            }
        }

        return $classes;
    }

    /**
     * @return array<int, array{id: int, nom: string}>
     */
    private function fromMirror(int $klassciMatiereId, ?int $institutionId): array
    {
        if ($institutionId === null) {
            return [];
        }

        // Recherche STRICTE par `klassci_id` : l'identifiant vient de la route
        // KLASSCI, son espace est connu. Même raison que dans `fromSeances()`.
        $matiereId = Matiere::localIdForKlassciId($klassciMatiereId, $institutionId);

        if ($matiereId === null) {
            return [];
        }

        $classeIds = DB::table('classe_matiere')
            ->where('matiere_id', $matiereId)
            ->where('institution_id', $institutionId)
            ->pluck('classe_id')
            ->all();

        if ($classeIds === []) {
            return [];
        }

        $lignes = Classe::query()
            ->where('institution_id', $institutionId)
            ->whereIn('id', $classeIds)
            ->whereNotNull('klassci_id')
            ->orderBy('id')
            ->get();

        $classes = [];
        foreach ($lignes as $ligne) {
            $classes[] = [
                'id' => (int) $ligne->id,
                'nom' => (string) ($ligne->libelle ?? 'N/A'),
            ];
        }

        return $classes;
    }
}
