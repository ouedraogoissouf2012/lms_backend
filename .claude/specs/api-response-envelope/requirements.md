# Requirements — Unification des enveloppes de réponse JSON de l'API

> Spec-Driven Workflow — Phase 1 (Requirements). Format EARS (WHEN / IF / WHERE / WHILE + SHALL).
> Réf. process : [CONTRIBUTING.md §A](../../../CONTRIBUTING.md). Réf. qualité : [PRODUCTION_STANDARDS.md](../../../PRODUCTION_STANDARDS.md) §1.5, §1.6, §5.

## 1. Contexte (faits mesurés)

- Le champ `'success'` apparaît **197 fois**, `'message'` **143**, `'data'` **96**, `'meta'` 6, `'errors'` 1, répartis sur **48 controllers** sous `app/Http/Controllers`.
- **85** réponses d'erreur (`'success' => false`) sont écrites inline dans les controllers.
- `app/Http/Controllers/Controller.php` est **vide** : aucun helper de réponse partagé.
- `app/Http/Resources/` et `app/Policies/` **n'existent pas** (0 fichier).
- La forme canonique existe déjà dans le code mais n'est **pas centralisée** :
  - `app/Http/Presenters/AuthResponsePresenter.php` → `{success, message, data, meta}`.
  - `app/Services/Quiz/Concerns/BuildsAttemptResponses::failure()` → `{status, success, message, data, errors}`.
- **Incohérence constatée** : 197 `success` mais seulement 96 `data` → certains endpoints mettent le payload sous `data`, d'autres à la racine. Un client doit deviner la forme endpoint par endpoint (violation [§1.5](../../../PRODUCTION_STANDARDS.md#L52) : « Format de réponse JSON identique pour tous les endpoints »).

**Problème racine** : l'absence d'un point unique de construction des réponses JSON ⇒ duplication (193 enveloppes manuelles) **et** divergence du contrat d'API.

## 2. Glossaire

| Terme | Définition |
|---|---|
| **Enveloppe** | La structure JSON externe d'une réponse API : `success`, `message`, `data`, `meta`, `errors`. |
| **Trait de réponse** | `RespondsWithJson` — trait monté sur le `Controller` de base exposant les fabriques d'enveloppes. |
| **Contrat canonique** | La forme de référence, dérivée de `AuthResponsePresenter`, que tous les endpoints doivent respecter. |
| **Test de caractérisation** | Test Feature qui fige la forme JSON actuelle d'un endpoint *avant* sa migration, pour garantir l'absence de régression. |

## 3. Exigences fonctionnelles (EARS)

### R1 — Trait central de réponse
- **R1.1** — WHEN un controller doit produire une réponse de succès, il SHALL le faire via une méthode `successResponse()` fournie par le trait `RespondsWithJson` monté sur `app/Http/Controllers/Controller.php`.
- **R1.2** — WHEN un controller doit produire une réponse d'erreur, il SHALL le faire via une méthode `errorResponse()` du même trait.
- **R1.3** — WHERE le trait est utilisé, il SHALL retourner un `Illuminate\Http\JsonResponse` (jamais un array brut), cohérent avec `AuthResponsePresenter`.

### R2 — Contrat d'enveloppe (préservation / DRY-only) — _amendé Phase 4_
> **Amendement (décision utilisateur, Phase 4)** : le code réel montre que les controllers émettent des formes hétérogènes (`{success, data}` sans message, `{success, message}` sans data, `{success, message, data}`). L'hypothèse initiale R2.4 (forme universelle d'`AuthResponsePresenter`) était **fausse**. Objectif retenu : **DRY sans changer le JSON** → chaque clé optionnelle est OMISE quand absente. L'uniformisation (toujours les 3 clés) est un chantier distinct (coordination frontend).

- **R2.1** — L'enveloppe de succès SHALL être `{ "success": true, "message"?: string, "data"?: mixed, "meta"?: object }` : `message` **omis** si `''`, `data` **omis** si `null`, `meta` **omis** si vide. Ainsi `successResponse($d)` reproduit `{success, data}` et `successResponse(null, $m)` reproduit `{success, message}`.
- **R2.2** — L'enveloppe d'erreur SHALL être `{ "success": false, "message": string, "errors"?: object }`, `errors` **omis** si vide, + **code HTTP** explicite (défaut succès 200, erreur 400).
- **R2.3** — Le trait SHALL être capable de reproduire **à l'identique** chacune des 3 formes existantes, de sorte qu'une migration ne modifie aucune clé visible par le client (cf. R3).

### R3 — Non-régression du contrat d'API (le plus critique)
- **R3.1** — WHERE un endpoint existant est ultérieurement migré vers le trait, la forme JSON de sa réponse SHALL rester identique sur tous les champs déjà exposés (aucun ajout/retrait/renommage de clé visible par le client).
- **R3.2** — IF une réponse existante place son payload à la racine (sans clé `data`), THEN sa migration SHALL préserver cette forme tant qu'un changement de contrat n'est pas explicitement décidé et documenté (la convergence vers `data` est hors périmètre de cette spec).

