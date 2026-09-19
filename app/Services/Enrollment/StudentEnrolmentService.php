<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;

/**
 * LE service qui inscrit un étudiant dans une classe (#846, ADR-803-03).
 *
 * ## Pourquoi un seul service pour trois portes
 *
 * L'ADR-803-03, accepté, prévoit trois portes — saisie unitaire, import CSV,
 * code d'inscription — et **un seul service**. La raison est écrite noir sur
 * blanc et déjà payée une fois dans ce dépôt : « Trois portes avec trois
 * services produiraient trois politiques divergentes sur les mêmes questions :
 * que faire d'un doublon, d'un étudiant déjà inscrit ailleurs, d'un compte
 * existant avec le même email, d'un code de classe inconnu. »
 *
 * Le prix de deux chemins d'écriture indépendants est visible dans le dépôt :
 * `user_classes` d'un côté, `classe_etudiant` de l'autre, et un
 * `CompositeEnrollmentSource` pour réconcilier après coup.
 *
 * ## Une seule cible : `classe_etudiant`
 *
 * `user_classes` n'est JAMAIS écrite par une inscription locale : elle conserve
 * son unique écrivain légitime, `StudentClassSynchronizer:96`, alimenté par
 * KLASSCI.
 *
 * ## L'établissement est un PARAMÈTRE, pas une lecture du tenant ambiant
 *
 * L'import s'exécute dans un job, où `ResolveInstitution` n'a jamais tourné :
 * s'en remettre à `TenantManager` y donnerait `null`, et la recherche de classe
 * deviendrait non bornée. L'appelant fournit donc l'établissement, qu'il tient
 * de l'import ou du tenant résolu selon la porte.
 */
final class StudentEnrolmentService
{
    /**
     * @param  array<string, mixed>  $pivot  colonnes de `classe_etudiant`
     */
    public function inscrireParCodeDeClasse(
        User $etudiant,
        int $institution,
        string $codeClasse,
        array $pivot = [],
    ): StudentEnrolment {
        $code = trim($codeClasse);

        // Un fichier sans colonne de classe crée des comptes sans les rattacher.
        // C'est légitime, et ce n'est pas une réussite d'inscription.
        if ($code === '') {
            return StudentEnrolment::sansObjet();
        }

        $classe = Classe::query()->withoutGlobalScopes()
            ->where('institution_id', $institution)
            ->where('code', $code)
            ->first();

        // Avant #846, ce cas sortait en SILENCE : l'import se déclarait réussi
        // et l'étudiant n'était dans aucune classe. Un rejet muet est pire
        // qu'une erreur, parce qu'il n'appelle aucune correction.
        if (! $classe instanceof Classe) {
            return StudentEnrolment::refusee(
                'classe_inconnue',
                sprintf('Aucune classe ne porte le code « %s » dans cet établissement.', $code),
            );
        }

        $this->rattacher($classe, $etudiant, $pivot);

        return StudentEnrolment::faite();
    }

    /**
     * `syncWithoutDetaching` et non `attach` : l'opération devient idempotente
     * ET corrective — renvoyer le fichier entier après avoir corrigé un statut
     * est le geste que #718 veut permettre. `attach` créerait un doublon, que
     * l'unique de la table ne bloque pas quand `annee_universitaire_id` est nul,
     * MySQL n'égalant jamais NULL.
     *
     * @param  array<string, mixed>  $pivot
     */
    private function rattacher(Classe $classe, User $etudiant, array $pivot): void
    {
        // `->id` et non `->getKey()` : ce dernier rend `mixed`, que PHPStan n9
        // refuse comme clé de tableau — à juste titre, une clé non entière
        // silencieusement convertie rattacherait le mauvais compte.
        $classe->etudiants()->syncWithoutDetaching([$etudiant->id => $pivot]);
    }
}
