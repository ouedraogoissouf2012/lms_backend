# ADR-846-01 — Une séance locale connaît sa classe

**Date :** 2026-09-19  
**Statut :** accepté  
**Issue :** #846 (lot D) · corrige une conséquence d'[ADR-803-03](2026-09-15-803-03-trois-portes-un-service.md)

## Décision

`seances` reçoit une colonne **`classe_id`** locale, nullable, vers `classes`. `LocalSeanceCreator` la renseigne ; la synchronisation KLASSCI ne la touche pas.

La porte étudiant de `SeanceRecordingAccessService` interroge alors **`CompositeEnrollmentSource`**, par **une seule comparaison** :

1. la classe **locale** de la séance — directement `classe_id`, ou, pour une séance miroitée, la classe locale dont le `klassci_id` vaut `klassci_classe_id` ;
2. confrontée aux classes de l'apprenant rendues par le composite.

**Une seule voie et non deux**, et c'est une mesure qui l'a décidé : les deux jambes du composite rendent le **même** espace d'identifiants. `KlassciEnrollmentSource::localClasseIdsFor` lit `user_classes.klassci_classe_id` puis **traduit** en `classes.id` (lignes 32-36). Maintenir une comparaison KLASSCI parallèle dupliquerait cette traduction sans rien couvrir de plus.

`creneaux` n'est pas retenue comme porteuse de la séance locale.

## Pourquoi

### La prescription d'ADR-803-03 est insuffisante — mesuré

Cet ADR conclut : « `SeanceRecordingAccessService` est routé par `CompositeEnrollmentSource` ». **Cela ne corrigerait rien**, et la chaîne est vérifiable :

- `canRead:25` court-circuite la porte étudiant quand `klassci_classe_id` est nul ;
- `LocalSeanceCreator:32` écrit précisément `'klassci_classe_id' => null`. Une séance locale ne garde qu'un `classe_nom`, du **texte libre** ;
- l'import est réservé aux écoles qui tiennent leur liste (`ImportPreviewRequest:48`, `ImportConfirmController:31`), donc l'inscription locale n'existe que dans une école autonome — dont les séances sont locales.

| | inscription | séance | porte étudiant |
|---|---|---|---|
| école **autonome** | `classe_etudiant` | locale, `klassci_classe_id` **nul** | **jamais atteinte** |
| école **KLASSCI** | `user_classes` | KLASSCI | fonctionne déjà |

Le composite rend des identifiants de classes **locales** ; une séance locale n'en porte aucun à comparer. Le manque n'est pas la lecture d'inscription, c'est **le lien séance ↔ classe**.

### Pourquoi `seances` et pas `creneaux`

`creneaux` a été créée par #800 pour le monde autonome, avec `training_session_id`, `starts_at`, `salle_virtuelle`. Elle a un modèle. Elle n'a **aucun service** : mesure du 2026-09-19, `grep` sur `app/Services/` → **0 fichier**.

En regard, **14 fichiers de service visio** dépendent de `Seance` — activation, session live, enregistrement, présences, notifications. Et `LocalSeanceCreator` est déjà câblé à une route vivante, `POST /seances` via `LMSSeanceCrudController`.

Porter la séance locale par `creneaux` signifierait réécrire ces quatorze fichiers pour un objet qui ne rend aujourd'hui aucun service. Le coût est réel, le bénéfice nul : les deux tables décrivent le même fait.

**Dette explicitement tracée :** `creneaux` reste une table sans code. Cet ADR ne la consomme pas et ne la justifie pas non plus — son sort appelle une décision distincte, qu'il ne prend pas.

### Pourquoi la Classe, et pas la période

[ADR-711-05](2026-09-06-711-05-classe-reference.md), accepté : « La Classe **porte inscription**, progression, notes, présences. » La question posée par `canRead` est exactement une question d'inscription — *cet apprenant est-il de cette classe ?*

Passer par `training_session_id` ajouterait un saut — séance → période → classe → inscription — pour répondre à une question que la Classe porte directement. Et `classes.training_session_id` existe déjà (#827) : la période reste atteignable depuis la classe quand on en aura besoin.

### La porte étudiant n'a AUCUN test, et c'est le vrai risque

Mesure du 2026-09-19 : `studentBelongsToSeanceClass` n'apparaît **que dans le service lui-même**. Aucun test, nulle part — **y compris pour le chemin KLASSCI qui fonctionne en production**.

Réécrire un chemin non testé est l'occasion de le casser en silence. Les tests de ce lot doivent donc couvrir **les deux** : le chemin KLASSCI en non-régression, le chemin local en correction. C'est une conséquence de l'ADR, pas un détail d'implémentation.

### Nullable, et jamais renseignée par la synchronisation

Une séance miroitée de KLASSCI n'a pas de classe locale à désigner — sa classe est identifiée par `klassci_classe_id`, et la classe miroir correspondante peut ne pas être encore synchronisée. Rendre la colonne obligatoire ferait échouer la synchronisation.

Deux voies cohabitent donc, sans conditionnelle de mode : chaque séance porte le lien de sa propre origine, et la porte étudiant essaie les deux.

## Ce que cela corrige, et ce que cela ne corrige pas

**Corrigé** : un apprenant d'école autonome, inscrit dans la classe, accède à l'enregistrement d'une séance **qu'il a manquée**. C'est la relecture asynchrone que `docs/PLAN_AUTONOMIE_KLASSCI.md:68` désigne comme différenciateur produit.

**Déjà ouvert aujourd'hui, et qui le reste** : l'apprenant qui a **assisté** au cours passe par la troisième porte, les présences — `esbtp_attendance.klassci_etudiant_id` est nullable, donc une présence locale s'y écrit. Cette porte n'est pas touchée.

**Non corrigé** : les séances locales **déjà créées** portent `classe_id` nul et le resteront. Elles ne connaissent leur classe que par `classe_nom`, du texte libre qu'aucune reprise ne peut apparier de façon sûre — deux classes peuvent porter le même nom. Les rattacher serait une décision de données, pas de schéma.

## Conséquences

- Une migration ajoutant `classe_id` nullable à `seances`, avec index `(institution_id, classe_id)`.
- `LocalSeanceCreator` accepte et vérifie une classe **de l'établissement** — même borne explicite que `LocalClasseMatiereLinker`, le scope global étant fail-open.
- `SeanceRecordingAccessService::studentBelongsToSeanceClass` cesse de lire `UserClass` en direct et passe par `CompositeEnrollmentSource`, satisfaisant enfin la règle d'ADR-803-03 : « Aucune lecture d'inscription ne contourne `CompositeEnrollmentSource`. »
- `canRead` n'a plus à court-circuiter sur `klassci_classe_id !== null`.
- Aucune migration sur `classe_etudiant` ni sur `user_classes`.

## Vérifié

`origin/lms` @ `a84a93f4`, schéma lu directement en base, le 2026-09-19.
