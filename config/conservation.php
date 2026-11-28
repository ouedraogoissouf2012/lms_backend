<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Durées de conservation des données (#1705, loi ivoirienne n° 2013-450)
|--------------------------------------------------------------------------
|
| LE tableau des durées, par usage. Chaque ligne dit ce que l'on garde, depuis
| quand court le délai, et qui l'applique :
|
|   - `politique` : la clé d'une politique du `RetentionRegistry`, et `cle` la
|     clé de configuration où vit SA durée. Une durée déjà portée par un autre
|     fichier (audit, visio, chapitres) y reste, avec sa variable
|     d'environnement : ce tableau la désigne, il ne la recopie pas.
|   - `suit_le_compte` : la donnée n'a pas de durée propre. Elle vit avec le
|     compte et part avec sa purge (clé étrangère en cascade).
|   - `duree_a_fixer` : l'issue qui fixera une durée encore absente. Déclarée
|     telle dans la note de conformité, jamais tue.
|
| La garde `DureesDeConservationTest` exige que chaque politique du registre y
| figure avec la même durée, et que la note `docs/conformite-loi-2013-450.md`
| reprenne chaque usage. Une durée ne diverge plus en silence.
|
*/

return [

    // Délai de grâce d'un compte, puis d'une école, mis à la corbeille.
    'comptes_supprimes_jours' => (int) env('CONSERVATION_COMPTES_SUPPRIMES_JOURS', 30),
    'ecoles_supprimees_jours' => (int) env('CONSERVATION_ECOLES_SUPPRIMEES_JOURS', 30),

    'usages' => [
        'comptes_supprimes' => [
            'libelle' => 'Comptes supprimés',
            'depuis' => 'la suppression du compte',
            'politique' => 'users',
            'cle' => 'conservation.comptes_supprimes_jours',
        ],
        'ecoles_supprimees' => [
            'libelle' => 'Établissements supprimés',
            'depuis' => 'la suppression de l\'établissement',
            'politique' => 'institutions',
            'cle' => 'conservation.ecoles_supprimees_jours',
        ],
        'journal_audit' => [
            'libelle' => 'Journal d\'audit',
            'depuis' => 'l\'écriture de l\'entrée',
            'politique' => 'audit-logs',
            'cle' => 'audit.retention_days',
        ],
        'enregistrements_visio' => [
            'libelle' => 'Enregistrements des séances en visio',
            'depuis' => 'la fin de l\'enregistrement',
            'politique' => 'recordings',
            'cle' => 'recordings.retention_days',
        ],
        'chapitres_corbeille' => [
            'libelle' => 'Chapitres mis à la corbeille',
            'depuis' => 'la mise à la corbeille',
            'politique' => 'chapters',
            'cle' => 'chapters.retention_days',
        ],
        'preuves_de_consentement' => [
            'libelle' => 'Preuves de consentement',
            'depuis' => 'le recueil du consentement',
            // Append-only (#716) ; leur durée propre reste à fixer. D'ici là, le compte qui en porte une est épargné
            // par la purge des comptes supprimés.
            'duree_a_fixer' => '#1827',
        ],
        'copies_et_notes' => [
            'libelle' => 'Copies et notes d\'évaluation',
            'depuis' => 'la purge du compte de l\'élève',
            'suit_le_compte' => true,
        ],
        'presences' => [
            'libelle' => 'Présences aux séances',
            'depuis' => 'la purge du compte de l\'élève',
            'suit_le_compte' => true,
        ],
        'progression' => [
            'libelle' => 'Progression dans les leçons et les quiz',
            'depuis' => 'la purge du compte de l\'élève',
            'suit_le_compte' => true,
        ],
        'messages_et_fichiers' => [
            'libelle' => 'Messages du forum, notifications et fichiers déposés',
            'depuis' => 'la purge du compte',
            'suit_le_compte' => true,
        ],
    ],

];
