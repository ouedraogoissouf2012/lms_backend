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
    // #1657 : le dossier de l'élève, ses tuteurs et sa photo (écriture).
    case ElevesDossiers = 'eleves.dossiers';

    // ─── Contenus et évaluations
    case ContenusRediger = 'contenus.rediger';
    case ForumModerer = 'forum.moderer';
    case EvaluationsGerer = 'evaluations.gerer';
    case NotesSaisir = 'notes.saisir';
    // #1650 : annoncer à toute une classe.
    case AnnoncesPublier = 'annonces.publier';

    // ─── Séances et visio
    case SeancesGerer = 'seances.gerer';
    case PresencesDepuisVisio = 'presences.depuis_visio';
    case EnseignementConsulter = 'enseignement.consulter';
    case VisioAnimer = 'visio.animer';
    case VisioEnregistrements = 'visio.enregistrements';
    // #1823 : traiter les justificatifs d'absence (lire, valider, rejeter).
    case AbsencesTraiter = 'absences.traiter';
    // #1682 : l'enseignant lit SES heures ; la gestion, le rapport des heures de l'école.
    case HeuresConsulter = 'heures.consulter';
    case HeuresRapport = 'heures.rapport';

    // ─── L'élève
    case InscriptionRejoindre = 'inscription.rejoindre';
    case SeancesMasquer = 'seances.masquer';
    // #1680 : signer sa présence par le code d'une séance.
    case PresencesEmarger = 'presences.emarger';
    // #1823 : voir ses absences et déposer un justificatif.
    case AbsencesJustifier = 'absences.justifier';
    // #1705 : exporter les données d'un élève, à sa demande (loi 2013-450). Un acte de responsable de traitement :
    // l'ADMINISTRATION de l'école seule, jamais le coordinateur (même ensemble que l'ancien `role:admin,superAdmin`).
    case ElevesExporter = 'eleves.exporter';

    /** Réservée au gestionnaire de la plateforme : jamais à un rôle d'école, ni à un accès individuel. */
    public function estDeLaPlateforme(): bool
    {
        return str_starts_with($this->value, 'plateforme.');
    }
}