### R4 — Sécurité des erreurs
- **R4.1** — WHEN `errorResponse()` construit une réponse, elle SHALL NE JAMAIS inclure `$e->getMessage()` ni aucun détail d'exception technique ([§1.2](../../../PRODUCTION_STANDARDS.md#L36)). Seuls un message métier et des `errors` structurés (ex. erreurs de validation) sont autorisés.

### R5 — Couverture de tests du trait
- **R5.1** — Le trait `RespondsWithJson` SHALL être couvert par des tests unitaires : succès avec `data`, succès sans `data` (R2.3), succès avec `meta`, erreur simple, erreur avec `errors`, code HTTP personnalisé. (Happy path + edge cases — [§1.3](../../../PRODUCTION_STANDARDS.md#L42).)

### R6 — Périmètre de livraison PR-0
- **R6.1** — La première livraison (PR-0) SHALL contenir **uniquement** le trait, son branchement sur le `Controller` de base, et ses tests unitaires.
- **R6.2** — PR-0 SHALL NE migrer **aucun** controller existant, **sauf** la résolution de l'unique collision de nom forcée par le trait : `ProxyDashboardController` déclare un `private function errorResponse()` qui, une fois `errorResponse()` hérité du trait via le `Controller` de base, provoque un *fatal* PHP (réduction de visibilité `protected`→`private`). Cette collision SHALL être résolue par **suppression du doublon** (le helper privé est identique au trait ; ses 4 appels passent tous un status explicite → **zéro changement de comportement**). C'est le minimum requis pour que le build ne casse pas, pas une migration de réponses.
  > _Amendement post-Design (découverte en Phase 4) : la collision n'avait pas été anticipée. Validée par l'utilisateur._

### R7 — Migration test-first (PRs ultérieures, cadrée ici)
- **R7.1** — IF un controller à migrer ne possède pas de test Feature couvrant la forme de sa réponse, THEN un test de caractérisation SHALL être écrit et vert **avant** toute modification de ce controller.
- **R7.2** — WHEN un controller est migré, `php artisan test` SHALL rester à **100 %** ([§1.3](../../../PRODUCTION_STANDARDS.md#L42)).

## 4. Exigences non-fonctionnelles

- **NF1 — Taille** : le trait SHALL respecter [§1.1](../../../PRODUCTION_STANDARDS.md#L31) (≤ 300 lignes) ; le `Controller` de base SHALL rester trivial.
- **NF2 — SOLID/D** : le trait n'introduit aucune dépendance instanciée par `new` ni Facade en logique métier. L'usage du helper `response()` (couche présentation) est aligné sur le précédent `AuthResponsePresenter` qui utilise déjà `response()->json()`.
- **NF3 — Rétrocompatibilité** : `AuthResponsePresenter` et `BuildsAttemptResponses` ne SHALL PAS être modifiés (déjà conformes). Le trait reproduit leur contrat ; un alignement cosmétique est hors périmètre.
- **NF4 — Lisibilité** : signatures auto-documentées, PHPDoc expliquant le POURQUOI (issue + audit), comme le reste du domaine Klassci/Quiz.

## 5. Hors périmètre (dette déclarée, non masquée)

1. **API Resources** (`app/Http/Resources`) pour le mapping du contenu de `data` → chantier séparé.
2. **Convergence forcée** des réponses « payload à la racine » vers `data` → décision de contrat distincte, nécessite coordination frontend.
3. **Modification** de `AuthResponsePresenter` / `BuildsAttemptResponses`.
4. **Axe #2** (autorisation par Policies) et **Axe #3** (`KlassciUserSynchronizer`).

## 6. Critères d'acceptation (Definition of Done — PR-0)

- [ ] Trait `RespondsWithJson` créé, ≤ 300 lignes, monté sur `Controller` de base.
- [ ] Contrat R2 respecté à l'identique d'`AuthResponsePresenter` (vérifié par test).
- [ ] Tests unitaires R5 verts ; `php artisan test` à 100 %.
- [ ] Aucun controller migré (R6).
- [ ] Aucun `getMessage()` exposé (R4) ; checklist pre-commit [§3](../../../PRODUCTION_STANDARDS.md#L109) passée.
- [ ] PHPStan vert (pas de nouvelle entrée baseline).

## 7. Alternatives écartées ([§4 Q12](../../../PRODUCTION_STANDARDS.md#L144))

| Alternative | Raison du rejet |
|---|---|
| **Classe `ApiResponse` statique** | Appel statique = Service Locator, viole [§1.6 D](../../../PRODUCTION_STANDARDS.md#L63). Le trait sur le base Controller est l'idiome Laravel sans dépendance cachée. |
| **`Response::macro()` dans un ServiceProvider** | Magie globale non typée, non découvrable par l'IDE, difficile à tester unitairement. Le trait est explicite et typé. |
| **`JsonResource` d'emblée** | Doublerait le périmètre (enveloppe + mapping) et le risque sur 48 controllers. On sépare : enveloppe d'abord (cette spec), mapping ensuite. |
```

