# Tasks — Finalisation d'un enregistrement Jibri vers la formation

> Issue **#469**. Phase 3/5 du spec-workflow. **En attente d'approbation.**
> [requirements.md](requirements.md) approuvés · [design.md](design.md) approuvé.

**Décision de revue (thermo-nuclear)** : R3 reste **strict** (404 si aucun enregistrement actif).
La création implicite par webhook a été envisagée puis **rejetée** — elle contournerait
`canControl()`, effacerait l'acteur de la piste d'audit, et masquerait le défaut F1. Contrepartie
retenue : un événement orphelin distinct et alertable (tâche 6.2), le fichier n'étant jamais
supprimé.

**Ordre imposé** : les tests précèdent l'implémentation (TDD, §1.3). Chaque tâche est livrable et
vérifiable seule.

---

## 1. Fondations — configuration et schéma

- [x] **1.1** Déclarer `services.visio.recordings_root` dans `config/services.php`
  (env `VISIO_RECORDINGS_ROOT`, sans défaut : absent ⇒ la voie Jibri est inactive, jamais un
  chemin deviné). _Requirements: R4, R8_
- [x] **1.2** Documenter **toutes** les variables visio manquantes.
  **Cible corrigée** : `.env.example` **n'existe pas** dans ce dépôt — aucun fichier `.env*`
  n'est versionné (`git ls-files`). Le foyer canonique est **`docs/ENV_VARIABLES.md`**, dont la
  §« Procédure pour ajouter une nouvelle variable » impose littéralement « ajouter une ligne à ce
  doc dans la PR ». Créer un `.env.example` aurait dupliqué cette source. Ajout d'une section 6
  (accès aux salles / finalisation), 9 variables, avec obligatoire-ou-défaut pour chacune.
  _Requirements: R8_
- [x] **1.3** Migration : index sur `seances.visio_room_id`. La colonne est interrogée à chaque
  finalisation et n'a aucun index (constat F3). **Index simple et non composite tenant-leading** :
  la requête du webhook n'a pas de tenant par construction (HMAC, pas de jeton porteur) — un index
  préfixé `institution_id` ne serait jamais utilisé par la seule requête qu'il doit servir.
  Justifié dans le fichier de migration. _Requirements: R3_

