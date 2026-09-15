<?php

declare(strict_types=1);

namespace App\Services\SchoolRegistration;

use App\Enums\InstitutionMode;
use App\Enums\Role;
use App\Enums\SchoolRequestStatus;
use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Models\SchoolRegistrationRequest;
use App\Models\User;
use App\Rules\UniqueEmailInInstitution;
use App\Services\Activation\ActivationLinkBuilder;
use App\Services\Activation\ActivationTokenService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Décision du supradmin sur une demande d'ouverture d'école (#803, ADR-803-02).
 *
 * ## Une seule transaction, ou rien
 *
 * Valider écrit TROIS choses : l'institution, son premier compte, et le statut
 * de la demande. Si l'une échoue, aucune n'est écrite.
 *
 * Ce n'est pas du confort. Aujourd'hui `InstitutionCrudService::create()` ne
 * crée que la ligne `institutions` ; il n'existe aucun chemin pour y créer le
 * premier compte, et la règle d'unicité d'email refuse en 422 faute de tenant
 * résolu pour un supradmin plateforme. Une validation en deux temps
 * reproduirait donc exactement le défaut #793 : une école où personne ne peut
 * entrer. L'atomicité EST le correctif.
 *
 * ## L'institution naît autonome, et le DÉCLARE
 *
 * `mode` vaut `standalone`. La colonne est NOT NULL avec le défaut `klassci`
 * (#814) : l'omettre ne produirait pas d'erreur, mais une école étiquetée
 * connectée à KLASSCI alors qu'elle n'en dépend pas — et
 * `RosterAuthorityFactory`, qui lit ce mode pour accorder l'inscription
 * locale, la lui refuserait.
 *
 * Le mode est DÉCLARÉ, jamais déduit de `klassci_api_url IS NULL`. Trois
 * raisons, toutes vérifiées :
 *
 *   - une colonne nullable ne distingue pas « pas encore configuré » de
 *     « délibérément autonome » : un oubli de saisie deviendrait un changement
 *     de mode en production, en silence ;
 *   - `KlassciConfigResolver` retombe sur la configuration GLOBALE quand
 *     l'institution n'a pas d'URL, si bien qu'un tenant autonome en reçoit une
 *     dès que `KLASSCI_API_URL` est définie (c'est #792) ;
 *   - la garde OCP n'a jamais interdit cette colonne. Elle interdit de
 *     résoudre le mode ailleurs qu'au point de liaison — et son propre
 *     docblock dit « l'attribut est la seule écriture possible, PUISQUE le
 *     mode est une colonne du modèle » (`scripts/lib/ocp-ratchet.php:31`).
 *
 * Ce service DÉCLARE le mode ; il ne le lit jamais. Le seul lecteur autorisé
 * reste `RosterAuthorityFactory`.
 *
 * ## Aucun mot de passe n'est choisi ici
 *
 * Le compte naît avec un secret aléatoire que personne ne connaît, immédiatement
 * remplacé par celui que le titulaire posera via son lien d'activation. Le
 * produit n'a aucun canal de courriel : lui fabriquer un mot de passe
 * reviendrait à le faire circuler de vive voix, et à le laisser valide
 * indéfiniment.
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
final class SchoolRequestDecisionService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ActivationTokenService $activations,
        private readonly ActivationLinkBuilder $liens,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Valide la demande : ouvre l'institution, son premier compte, et rend le
     * LIEN d'activation complet — jamais le jeton nu.
     *
     * @throws BusinessException
     */
    public function valider(SchoolRegistrationRequest $demande, ?string $slugImpose, User $decideur): EcoleOuverte
    {
        $this->refuserSiDejaTranchee($demande);

        $slug = $this->slugRetenu($demande, $slugImpose);

        $ouverte = $this->db->transaction(function () use ($demande, $slug, $decideur): EcoleOuverte {
            $institution = Institution::create([
                'slug' => $slug,
                'mode' => InstitutionMode::Standalone,
                'name' => $demande->nom_ecole,
                'klassci_api_url' => null,
                'is_active' => true,
            ]);

            $proprietaire = $this->creerProprietaire($demande, $institution);

            $demande->statut = SchoolRequestStatus::Validee;
            $demande->institution_id = $this->cle($institution);
            $demande->decide_par_user_id = $this->cle($decideur);
            $demande->decide_le = now();
            $demande->save();

            // Le lien est bâti DANS la transaction : si l'adresse du front
            // manque, rien n'est écrit. Remettre un lien mort laisserait une
            // école sans accès et une demande marquée validée.
            $activation = $this->liens->pour($this->activations->emettre($proprietaire));

            $this->logger->info('Demande d\'ouverture validee', [
                'demande_id' => $demande->getKey(),
                'institution_id' => $institution->getKey(),
                'decide_par' => $decideur->getKey(),
            ]);

            return new EcoleOuverte($institution, $proprietaire, $activation);
        });

        // `transaction()` rend `mixed` : on RESTREINT, comme le fait déjà
        // `ForumPostService::create()`. Un retour d'un autre type signalerait une
        // transaction interrompue, pas un cas métier.
        if (! $ouverte instanceof EcoleOuverte) {
            throw new BusinessException("Ouverture de l'école interrompue.", 500);
        }

        return $ouverte;
    }

    /**
     * @throws BusinessException
     */
    public function refuser(SchoolRegistrationRequest $demande, string $motif, User $decideur): SchoolRegistrationRequest
    {
        $this->refuserSiDejaTranchee($demande);

        $demande->statut = SchoolRequestStatus::Refusee;
        $demande->motif_refus = $motif;
        $demande->decide_par_user_id = $this->cle($decideur);
        $demande->decide_le = now();
        $demande->save();

        $this->logger->info('Demande d\'ouverture refusee', [
            'demande_id' => $demande->getKey(),
            'decide_par' => $decideur->getKey(),
        ]);

        return $demande;
    }

    /**
     * Le premier compte de l'établissement, en `superAdmin`.
     *
     * L'unicité de l'email est vérifiée contre l'institution NOMMÉE : le
     * supradmin plateforme n'a pas de tenant, et `forCreationBy()` échouerait
     * fail-closed — correctement, mais en rendant l'amorçage impossible.
     *
     * @throws BusinessException
     */
    private function creerProprietaire(SchoolRegistrationRequest $demande, Institution $institution): User
    {
        $validation = Validator::make(
            ['email' => $demande->email_demandeur],
            ['email' => ['required', 'email', UniqueEmailInInstitution::forCreationIn($institution)]],
        );

        if ($validation->fails()) {
            throw new BusinessException((string) $validation->errors()->first('email'), 422);
        }

        $proprietaire = new User;
        $proprietaire->name = $demande->nom_demandeur;
        $proprietaire->email = $demande->email_demandeur;
        $proprietaire->phone = $demande->telephone_demandeur;
        $proprietaire->role = Role::SuperAdmin->value;
        $proprietaire->institution_id = $this->cle($institution);
        // Secret que personne ne connaît : il ne sert qu'à ne jamais laisser la
        // colonne vide. Le titulaire posera le sien via le lien d'activation.
        $proprietaire->password = Str::password(32);
        $proprietaire->save();

        return $proprietaire;
    }

    /**
     * @throws BusinessException
     */
    private function refuserSiDejaTranchee(SchoolRegistrationRequest $demande): void
    {
        if ($demande->statut !== SchoolRequestStatus::EnAttente) {
            throw new BusinessException('Cette demande a déjà été tranchée.', 409);
        }
    }

    /**
     * Restreint `getKey()` — qui rend `mixed` — à l'entier attendu.
     *
     * Ce n'est pas une conversion de confort : une clé non entière signalerait
     * un schéma inattendu, et l'écrire telle quelle dans une colonne typée
     * produirait un défaut silencieux plutôt qu'une panne lisible.
     *
     * @throws BusinessException
     */
    private function cle(Model $modele): int
    {
        $cle = $modele->getKey();

        if (! is_int($cle)) {
            throw new BusinessException('Identifiant de modèle inattendu.', 500);
        }

        return $cle;
    }

    private function slugRetenu(SchoolRegistrationRequest $demande, ?string $slugImpose): string
    {
        $slug = $slugImpose ?? $demande->slug_souhaite;

        return is_string($slug) && $slug !== '' ? $slug : Str::slug((string) $demande->nom_ecole);
    }
}
