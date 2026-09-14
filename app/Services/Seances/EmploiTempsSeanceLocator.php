<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;

/**
 * Retrouve UNE séance d'un porteur dans l'emploi du temps KLASSCI.
 *
 * ## Pourquoi cette classe existe
 *
 * Le LMS cherchait ses séances dans `matieres/{id}.data.seances_programmees`.
 * Cette clé est **systématiquement vide**. Mesure refaite le 2026-09-14 avec le
 * jeton réel de l'enseignant `bede@gmail.com`, sur la production :
 *
 *     matiere 1 (Marketing digital) : seances_programmees = 0 | statistiques.seances.total_programmees = 47
 *     matiere 2 (Algorithme)        : seances_programmees = 0 | statistiques.seances.total_programmees = 51
 *     matiere 3 (Anglais)           : seances_programmees = 0 | statistiques.seances.total_programmees = 28
 *
 * KLASSCI se contredit dans le MÊME corps de réponse. Toute recherche fondée sur
 * cette clé échoue par construction — c'est ce qui rendait l'activation visio
 * impossible depuis toujours (#739) : `activate()` sortait en 404 « Séance non
 * trouvée » sur des séances qui existent.
 *
 * ## Un seul appel, pas N
 *
 * L'ancienne recherche interrogeait `matieres/{id}` **une fois par matière** de
 * l'enseignant, séquentiellement. Toutes ces requêtes étaient inutiles, et cette
 * rafale est exactement le motif qui arme le filtre anti-abus de l'hébergement
 * KLASSCI (#744). L'emploi du temps répond en **un** appel, mis en cache 600 s.
 *
 * ## Ce qui reste à faire, et qui n'est pas ici
 *
 * `KlassciSeanceLookupService` et son moteur `KlassciSeanceMatiereScanner`
 * rendent le même service et portent le même défaut : `findSeance()` y lit
 * encore la clé morte. Les migrer dessus impose de reprendre le parcours
 * « détail d'une séance », qui relève du périmètre restant de #740 et mérite ses
 * propres tests. Cette classe est le remplaçant canonique vers lequel ils
 * doivent converger — pas une troisième implémentation destinée à coexister.
 *
 * Vérifié par tests/Feature/Visio/VisioActivationSeanceSourceTest.php.
 */
final class EmploiTempsSeanceLocator
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly KlassciEmploiTempsSeances $emploiTemps,
    ) {}

    /**
     * La séance et la matière qui la porte, ou `[null, null]`.
     *
     * Le contrat de retour est celui de l'ancienne recherche, volontairement :
     * les appelants n'ont pas à changer de forme en changeant de source.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    public function locate(int $seanceId, User $user, ?string $klassciToken): array
    {
        if ($klassciToken === null || $klassciToken === '') {
            return [null, null];
        }

        $matieresParId = $this->matieresOf($user, $klassciToken);

        if ($matieresParId === []) {
            return [null, null];
        }

        [$dateDebut, $dateFin] = SeancesWindow::rolling();

        // Le filtre par matière est LOCAL : KLASSCI accepte `matiere_id` et
        // l'ignore — mesuré, il rendait les séances d'autres matières.
        $parMatiere = $this->emploiTemps->fetchByMatiere(
            $klassciToken,
            array_keys($matieresParId),
            $dateDebut,
            $dateFin,
        );

        foreach ($parMatiere as $matiereId => $seances) {
            foreach ($seances as $seance) {
                if (KlassciPayload::toInt($seance['id'] ?? null) === $seanceId) {
                    return [$seance, $matieresParId[$matiereId] ?? null];
                }
            }
        }

        return [null, null];
    }

    /**
     * Les matières du porteur, indexées par identifiant KLASSCI.
     *
     * L'aiguillage par rôle est celui qui existait : l'enseignant lit son
     * tableau de bord, les autres le catalogue. Le changer serait un autre sujet
     * — et pour le coordinateur, un sujet à part entière (#757).
     *
     * @return array<int, array<string, mixed>>
     */
    private function matieresOf(User $user, string $klassciToken): array
    {
        $reponse = $user->isTeacher()
            ? $this->klassciService->requestWithUserToken($klassciToken, 'me/teacher-dashboard', 'GET')
            : $this->klassciService->requestWithUserToken($klassciToken, 'matieres', 'GET');

        $brutes = $user->isTeacher()
            ? KlassciPayload::listOfArrays(KlassciPayload::asArray($reponse['data'] ?? null)['matieres'] ?? null)
            : KlassciPayload::listOfArrays($reponse['data'] ?? null);

        $parId = [];

        foreach ($brutes as $matiere) {
            $id = KlassciPayload::toInt($matiere['id'] ?? null);

            if ($id !== null) {
                $parId[$id] = $matiere;
            }
        }

        return $parId;
    }
}
