# Requirements — Finalisation d'un enregistrement Jibri vers la formation

> Issue source : **#469** — « feat(visio) : finaliser le parcours Jitsi/Jibri vers la formation ».
> Phase 1/5 du spec-workflow ([CONTRIBUTING.md §A](../../../CONTRIBUTING.md)).
> **Statut : en attente d'approbation utilisateur.**

---

## 1. Contexte mesuré

Le serveur Jitsi/Jibri est en production depuis le 2026-08-31 et **enregistre réellement** :
un enregistrement de référence a produit un `.mp4` h264 1280×720, 1757 images sur 117,1 s
(exactement 15 fps), piste AAC 44,1 kHz avec `mean_volume = −56,4 dB` et `max_volume = −18,5 dB`
— très au-dessus du silence numérique (−91 dB), donc de l'audio réel.

Côté LMS, **toute la moitié réceptrice existe déjà** :

| Pièce | Emplacement | État |
|---|---|---|
| Démarrage / arrêt / consultation | `routes/api/lms.php:174-183` | opérationnel |
| Création de la ligne d'enregistrement | `SeanceRecordingControlService.php:43-47` | opérationnel |
| Webhook signé, horodaté, anti-rejeu | `SeanceRecordingWebhookService.php:88-129` | opérationnel |
| Rattachement en chapitre vidéo | `SeanceRecordingAttachmentResolver.php` | opérationnel |
| Rétention, purge, échec des `Processing` bloqués | `SeanceRecordingRetentionService`, `StaleRecordingFailer` | planifiés |
| Stockage d'une vidéo de chapitre | `ChapterArtifactStorage::storeVideo()` | opérationnel |

### Les trois manques

1. **Personne n'appelle le webhook.** `finalize-script` vaut `/path/to/finalize`, chemin
   inexistant (vérifié dans le conteneur). Aucun code n'émet le webhook.
2. **Le fichier n'a pas d'URL.** `ProcessSeanceRecordingReady` **ne télécharge rien** : il écrit
   l'URL reçue dans `chapters.video_url`. Or le `.mp4` réside dans `/opt/visio/recordings/<uuid>/`
   sur le serveur visio, injoignable en HTTP.
3. **Jibri ignore le `recording_id`.** Il connaît le **salon** (`metadata.json` → `meeting_url`),
   pas l'identifiant interne exigé par le webhook.

### Correction d'une justification erronée

Une version antérieure de ce dossier justifiait le téléversement vers le LMS par « le contrôle
d'accès `ChapterReadGate` s'appliquera ». **C'est faux et cela doit être écrit ici** :
`ChapterArtifactStorage::storeVideo()` dépose délibérément les vidéos sur le **disque public**,
servi sans authentification via `/storage/...`. La classe le documente et l'assume :

> « Les **vidéos** … sont consommées par des balises `<video>` / `<img>`. Les rendre privées
> casserait l'affichage des cours. »
> — `ChapterArtifactStorage.php:36-44`

La vraie raison de faire transiter le fichier par le LMS est **la propriété du cycle de vie**,
développée en R4 : un fichier resté sur le serveur visio échapperait intégralement à la purge.

---

## 2. Requirements

### R1 — Émission du webhook à la fin d'un enregistrement

**WHEN** Jibri termine un enregistrement ayant produit un média,
**THE SYSTEM SHALL** invoquer un script de finalisation qui notifie le LMS.

**IF** le script est invoqué sans répertoire d'enregistrement exploitable en `$1`,
**THE SYSTEM SHALL** journaliser les arguments reçus et sortir en code non nul,
**plutôt que** de deviner le répertoire.

> _Dette assumée et tracée_ : le contrat « `$1` = répertoire d'enregistrement » est celui
> documenté par Jibri, mais il n'a **pas** pu être vérifié empiriquement (le conteneur a été
> redémarré avant qu'une trace d'appel soit capturée, et l'exemple fourni par l'image,
> `finalize_sip.sh`, ne prend aucun argument). L'échec bruyant ci-dessus est la contrepartie :
> la première finalisation réelle tranchera, et elle le dira fort.

### R2 — Le média devient un chapitre de la formation

**WHEN** le LMS reçoit une notification de finalisation valide,
**THE SYSTEM SHALL** rattacher l'enregistrement à la leçon de la séance sous forme de chapitre
vidéo, en réutilisant `ProcessSeanceRecordingReady` **sans le modifier**.

**WHERE** la séance ne permet pas de résoudre une leçon unique
(`missing_klassci_matiere_id`, `matiere_not_found`, `lesson_not_found`, `ambiguous_lesson`),
**THE SYSTEM SHALL** conserver le comportement d'échec existant et **ne pas** créer de chapitre.

### R3 — Résolution du salon vers l'enregistrement

**WHEN** la notification identifie l'enregistrement par le **salon** plutôt que par
`recording_id`,
**THE SYSTEM SHALL** résoudre côté serveur l'enregistrement actif de la séance dont
`visio_room_id` correspond.

**IF** aucun enregistrement actif ne correspond au salon,
**THE SYSTEM SHALL** répondre 404 sans créer de ligne.

**IF** plusieurs enregistrements actifs correspondent,
**THE SYSTEM SHALL** refuser plutôt que d'en choisir un.

