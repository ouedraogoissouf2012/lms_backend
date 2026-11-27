<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Middleware\EnsurePermission;
use App\Services\Autorisation\PermissionsDuRole;

/**
 * Le catalogue VERSIONNÉ des permissions (#1665, lot 1). Une permission nomme ce qu'une route permet de FAIRE,
 * pas qui la fait : les rôles reçoivent des permissions ({@see PermissionsDuRole}),
 * les routes en exigent une (`permission:<valeur>`, {@see EnsurePermission}).
 *
 * Une valeur est un contrat (routes, journal, et bientôt les rôles d'école et les accès individuels enregistrés) :
 * elle ne se renomme pas, elle se remplace et se migre.
 */
enum Permission: string
{
    // ─── Plateforme (le gestionnaire de la plateforme seul)
    case PlateformeEtablissements = 'plateforme.etablissements';
    case PlateformeDemandesEcole = 'plateforme.demandes_ecole';

    // ─── L'école : sa configuration
    case EcoleConfiguration = 'ecole.configuration';
    case EcoleIntegrations = 'ecole.integrations';
    case KlassciTesterConnexion = 'klassci.tester_connexion';
    case NotificationsDiffuser = 'notifications.diffuser';

    // ─── Les comptes
    case ComptesGerer = 'comptes.gerer';
    case ComptesMotDePasse = 'comptes.mot_de_passe';

    // ─── Le pilotage : tableaux, statistiques, rapports
    case PilotageConsulter = 'pilotage.consulter';
    case RapportsPresences = 'rapports.presences';

    // ─── Classes et formations
    case ClassesGerer = 'classes.gerer';
    case ClassesEleves = 'classes.eleves';
    case FormationsGerer = 'formations.gerer';
    case ReferentielConsulter = 'referentiel.consulter';
    case ImportsImporter = 'imports.importer';

    // ─── Contenus et évaluations
    case ContenusRediger = 'contenus.rediger';
    case ForumModerer = 'forum.moderer';
    case EvaluationsGerer = 'evaluations.gerer';
    case NotesSaisir = 'notes.saisir';

    // ─── Séances et visio
    case SeancesGerer = 'seances.gerer';
    case PresencesDepuisVisio = 'presences.depuis_visio';
    case EnseignementConsulter = 'enseignement.consulter';
    case VisioAnimer = 'visio.animer';
    case VisioEnregistrements = 'visio.enregistrements';

    // ─── L'élève
    case InscriptionRejoindre = 'inscription.rejoindre';
    case SeancesMasquer = 'seances.masquer';

    /** Réservée au gestionnaire de la plateforme : jamais à un rôle d'école, ni à un accès individuel. */
    public function estDeLaPlateforme(): bool
    {
        return str_starts_with($this->value, 'plateforme.');
    }
}
