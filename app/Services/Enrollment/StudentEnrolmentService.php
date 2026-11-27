<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\User;
use App\Services\Catalogue\ClasseArchivee;
use Illuminate\Database\DatabaseManager;
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
    /** Le motif du refus d'une classe complète (#1115), lu par le front. */
    public const CLASSE_COMPLETE = 'class_full';

    public function __construct(private readonly DatabaseManager $db) {}

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

        // #1652 : une classe archivée ne reçoit plus personne, l'import compris. Refusée
        // ligne à ligne, nommément, comme une classe inconnue : jamais en silence.
        if ($classe->estArchivee()) {
            return StudentEnrolment::refusee(
                'classe_archivee',
                sprintf('La classe « %s » est archivée : elle ne reçoit plus d\'inscription.', $code),
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
     * la ligne, et ADR-803-03 veut qu'un seul endroit en décide.
     *
     * Les valeurs par défaut aussi ({@see self::defautsALaCreation()}), et elles
     * ne valent qu'à la CRÉATION de l'adhésion (#895, #1237) : cette écriture MET
     * À JOUR une ligne existante, où un défaut écraserait l'historique (la date
     * d'inscription, ADR-711-02) ou la décision de l'établissement (un statut
     * `suspendu` redevenu `actif`). Ce que l'appelant fournit, lui, s'écrit
     * toujours : c'est la correction voulue par #718.
     *
     * @param  array<string, mixed>  $pivot
     */
    public function inscrire(User $etudiant, Classe $classe, array $pivot = []): void
    {
        $ligne = ['institution_id' => $classe->institution_id] + $pivot;

        if ($this->statutActuel($etudiant, $classe) === null) {
            $ligne += $this->defautsALaCreation();
        }

        // `->id` et non `->getKey()` : ce dernier rend `mixed`, que PHPStan n9
        // refuse comme clé de tableau — à juste titre, une clé non entière
        // silencieusement convertie rattacherait le mauvais compte.
        $classe->etudiants()->syncWithoutDetaching([$etudiant->id => $ligne]);
    }

    /**
     * Ce qu'une adhésion NOUVELLE reçoit quand l'appelant ne le dit pas, et
     * qu'une adhésion existante garde.
     *
     * @return array<string, mixed>
     */
    private function defautsALaCreation(): array
    {
        // L'inscription date du moment où elle est réellement faite, comme le
        // fait la synchronisation KLASSCI (`ClasseStudentsSynchronizer`), et
        // l'apprenant entre présent.
        return [
            'statut' => ClasseEtudiantStatut::Actif->value,
            'date_inscription' => now()->toDateString(),
        ];
    }

    /**
     * Adhésion demandée par l'APPRENANT, avec un code (#885).
     *
     * ## Un code fait entrer, il ne rouvre pas
     *
     * `inscrire()` écrit le statut que l'établissement fournit, et c'est juste :
     * c'est lui qui parle (sans statut fourni, l'adhésion existante garde le
     * sien, #1237). Ici c'est l'apprenant, et le code de sa classe circule par
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
     * ## Une classe complète ne reçoit plus personne (#1115)
     *
     * Seule une adhésion NOUVELLE prend une place : un membre déjà actif reste
     * `DejaActive`, une adhésion close garde son refus. La place se compte et
     * s'écrit sous le verrou de la ligne de la classe : deux codes envoyés
     * ensemble ne dépassent pas la capacité ({@see Classe::isComplete()}).
     *
     * ## Une classe archivée ne reçoit plus personne (#1652)
     *
     * Lu sur la ligne VERROUILLÉE, avant l'adhésion existante : un archivage concurrent ne
     * laisse rien passer, et même un membre déjà actif reçoit le refus (aucune écriture
     * n'aurait lieu, mais « déjà inscrit » laisserait croire la classe ouverte).
     *
     * @throws BusinessException 409 si une adhésion non active existe, si la classe est
     *                           complète (`class_full`) ou archivée (`class_archived`)
     */
    public function rejoindre(User $etudiant, Classe $classe): Adhesion
    {
        $statut = $this->db->transaction(fn (): ?string => $this->entrer($etudiant, $classe));

        if ($statut === null) {
            return Adhesion::Nouvelle;
        }

        if ($statut === ClasseEtudiantStatut::Actif->value) {
            return Adhesion::DejaActive;
        }

        throw new BusinessException(
            'Votre inscription dans cette classe n\'est pas active. Adressez-vous à votre établissement.',
            409,
            // Une décision de l'établissement, pas une panne (#906) : le front
            // ne doit pas l'afficher en erreur technique.
            reason: InscriptionNonActive::REASON,
        );
    }

    /**
     * Refuse une adhésion nouvelle dans une classe complète. Publique pour la porte
     * anonyme, qui la pose AVANT de savoir si le compte existe : un compte existant
     * et un compte neuf y reçoivent ainsi la même réponse (#914).
     *
     * @throws BusinessException 409 `class_full`
     */
    public function exigerUnePlace(Classe $classe): void
    {
        if ($classe->isComplete()) {
            throw new BusinessException(
                'Cette classe est complète. Adressez-vous à votre établissement.',
                409,
                // Le code est valide : une décision de capacité, pas une panne (#906).
                reason: self::CLASSE_COMPLETE,
            );
        }
    }

    /**
     * Sous le verrou de la classe : rend le statut d'une adhésion existante, ou
     * écrit l'adhésion nouvelle s'il reste une place et rend `null`.
     *
     * @throws BusinessException 409 `class_full`, 409 `class_archived`
     */
    private function entrer(User $etudiant, Classe $classe): ?string
    {
        $verrouillee = Classe::query()->withoutGlobalScopes()->whereKey($classe->id)->lockForUpdate()->first();

        if (false && $verrouillee?->estArchivee() === true) {
            throw ClasseArchivee::exception();
        }

        $statut = $this->statutActuel($etudiant, $classe);

        if ($statut !== null) {
            return $statut;
        }

        $this->exigerUnePlace($classe);

        try {
            $classe->etudiants()->attach($etudiant->id, [
                'institution_id' => $classe->institution_id,
                'statut' => ClasseEtudiantStatut::Actif->value,
                'date_inscription' => now()->toDateString(),
            ]);

            return null;
        } catch (UniqueConstraintViolationException) {
            // Deux envois simultanés : l'unique `(classe_id, user_id)` tranche, et
            // la ligne du gagnant décide de l'issue. Rattrapée DANS la transaction :
            // MySQL et SQLite n'annulent que l'instruction refusée.
            return $this->statutActuel($etudiant, $classe);
        }
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