> _Justification_ : un fournisseur d'enregistrement connaît le salon, jamais un identifiant
> interne au LMS. Faire porter la résolution au serveur évite un aller-retour HTTP
> supplémentaire et n'ouvre aucune nouvelle surface d'authentification.

### R4 — Le LMS possède le média et son effacement

**WHEN** un enregistrement est finalisé,
**THE SYSTEM SHALL** transférer le fichier vers le stockage du LMS via
`ChapterArtifactStorage::storeVideo()`.

**WHEN** le chapitre correspondant est supprimé ou purgé,
**THE SYSTEM SHALL** effacer le média, `ChapterArtifactStorage::purgeChapter()` couvrant déjà
les deux disques.

> _Justification — la seule qui tienne_ : `SeanceRecordingRetentionService` et
> `purgeChapter()` ne savent effacer que ce que le LMS détient. Un `.mp4` laissé sur le serveur
> visio survivrait à toute purge, y compris une demande d'effacement. Le plan de conformité du
> projet est explicite là-dessus : un effacement qui laisse la donnée lisible
> **n'est pas un effacement** (EDPB, 10 février 2026). Héberger ailleurs créerait exactement
> cette situation.

**WHERE** le transfert échoue,
**THE SYSTEM SHALL** laisser l'enregistrement en échec explicite avec un motif, jamais en
`Ready`.

### R5 — Authentification, sans nouvelle surface

**THE SYSTEM SHALL** authentifier la notification par le HMAC existant :
`HMAC-SHA256(timestamp + "\n" + nonce + "\n" + corps_brut, secret)`,
en-têtes `X-Visio-Signature`, `X-Visio-Timestamp`, `X-Visio-Nonce`.

**IF** le secret `services.visio.webhook_secret` est absent,
**THE SYSTEM SHALL** répondre 503 et **ne rien traiter**.

**IF** l'horodatage sort de la fenêtre `services.visio.webhook_max_age` (300 s par défaut),
**OR IF** le nonce a déjà été vu,
**THE SYSTEM SHALL** refuser (401 / 409).

**THE SYSTEM SHALL NOT** introduire de point d'entrée non authentifié, ni exposer
`/opt/visio/recordings` en HTTP.

### R6 — Robustesse face aux pannes

**IF** le LMS est injoignable au moment de la finalisation,
**THE SYSTEM SHALL** réessayer un nombre borné de fois avec attente croissante,
**et** conserver le fichier local, **jamais** le supprimer avant accusé de réception.

**WHILE** une finalisation est en échec répété,
**THE SYSTEM SHALL** laisser une trace exploitable côté Jibri (journal daté, code de sortie),
de sorte qu'un opérateur puisse rejouer la notification.

**IF** la même notification est reçue deux fois,
**THE SYSTEM SHALL** rester idempotent : `ProcessSeanceRecordingReady` sort déjà sans effet
si le statut est `Ready` (`ProcessSeanceRecordingReady.php:48`).

### R7 — Aucune régression du contrat existant

**THE SYSTEM SHALL** continuer d'accepter une notification portant `recording_id` + `url`,
telle que la vérifient les tests actuels
(`tests/Feature/LMS/Visio/VisioRecordingWebhookTest.php`).

**THE SYSTEM SHALL** préserver les statuts existants : 202 accepté, 401 signature,
409 rejeu, 404 introuvable, 422 charge invalide, 503 non configuré.

### R8 — Configuration déclarée

**THE SYSTEM SHALL** documenter dans `.env.example` toutes les variables visio requises.

> _Constat_ : `.env.example` ne contient **aucune** variable `VISIO_*` ni `JITSI_*` aujourd'hui,
> alors que `config/services.php` en déclare huit. Un déploiement neuf part donc avec un
> `webhook_secret` vide → 503 permanent, sans indice.

---

## 3. Hors périmètre — explicitement

| Exclu | Pourquoi |
|---|---|
| Déclencher Jibri depuis le LMS (API HTTP directe) | jicofo gère déjà le pool de Jibri via la brasserie. Piloter en direct obligerait à réimplémenter cette sélection. |
| Rendre privées les vidéos de chapitre | Décision délibérée de #598, hors sujet ici. À rouvrir séparément si la conformité l'exige. |
| URLs de diapositives prédictibles | Dette pré-existante déjà tracée dans `.claude/specs/598-chapter-artifacts-private/design.md`. |
| Second Jibri / capacité à 5 simultanés | Demande une mesure sur contenu réel (diapositives + webcam). La mesure faite portait sur un écran quasi statique : elle donne un plancher, pas une capacité. |
| Consentement à l'enregistrement | Sujet à part entière (table `consents` append-only du plan d'autonomie). |

---

## 4. Critères d'acceptation

1. Un enregistrement réel lancé depuis l'interface produit un chapitre vidéo lisible dans la
   leçon de la séance, **sans intervention manuelle**.
2. Le `.mp4` est présent sur le disque du LMS et absent après purge du chapitre.
3. Une notification rejouée ne crée pas de second chapitre.
4. Une notification non signée est refusée en 401.
5. Les tests existants de `VisioRecordingWebhookTest` passent **sans modification**.
6. Une finalisation dont le LMS est injoignable laisse le fichier intact et une trace lisible.
