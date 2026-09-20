<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\User;

/**
 * #760 — la porte « détails d'une classe » en espace **LOCAL**.
 *
 * ## Le défaut
 *
 * `GET /lms/classes/{id}` transmet son identifiant **brut** à KLASSCI
 * ({@see ClasseDetailsQueryService} `:120`) : c'est donc une route en espace
 * KLASSCI. Deux de ses trois appelants le respectent — la liste admin
 * (`useAdminClasses.js:234`, alimentée par `klassciService.getClasses()`) et la
 * carte enseignant (`TeacherClassCard.vue:4`, alimentée par
 * `/lms/teacher/classes`).
 *
 * Le troisième, l'onglet Classes d'une matière (`useMatiereDetails.js:255`), y
 * envoie un identifiant **local**, pris dans `classes_concernees`. KLASSCI rend
 * alors la classe qui porte ce nombre chez LUI — une autre — en 200, sans
 * erreur. Mesuré en production : sur 21 classes, **une seule** a `id` égal à
 * `klassci_id`, et **sept** sont en collision franche.
 *
 * ## Pourquoi une porte, et pas une résolution sur la route existante
 *
 * L'issue proposait de résoudre l'entrée avec `Classe::localIdFor()`. Cette
 * méthode donne la **précédence au local** : sur les sept classes en collision,
 * elle détournerait les deux appelants KLASSCI vers la mauvaise ligne. **Deux
 * chemins cassés pour un réparé**, et silencieusement.
 *
 * ## Pourquoi pas non plus « émettre les deux espaces »
 *
 * Faire porter à `classes_concernees` un second identifiant est précisément ce
 * que `MatiereClassesResolver` a été corrigé pour
 * cesser de faire : « en émettre deux, c'est fabriquer soi-même l'ambiguïté ».
 * Trois tests figent d'ailleurs sa forme exacte `['id', 'nom']`.
 *
 * Reste une seule voie honnête : **l'appelant qui parle local dispose d'une
 * porte qui parle local.** Aucun payload ne change, aucun garde ne rougit, les
 * deux appelants KLASSCI sont intacts. C'est le motif « trois portes, un
 * service » d'ADR-803-03 : la traduction vit au seuil, la logique reste unique.
 *
 * ## Le cas sans correspondance KLASSCI
 *
 * Une classe créée localement (ADR-848-01) a un `klassci_id` nul : il n'existe
 * aucune fiche KLASSCI à aller chercher. Répondre 404 mentirait — la classe
 * existe. Cette porte rend donc **409**, qui dit la vérité : la ressource est
 * là, mais la question posée n'a pas de sens pour elle.
 */
final class ClasseLocalDetailsService
{
    public function __construct(
        private readonly ClasseDetailsQueryService $details,
    ) {}

    /**
     * @param  int  $classeId  Identifiant **local** (clé primaire de `classes`).
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function detailsForLocalId(int $classeId, User $user): array
    {
        $classe = $this->classeLocale($classeId, $user);

        if (! $classe instanceof Classe) {
            return $this->erreur(404, 'Classe non trouvée');
        }

        $klassciId = $classe->klassci_id;

        if (! is_numeric($klassciId)) {
            return $this->erreur(
                409,
                "Cette classe a été créée localement : elle n'a pas de fiche KLASSCI à afficher.",
            );
        }

        return $this->details->getDetailsForUser((int) $klassciId, $user);
    }

    /**
     * Bornage explicite à l'institution, jamais délégué au scope global.
     *
     * Le scope global `BelongsToInstitution` est *fail-open* : sans
     * tenant résolu, il s'efface. Un compte plateforme lirait alors la classe de
     * n'importe quel établissement. Le filtre posé ici ne s'efface pas — sans
     * institution, il ne résout rien (ADR-805-01, le `null` absorbant).
     */
    private function classeLocale(int $classeId, User $user): ?Classe
    {
        $institutionId = $user->institution_id;

        return Classe::query()
            ->where('institution_id', is_numeric($institutionId) ? (int) $institutionId : null)
            ->whereKey($classeId)
            ->first();
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function erreur(int $status, string $message): array
    {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }
}
