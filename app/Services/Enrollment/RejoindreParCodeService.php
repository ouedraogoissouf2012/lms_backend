<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Psr\Log\LoggerInterface;

/**
 * La troisième porte, AUTHENTIFIÉE : un apprenant qui a déjà un compte rejoint
 * une classe avec son code (#885, ADR-803-03, ADR-885-01).
 *
 * La porte anonyme (#846) refuse tout compte existant : une adresse n'y prouve
 * rien, et poser un mot de passe dessus serait une prise de contrôle. Ce refus
 * laissait sans recours l'apprenant d'une seconde formation, celui créé par
 * import puis activé, celui qui revient — tout le monde, dès la deuxième fois.
 * Ici le jeton prouve l'identité ; il ne reste qu'à vérifier le code.
 *
 * Ce n'est pas une quatrième porte : même code, même résolution
 * ({@see ClasseOuverteParCode}), même écriture
 * (`StudentEnrolmentService::rejoindre()`).
 *
 * ## L'établissement vient du COMPTE, jamais de la requête
 *
 * Le code n'est unique que par établissement. Un en-tête `X-Institution`
 * laisserait un apprenant rejoindre une classe d'une école qui ne le connaît
 * pas. `ResolveInstitution` l'ignore déjà en présence d'un jeton ; ce service ne
 * s'en remet pas pour autant au tenant ambiant, et lit le compte lui-même.
 *
 * ## Seul un apprenant adhère
 *
 * `role:etudiant` ne suffit pas : `EnsureRole` laisse passer le `superAdmin`
 * d'établissement sur toute route qui n'exige pas `supradmin`. Sans ce contrôle,
 * un administrateur apparaîtrait dans la liste des élèves de la classe.
 *
 * ## Un plafond d'ÉCHECS par établissement
 *
 * Le seau `rejoindre-classe` borne chaque compte. Il ne borne pas l'ensemble :
 * des comptes créés un à un par la porte anonyme s'accumulent, et chacun
 * apporte ses propres essais. Seuls les échecs sont comptés — un code valide
 * n'est jamais un essai de trop.
 *
 * Le prix est assumé : quelques comptes malveillants peuvent fermer cette porte
 * à leur propre école pour la journée. C'est le même arbitrage que la borne
 * globale de la porte anonyme, et il est journalisé pour qu'on le voie.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class RejoindreParCodeService
{
    /**
     * Avec K codes ouverts, le risque quotidien de tomber sur l'un d'eux est
     * au plus 200·K / 31⁶ — ~1,1·10⁻⁵ pour 50 classes ouvertes.
     */
    private const ECHECS_PAR_JOUR = 200;

    private const UN_JOUR = 86_400;

    public function __construct(
        private readonly ClasseOuverteParCode $classes,
        private readonly StudentEnrolmentService $inscriptions,
        private readonly RateLimiter $limiteur,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws BusinessException 403 non apprenant, 404 code sans inscription
     *                           ouverte, 409 adhésion close, 429 échecs épuisés
     */
    public function rejoindre(User $apprenant, string $code): ClasseRejointe
    {
        $ecole = $this->ecoleDeLApprenant($apprenant);
        $echecs = self::cleDesEchecs($ecole->id);

        if ($this->limiteur->tooManyAttempts($echecs, self::ECHECS_PAR_JOUR)) {
            $this->logger->warning('Rejoindre par code : plafond d\'échecs atteint', [
                'institution_id' => $ecole->id,
            ]);

            // `institution_cap_reached` et non le motif du seau du compte (#906) :
            // l'un se lève en attendant une minute, l'autre ferme la porte à
            // toute l'école jusqu'au lendemain.
            throw new BusinessException(
                'Trop de codes erronés aujourd\'hui dans cet établissement. Réessayez demain.',
                429,
                reason: 'institution_cap_reached',
            );
        }

        try {
            $classe = $this->classes->resoudre($code, $ecole);
        } catch (BusinessException $refus) {
            $this->limiteur->hit($echecs, self::UN_JOUR);

            throw $refus;
        }

        return new ClasseRejointe($classe, $this->inscriptions->rejoindre($apprenant, $classe));
    }

    /**
     * La clé du plafond d'échecs d'un établissement. Publique pour que les
     * tests la LISENT au lieu de la recopier : une clé dupliquée qui dérive
     * laisse un seau plein d'un test à l'autre, sans bruit.
     */
    public static function cleDesEchecs(int $institution): string
    {
        return 'rejoindre-echecs|institution:'.$institution;
    }

    /**
     * @throws BusinessException
     */
    private function ecoleDeLApprenant(User $apprenant): Institution
    {
        if ($apprenant->asRoleEnum() !== Role::Etudiant) {
            throw new BusinessException('Seul un apprenant peut rejoindre une classe avec un code.', 403);
        }

        $ecole = $apprenant->institution;

        // Un compte de plateforme n'appartient à aucune école : aucun code ne
        // peut lui désigner une classe sans ambiguïté.
        if (! $ecole instanceof Institution) {
            throw new BusinessException('Ce compte n\'est rattaché à aucun établissement.', 409, reason: 'no_institution');
        }

        return $ecole;
    }
}
