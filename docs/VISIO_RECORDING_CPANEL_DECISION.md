# Decision Visio Recording Sur cPanel

Date: 2026-07-11
Issue: #395

## Decision

La production reste sur un hebergement cPanel mutualise. Le backend LMS ne doit
donc pas dependre d'un serveur Jitsi auto-heberge, de Jibri, de Supervisor, de
workers permanents ou d'un service systeme installe manuellement.

Pour l'enregistrement des seances visio, l'architecture retenue est:

- capture via service externe manage compatible webhook, par exemple JaaS/8x8,
  ou capture navigateur `MediaRecorder` pour un MVP;
- stockage hors disque public local pour les videos lourdes, idealement via le
  disque `s3`/compatible objet deja present dans `config/filesystems.php`;
- traitement asynchrone via la queue `database`, drainee par le scheduler
  cPanel existant;
- rattachement automatique cote backend par resolution:
  `Seance.klassci_matiere_id` -> `Matiere.klassci_id` -> `Lesson` -> `Chapter`
  video;
- exposition d'un etat d'enregistrement et d'une URL prete via endpoints
  dedies, sans reutiliser l'upload generique limite a 30 Mo.

## Pourquoi Pas Jibri Sur Ce Projet

Jibri exige un environnement serveur controle: paquets systeme, services
longue duree, navigateur/headless capture, ressources CPU/RAM previsibles et
configuration Jitsi. Ces prerequis ne sont pas disponibles sur un cPanel
mutualise.

Le backend peut orchestrer les metadonnees, les droits, le webhook, la queue et
le rattachement au cours, mais il ne doit pas porter la capture serveur lourde.

## Consequences Pour Les Sous-Issues

#395 est une decision d'architecture et de faisabilite. L'implementation produit
reste decoupee dans les issues specialisees:

- #398: securiser l'acces salle/JWT avant d'enregistrer quoi que ce soit;
- #399: fiabiliser presence/heartbeat pour garder des bornes de seance propres;
- #410: rattacher automatiquement l'enregistrement a la bonne matiere/lecon;
- #411: exposer start, stop et status;
- #412: traiter `recording-ready` via webhook ou job;
- #413: droits, consentement RGPD, audit et retention;
- #423/#424: traitements asynchrones compatibles cPanel.

## Regles De Mise En Oeuvre

- Ne pas stocker une video de seance sur le disque `public` local par defaut.
- Ne pas augmenter le plafond de `UploadFileRequest` pour contourner le besoin
  d'upload resumable ou de webhook externe.
- Toute nouvelle table ou migration doit etre traitee dans une sous-issue
  dediee, avec tests et runbook de migration.
- Les jobs doivent etre idempotents, courts, et compatibles avec le drain
  `queue:work --stop-when-empty --max-time=55`.
- Les URLs d'enregistrement doivent etre protegees ou signees, jamais devinables.

## Etat Actuel Verifie

- `Seance` contient les IDs KLASSCI et l'etat visio local.
- `Chapter` accepte deja un contenu `video` avec `video_url`.
- `Lesson` relie matiere, classe et enseignant.
- `config/filesystems.php` expose un disque `s3` configurable.
- `UploadFileRequest` reste limite a 30 Mo, ce qui exclut les videos longues.
- Aucun modele d'enregistrement n'est actif dans la branche `lms` au moment de
  cette decision; les anciennes tentatives doivent etre reprises par sous-issue,
  pas restaurees en bloc.
