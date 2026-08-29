# Requirements — Restreindre les leçons d'un étudiant à sa classe (#482)

## Contexte & preuves

`LessonListService::myCourses()` (`app/Services/Lesson/LessonListService.php:107-140`)
et `LessonListService::list()` (`:44-92`) renvoient à un étudiant **toutes** les
leçons publiées du tenant. Le filtre par classe n'est appliqué que si `classe_id`
est **explicitement** passé en query string — un étudiant peut donc voir les
leçons de **toutes les classes / filières** de son institution.

**Faille** : fuite de contenu inter-classes. Latente tant que #481 (leçons
invisibles) n'est pas corrigée, mais **active dès** que des leçons auront un
`published_at` valide. Correctif de **sécurité** → doit précéder #481.

### Modèle de données (vérifié)

- Classe de l'étudiant : `UserClass.klassci_classe_id` = **ID KLASSCI**
  (`app/Models/UserClass.php`, alimenté au login par `StudentClassSynchronizer`).
  Le pivot `classe_etudiant` existe mais **n'est jamais écrit** en runtime → non
  utilisé.
- Classe d'une leçon : `lessons.classe_id` = **ID LOCAL** (`classes.id`,
  `belongsTo Classe`), **nullable**.
- Les deux clés **ne se joignent pas directement** : pont via
  `classes` (`UserClass.klassci_classe_id → classes.klassci_id → classes.id`).
- Matières de l'étudiant : **non stockées** localement (KLASSCI `me/dashboard`)
  → hors périmètre (décision : filtrage par classe seule).

## Décisions métier (arrêtées avec le produit)

- **D1** : filtrage par la **classe** de l'étudiant **seule** (100 % local, aucun
  appel KLASSCI live).
- **D2** : les leçons `classe_id = NULL` sont **exclues** pour l'étudiant
  (moindre privilège).
- **D3** : le fix couvre **`myCourses()` ET `list()`** (les deux endpoints
  étudiants), via un **helper partagé**.

## Portée

- **IN** : filtrage classe étudiant dans `myCourses()` et `list()` ; helper de
  résolution des `classes.id` locaux d'un étudiant.
- **OUT** : les rôles non-étudiants (enseignant/coordinateur/admin) — comportement
  **inchangé** (voient selon leurs propres règles existantes).
- **OUT** : filtrage par matières (décision D1) ; pagination et `MyCoursesRequest`
  (#483) ; données `published_at` (#481).

## Exigences (EARS)

**REQ-1 — Restriction classe pour l'étudiant (myCourses)**
WHEN un utilisateur **étudiant** appelle `myCourses()`, THE SYSTEM SHALL ne
retourner que les leçons dont `classe_id` ∈ {ids locaux des classes de
l'étudiant}, en plus des filtres existants (tenant, publié).

**REQ-2 — Restriction classe pour l'étudiant (list)**
WHEN un utilisateur **étudiant** appelle `list()`, THE SYSTEM SHALL appliquer la
**même** restriction classe que REQ-1.

**REQ-3 — Exclusion des leçons sans classe**
WHERE `classe_id` d'une leçon est `NULL`, THE SYSTEM SHALL l'exclure du résultat
d'un étudiant (conséquence directe d'un `whereIn('classe_id', …)`).

**REQ-4 — Étudiant sans classe**
IF un étudiant n'a **aucune** `UserClass` (aucune classe résolue), THEN THE
SYSTEM SHALL retourner une liste **vide** (fail-secure : pas de classe → aucune
leçon), sans exception.

**REQ-5 — Non-régression des rôles non-étudiants**
THE SYSTEM SHALL ne PAS appliquer la restriction classe aux rôles
enseignant/coordinateur/admin : leur résultat est **identique** à l'actuel.

**REQ-6 — Isolation tenant préservée**
THE SYSTEM SHALL conserver le filtre tenant explicite existant : la résolution
des classes de l'étudiant SHALL être bornée à `institution_id` de l'étudiant.

**REQ-7 — DI strict, pas de god-method**
THE SYSTEM SHALL implémenter la résolution des classes locales dans un
collaborateur/méthode dédié réutilisable (DRY entre les 2 endpoints), sans
Facade ni `app()` dans la logique métier (§1.6 D), méthodes ≤40 lignes.

## Critères d'acceptation (mesurables)

1. Un étudiant de la classe A (locale id 1) ne voit **aucune** leçon de la
   classe B (locale id 2) via `myCourses()` **ni** via `list()`.
2. Une leçon `classe_id = NULL` n'apparaît **jamais** pour un étudiant.
3. Un étudiant sans `UserClass` reçoit une liste **vide** (200, pas d'erreur).
4. Un enseignant/coordinateur/admin voit **exactement** le même ensemble
   qu'avant le fix (test de non-régression).
5. Le pont KLASSCI→local est correct : une leçon rattachée à `classes.id` dont
   le `klassci_id` correspond à la `UserClass` de l'étudiant **est** visible.
6. `php artisan test` = 100 % ; PHPStan level 9 vert ; garde tailles OK.

## Q15 — Critères d'invalidation

- ❌ Joindre `lessons.classe_id` (local) directement à
  `UserClass.klassci_classe_id` (KLASSCI) SANS passer par `classes` → 0 résultat
  ou faux positifs.
- ❌ Restriction appliquée aussi aux enseignants/coordinateurs/admins (régression
  fonctionnelle).
- ❌ Étudiant sans classe → exception (au lieu de liste vide).
- ❌ Fuite persistante sur `list()` parce que seul `myCourses()` a été corrigé.
- ❌ Requête N+1 pour résoudre les classes (doit être 1 requête bornée).
- ❌ Perte du filtre tenant (résolution de classes cross-tenant).
