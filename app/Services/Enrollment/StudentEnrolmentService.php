<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

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

        $this->inscrire($etudiant, $classe, $pivot);

        return StudentEnrolment::faite();
    }

    /**
     * Inscription décidée par l'ÉTABLISSEMENT — l'import aujourd'hui.
     *
     * `syncWithoutDetaching` et non `attach` : l'opération devient idempotente
     * ET corrective — renvoyer le fichier entier après avoir corrigé un statut
     * est le geste que #718 veut permettre. `attach` lèverait au second envoi
     * sur l'unique `(classe_id, user_id)`, effectif depuis #541.
     *
     * L'établissement est posé ICI, jamais par l'appelant : c'est la forme de
     * la ligne, et ADR-803-03 veut qu'un seul endroit en décide. La date, elle,
     * reste à l'appelant : cette écriture MET À JOUR une ligne existante, et une
     * date par défaut y écraserait la date d'inscription historique.
     *
     * @param  array<string, mixed>  $pivot
     */
    public function inscrire(User $etudiant, Classe $classe, array $pivot = []): void
    {
        // `->id` et non `->getKey()` : ce dernier rend `mixed`, que PHPStan n9
        // refuse comme clé de tableau — à juste titre, une clé non entière
        // silencieusement convertie rattacherait le mauvais compte.
        $classe->etudiants()->syncWithoutDetaching([
            $etudiant->id => ['institution_id' => $classe->institution_id] + $pivot,
        ]);
    }

    /**
     * Adhésion demandée par l'APPRENANT, avec un code (#885).
     *
     * ## Un code fait entrer, il ne rouvre pas
     *
     * `inscrire()` écrase le statut, et c'est juste : c'est l'établissement qui
     * parle. Ici c'est l'apprenant, et le code de sa classe circule par
     * WhatsApp. Si le ressaisir réactivait une adhésion `suspendu` ou
     * `abandonne`, la décision de l'établissement ne vaudrait rien. Une
     * adhésion existante non active est donc REFUSÉE, sans être touchée.
     *
     * C'est la règle de Moodle : `enrol_self::can_self_enrol()` refuse dès
     * qu'une inscription existe, quel que soit son statut
     * (`enrol/self/lib.php:284`).
     *
     * Deux politiques, un seul service : elles ne divergent pas, elles
     * dépendent de QUI agit — ce qu'ADR-803-03 veut voir décidé à un seul
     * endroit.
     *
     * ## La ligne écrite est complète
     *
     * `date_inscription`, qu'ADR-711-02 rend obligatoire à l'écriture locale,
     * et `institution_id`, qu'écrit déjà `ClasseStudentsSynchronizer`.
     *
     * @throws BusinessException 409 si une adhésion non active existe
     */
    public function rejoindre(User $etudiant, Classe $classe): Adhesion
    {
        $statut = $this->statutActuel($etudiant, $classe);

        if ($statut === null) {
            try {
                $classe->etudiants()->attach($etudiant->id, [
                    'institution_id' => $classe->institution_id,
                    'statut' => ClasseEtudiantStatut::Actif->value,
                    'date_inscription' => now()->toDateString(),
                ]);

                return Adhesion::Nouvelle;
            } catch (UniqueConstraintViolationException) {
                // Deux envois simultanés : l'unique `(classe_id, user_id)`
                // tranche, et la ligne du gagnant décide de l'issue.
                $statut = $this->statutActuel($etudiant, $classe);
            }
        }

        if ($statut === ClasseEtudiantStatut::Actif->value) {
            return Adhesion::DejaActive;
        }

        throw new BusinessException(
            'Votre inscription dans cette classe n\'est pas active. Adressez-vous à votre établissement.',
            409,
            // Une décision de l'établissement, pas une panne (#906) : le front
            // ne doit pas l'afficher en erreur technique.
            reason: 'enrolment_not_active',
        );
    }

    /**
     * Lecture directe du pivot : aucun scope de modèle ne s'y applique, donc
     * rien ne peut masquer une ligne existante et laisser croire à son absence.
     */
    private function statutActuel(User $etudiant, Classe $classe): ?string
    {
        $statut = $classe->etudiants()->newPivotStatementForId($etudiant->id)->value('statut');

        return is_string($statut) ? $statut : null;
    }
}
