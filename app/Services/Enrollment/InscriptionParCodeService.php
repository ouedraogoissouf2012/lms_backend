<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * La troisième porte : l'apprenant s'inscrit lui-même (#846, ADR-803-03).
 *
 * L'import suppose que l'école détienne déjà la liste. C'est vrai d'un
 * établissement scolaire, faux d'un formateur qui ouvre une session et diffuse
 * son code par WhatsApp — le cas d'usage central du monde autonome.
 *
 * ## Un compte existant n'est JAMAIS touché
 *
 * C'est la règle née de #812, durcie par #823 : sur un endpoint anonyme, une
 * adresse n'est pas une preuve de propriété. Poser un mot de passe sur un compte
 * existant serait une **prise de contrôle** — il suffirait de connaître une
 * adresse et un code de classe.
 *
 * L'inscrire sans toucher ses identifiants n'est pas mieux : on rattacherait
 * quelqu'un à une classe **sans son consentement**.
 *
 * On refuse donc, explicitement. Le refus révèle qu'un compte existe, et c'est
 * assumé : la fuite est bornée — il faut déjà détenir un code valide, lui-même
 * un secret de six caractères, et le débit est limité par un seau nommé. En
 * face, le silence laisserait tout apprenant qui revient sans aucun recours.
 *
 * ## Code inconnu et code retiré rendent le MÊME refus
 *
 * Les distinguer dirait à un inconnu qu'un code a existé pour cette valeur —
 * donc qu'une classe l'attend. Même raisonnement qu'ADR-803-02 pour les trois
 * états du jeton d'activation.
 *
 * ## L'écriture passe par le service unique
 *
 * `StudentEnrolmentService::inscrire()`. ADR-803-03 : trois portes, un seul
 * service. Écrire `classe_etudiant` ici produirait une quatrième politique sur
 * les mêmes questions.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class InscriptionParCodeService
{
    public function __construct(
        private readonly StudentEnrolmentService $inscriptions,
        private readonly TenantManager $tenants,
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array{code: string, nom: string, email: ?string, telephone: ?string, password: string}  $valide
     *
     * @throws BusinessException
     */
    public function inscrire(array $valide): Classe
    {
        $institution = $this->tenants->id();

        if ($institution === null) {
            throw new BusinessException('Établissement non résolu.', 400);
        }

        $classe = $this->classeOuverte($valide['code'], $institution);

        $this->refuserSiLeCompteExiste($valide, $institution);

        // Transaction : un compte créé sans son inscription laisserait un
        // apprenant qui ne peut ni entrer dans sa classe, ni recommencer —
        // son adresse serait désormais « déjà prise ».
        $this->db->transaction(function () use ($valide, $institution, $classe): void {
            $etudiant = User::query()->create([
                'institution_id' => $institution,
                'name' => $valide['nom'],
                'email' => $valide['email'],
                'phone' => $valide['telephone'],
                // Le clair : le cast `hashed` du modele (User:58) le chiffre.
                // Le hacher ICI produirait une double empreinte le jour ou la
                // detection de Laravel changerait — et s ecarterait de
                // ImportApplyService:179, seul autre createur de compte.
                'password' => $valide['password'],
                'role' => Role::Etudiant->value,
            ]);

            $this->inscriptions->inscrire($etudiant, $classe, ['statut' => 'actif']);
        });

        return $classe;
    }

    /**
     * La classe que ce code ouvre, dans CET établissement.
     *
     * Bornée à l'établissement parce que `code_inscription` n'est unique que
     * par institution : deux écoles peuvent tirer le même code, et un candidat
     * atterrirait dans la mauvaise. Le tenant vient de l'en-tête `X-Institution`,
     * résolu par `ResolveInstitution` en priorité 2.
     *
     * @throws BusinessException
     */
    private function classeOuverte(string $code, int $institution): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()
            ->where('institution_id', $institution)
            ->where('code_inscription', ClasseEnrolmentCode::normaliser($code))
            ->whereNull('code_inscription_revoque_le')
            ->first();

        if (! $classe instanceof Classe) {
            // Inconnu ET retiré rendent ce même message : les séparer dirait
            // qu'un code a existé pour cette valeur.
            throw new BusinessException('Ce code ne correspond à aucune inscription ouverte.', 404);
        }

        return $classe;
    }

    /**
     * @param  array{email: ?string, telephone: ?string}  $valide
     *
     * @throws BusinessException
     */
    private function refuserSiLeCompteExiste(array $valide, int $institution): void
    {
        $existant = User::query()->withoutGlobalScopes()
            ->where('institution_id', $institution)
            ->where(function ($requete) use ($valide): void {
                if ($valide['email'] !== null) {
                    $requete->orWhere('email', $valide['email']);
                }
                if ($valide['telephone'] !== null) {
                    $requete->orWhere('phone', $valide['telephone']);
                }
            })
            ->exists();

        if (! $existant) {
            return;
        }

        // Journalisé SANS l'adresse : le journal n'a pas à dupliquer une donnée
        // personnelle pour signaler une tentative.
        $this->logger->info('Inscription par code refusée : compte déjà existant', [
            'institution_id' => $institution,
        ]);

        throw new BusinessException(
            'Un compte existe déjà avec ces coordonnées. Connectez-vous pour rejoindre cette classe.',
            409
        );
    }
}
