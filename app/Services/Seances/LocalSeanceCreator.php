<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use App\Services\Catalogue\CoupleDuProgramme;
use Carbon\Carbon;

/**
 * #710 — création locale. Jamais d'identifiant local dans klassci_*.
 *
 * ## La classe locale (#846)
 *
 * Jusqu'ici une séance locale ne gardait qu'un `classe_nom`, du texte libre.
 * Elle n'avait donc **aucun lien** vers une classe, et `canRead:25` court-
 * circuitait la porte étudiant : tout apprenant d'une école autonome était privé
 * des enregistrements de ses propres cours, sauf s'il avait été présent.
 *
 * `classe_id` est **facultatif** : une séance peut se créer avant que la classe
 * soit arrêtée, et le champ libre reste utile pour l'affichage. Mais s'il est
 * fourni, la classe doit être de l'établissement.
 *
 * @see docs/adr/2026-09-19-846-01-seance-locale-et-sa-classe.md
 */
final class LocalSeanceCreator
{
    public function __construct(private readonly CoupleDuProgramme $couple) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException si la classe ou la matière n'est pas celle de
     *                           l'établissement (404), ou si la matière n'est
     *                           pas au programme de la classe (422)
     */
    public function create(User $author, array $payload): Seance
    {
        $titre = is_string($payload['titre'] ?? null) ? $payload['titre'] : '';
        $dateSeance = $this->instant($payload['date_seance'] ?? null);
        $classeId = $this->classeDeLEtablissement($payload, $author);
        $matiere = $this->matiereDuProgramme($payload, $author, $classeId);
        $nomLibre = is_string($payload['matiere_nom'] ?? null) ? $payload['matiere_nom'] : null;

        return Seance::query()->create([
            'titre' => $titre,
            'date_seance' => $dateSeance,
            // Avec un id, le libellé de la matière fait foi : le nom libre est ignoré.
            'matiere_id' => $matiere?->id,
            'matiere_nom' => $matiere !== null ? $matiere->libelle : $nomLibre,
            'classe_nom' => is_string($payload['classe_nom'] ?? null) ? $payload['classe_nom'] : null,
            'classe_id' => $classeId,
            'created_by' => $author->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
            'is_active' => true,
            'institution_id' => $author->institution_id,
        ]);
    }

    /**
     * L'instant de la séance, rangé dans le fuseau de l'application : un
     * décalage fait foi (D4), il n'est pas ignoré par le format de la base.
     */
    private function instant(mixed $date): string
    {
        return is_string($date) && $date !== ''
            ? Carbon::parse($date)->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s')
            : '';
    }

    /**
     * La classe est bornée à l'établissement de l'auteur, explicitement.
     *
     * `withoutGlobalScopes` parce que le scope multi-tenant est fail-OPEN : s'y
     * fier serait dépendre d'une garde qui se tait quand aucun tenant n'est
     * résolu — et une séance créée dans un job n'en a pas.
     *
     * Le message ne distingue pas « n'existe pas » de « appartient à une autre
     * école » : les séparer dirait à un établissement quels identifiants
     * existent chez ses voisins.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException
     */
    private function classeDeLEtablissement(array $payload, User $author): ?int
    {
        $classeId = $payload['classe_id'] ?? null;

        if (! is_numeric($classeId)) {
            return null;
        }

        $existe = Classe::query()->withoutGlobalScopes()
            ->where('id', (int) $classeId)
            ->where('institution_id', $author->institution_id)
            ->exists();

        if (! $existe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404, null, 'class_out_of_scope');
        }

        return (int) $classeId;
    }

    /**
     * La matière, bornée à l'établissement de l'auteur comme la classe, et le
     * même refus pour « n'existe pas » et « d'une autre école ». Avec une
     * classe, elle doit être au programme de cette classe : la règle du couple,
     * partagée avec la programmation d'une évaluation (`CoupleDuProgramme`).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException
     */
    private function matiereDuProgramme(array $payload, User $author, ?int $classeId): ?Matiere
    {
        $matiereId = $payload['matiere_id'] ?? null;

        if (! is_numeric($matiereId)) {
            return null;
        }

        $matiere = Matiere::query()->withoutGlobalScopes()
            ->whereKey((int) $matiereId)
            ->where('institution_id', $author->institution_id)
            ->first();

        if ($matiere === null) {
            throw new BusinessException('Matière introuvable dans cet établissement.', 404, null, 'subject_out_of_scope');
        }

        if ($classeId !== null && ! $this->couple->estProgramme($classeId, $matiere->id)) {
            throw new BusinessException(
                'Cette matière n\'est pas au programme de cette classe.',
                422,
                null,
                'subject_not_in_class_program',
            );
        }

        return $matiere;
    }
}