> **Vérification groupe 1** : `VisioRecordingWebhookTest` — 4/4 verts, sans modification du
> fichier de test (tâche 6.4 partiellement satisfaite d'avance). La migration s'exécute via
> `RefreshDatabase`.

## 2. Localisation du média — la frontière réversible

- [x] **2.1** Test unitaire `LocalDirectoryRecordingMediaSourceTest` — **16 cas, 16 verts**.
  9 identifiants hostiles couverts (traversée relative et encodée, chemins absolus POSIX et
  Windows, séparateur injecté, octet nul, majuscules, vide, joker). Le cas « jamais d'accès
  disque » utilise un double qui **échoue s'il est seulement interrogé** — c'est une preuve, pas
  une affirmation. Deux cas ajoutés hors énoncé : 0 média → `null`, **2 médias → `null`** (refuser
  plutôt qu'attacher le mauvais enregistrement à un cours). _Requirements: R4, R5_
- [x] **2.2** Interface `RecordingMediaSource` + `LocalDirectoryRecordingMediaSource`.
  Validation du format **avant** toute concaténation. Liaison dans `AppServiceProvider` via une
  méthode privée dédiée, sur le patron de `bindVisioAccessTokenIssuer()` (le garde-fou de taille
  de méthode refuse qu'un `register()` déjà long s'allonge à chaque binding).
  **`bind()` et non `singleton()`** : vérifié qu'un changement de `config()` produit bien une
  nouvelle instance — un singleton figerait la racine au premier appel et fausserait les tests.
  _Requirements: R4_

> **Vérification groupe 2** : 16/16 · liaison résolue en `LocalDirectoryRecordingMediaSource` ·
> racine absente ⇒ `locate()` renvoie `null` (voie Jibri éteinte, comme spécifié en 1.1).
> Note : le dépôt utilise `@dataProvider` (5 fichiers) et jamais `#[DataProvider]` — convention
> suivie, malgré l'avertissement de dépréciation PHPUnit 12, qui concerne tout le dépôt.

## 3. Stockage et effacement du média

- [x] **3.1** Test unitaire `RecordingMediaStorageTest` — **9/9**. A trouvé un défaut réel :
  `url()` renvoyait une URL **relative** sous `Storage::fake()` (`/storage/…` au lieu de
  `http://…/storage/…`), le fake reconstruisant le disque sans sa clé `url`. Ce n'est pas un
  artefact de test : cette valeur est **persistée dans `chapters.video_url`** et servie à un front
  hébergé sur une **autre origine** — une URL relative y pointerait vers le domaine du front, et
  le défaut serait figé en base. `url()` force désormais l'absolu. _Requirements: R4_
- [x] **3.2** `RecordingMediaStorage`. `writeStream()` et non `put()` (un cours d'une heure pèse
  des centaines de Mo, qu'on ne charge pas en mémoire) ; `is_readable()` et non `file_exists()`
  (un fichier présent mais illisible produirait un média de **0 octet stocké sans erreur**) ;
  nom de fichier aléatoire, non dérivé de l'identifiant. _Requirements: R4_
- [x] **3.3** Test `SeanceRecordingMediaPurgeTest` — écrit **rouge d'abord** : 2 échecs,
  `Found unexpected file … after purge`. Défaut du design §1 reproduit. _Requirements: R4_
- [x] **3.4** `RecordingMediaStorage::purge()` branché dans `SeanceRecordingRetentionService`
  (injection au constructeur). Effacement **dans la transaction, avant les lignes** — arbitrage
  documenté dans la méthode : une suppression de fichier n'étant pas transactionnelle, le résidu
  acceptable est « média effacé, métadonnées en trop » (visible, réparable), jamais « métadonnées
  perdues, vidéo encore servie », qui *documenterait* une suppression n'ayant pas eu lieu.
  Balayage `new SeanceRecordingRetentionService(` : **aucune instanciation directe**, le conteneur
  injecte partout. 90 → ~118 lignes (< 300 ✓). _Requirements: R4_

> **Vérification groupe 3** : 4 nouveaux tests verts **+ les 5 tests existants de
> `PurgeSeanceRecordingsCommandTest` toujours verts** — dont « chapter from another tenant is
> never deleted ». Aucune régression sur la purge.

## 4. Résolution salon → enregistrement

- [ ] **4.1** Test unitaire `RoomRecordingResolverTest` avec **deux institutions** : salon connu +
  enregistrement actif → trouvé ; salon inconnu → `null` ; enregistrement non actif → `null` ;
  deux actifs → `null` (jamais un choix arbitraire) ; **le salon d'un tenant ne résout jamais vers
  l'autre**. _Requirements: R3_
- [ ] **4.2** `RoomRecordingResolver::resolve(string $room): ?SeanceRecording`. Jointure
  `seance_recordings` × `seances` sur `visio_room_id`, statut dans
  `SeanceRecordingStatus::activeValues()`, `withoutGlobalScope('institution')` — cohérent avec le
  service existant, qui n'a pas de tenant résolu sur cette route. _Requirements: R3_

## 5. Import du média

- [ ] **5.1** Test `ImportJibriRecordingMediaTest` : chemin nominal → `ProcessSeanceRecordingReady`
  mis en file `low` ; média introuvable → statut `Failed` + motif `media_not_found` ;
  copie en échec → `Failed` + `media_copy_failed` ; enregistrement déjà `Ready` → **aucun effet**
  (idempotence) ; le double en mémoire de `RecordingMediaSource` prouve que le job ne touche pas
  le disque. _Requirements: R2, R4, R6_
- [ ] **5.2** Job `ImportJibriRecordingMedia` (file `low`) : localise, stocke, renseigne
  `provider='jibri'`, `provider_recording_id`, `file_size_bytes`, puis dispatche
  `ProcessSeanceRecordingReady` **inchangé**. Aucun `getMessage()` vers le client (§1.2).
  _Requirements: R2, R4, R6_

## 6. Webhook — la nouvelle voie

- [ ] **6.1** Test `VisioRecordingWebhookRoomPathTest` : `{room, session}` signé → **202** + job
  en file ; salon inconnu → **404** ; `session` malformé → **422** ; non signé → **401** ;
  nonce rejoué → **409** ; secret absent → **503**. _Requirements: R3, R5, R7_
- [ ] **6.2** Test : une notification dont le salon existe **sans enregistrement actif** journalise
  `recording_orphan_no_active_session` avec salon et session — la contrepartie du 404 strict.
  _Requirements: R6_
- [ ] **6.3** Brancher la voie `room` + `session` dans `SeanceRecordingWebhookService` :
  `recording_id` présent ⇒ voie historique **intacte** ; sinon `room` + `session`. Injection de
  `RoomRecordingResolver`. Attendu : 142 → ~185 lignes (< 300 ✓). _Requirements: R3, R7_
- [ ] **6.4** **Non-régression** : exécuter `VisioRecordingWebhookTest` **sans le modifier**.
  Toute modification de ce fichier signale une régression du contrat, pas un test à corriger.
  _Requirements: R7_

## 7. Script de finalisation — côté serveur visio

- [ ] **7.1** `ops/visio/finalize-recording.sh` versionné dans le dépôt (le déploiement le monte
  dans `/config`) : journalise `$@` daté **avant tout** ; sort en 1 si `$1` n'est pas un
  répertoire lisible ; lit `metadata.json` → salon ; signe en HMAC-SHA256 ; 3 tentatives
  (5 s / 15 s / 45 s) ; **ne supprime jamais** le fichier. _Requirements: R1, R6_
- [ ] **7.2** `docs/VISIO_JIBRI_FINALIZE.md` : variables à poser, montage du script,
  `JIBRI_FINALIZE_RECORDING_SCRIPT_PATH`, et la procédure de **rejeu manuel** d'une finalisation
  orpheline (contrepartie de 6.2). _Requirements: R1, R6_

## 8. Vérification finale

- [ ] **8.1** Gardes de taille : `check-file-sizes.php` et `check-method-sizes.php` sur les
  fichiers modifiés, avec **l'invocation exacte de la CI** (liste de fichiers changés, pas `app/`).
- [ ] **8.2** PHPStan niveau 9 : aucune entrée ajoutée à la baseline. Une violation se corrige à
  la source.
- [ ] **8.3** Suite ciblée : `tests/Feature/LMS/Visio/`, `tests/Unit/Services/Visio/`,
  et les tests de rétention. La suite complète dépasse une heure — la CI tranche.
- [ ] **8.4** Issues de suivi à ouvrir, **avant** la PR :
  - **F1** — le bouton « Démarrer l'enregistrement » du LMS ne pilote pas Jibri (`window.open`
    interdit l'API de pilotage). **C'est cette issue qui ferme réellement le parcours #469.**
  - Supprimer un chapitre laisse ses fichiers sur disque : `purgeChapter()` n'est appelé que
    depuis le pipeline de conversion, aucun observer sur `Chapter` (design §1).

---

## Ce que ces tâches ne font pas

Reporté et tracé, jamais silencieux : pilotage de Jibri depuis le LMS · vidéos de chapitre privées
(#598) · orphelinage général des fichiers de chapitre · mesure de capacité à 5 enregistrements
simultanés · consentement explicite par participant.
