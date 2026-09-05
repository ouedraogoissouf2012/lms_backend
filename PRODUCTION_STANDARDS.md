# NORMES DE QUALITÉ PRODUCTION — MANIFESTE

## Rigueur Militaire

Ce document définit les standards non-négociables pour transformer ce code MVP en production-grade.

---

## Engagement

Ce projet est conçu pour durer **plus de 10 ans**. Chaque ligne de code écrite aujourd'hui sera lue, comprise et maintenue par quelqu'un d'autre demain — peut-être par quelqu'un qui n'est pas encore dans l'équipe.

Cela impose une seule règle morale :

> **On choisit toujours la meilleure solution architecturale, jamais la plus rapide.**

Conséquences concrètes :

- Si une fonctionnalité nécessite 10 fichiers au lieu de 2, on fait 10 fichiers.
- Si une décision nécessite 5 vérifications au lieu d'une intuition, on fait 5 vérifications.
- On ne livre jamais un prototype en production.
- On ne raccourcit jamais une étape pour gagner du temps.
- En cas de doute → ralentir, vérifier, demander.

**Sur l'honneur de la bonne foi**, chaque contributeur s'engage à signaler honnêtement quand un compromis a été fait, plutôt que de présenter une solution rapide comme étant "la bonne".

---

## 1. PRINCIPES FONDAMENTAUX

### 1.1 Zero God Code
- **Règle** : Aucun fichier ne dépasse 300 lignes de code métier.
- **Contrôle** : Chaque commit inclut une vérification de taille. Si dépassement, refactoring obligatoire.
- **Exceptions** : Aucune.

### 1.1-bis Tout garde-fou publie son dénominateur

- **Règle** : un garde-fou affiche **combien d'éléments il a inspectés**, pas seulement « aucune violation ».
- **Règle** : il distingue trois sorties — `0` conforme, `1` violation, **`2` il n'a pas pu travailler**.
- **Contrôle** : pour chaque garde-fou, écrire **d'abord** le test qui prouve qu'il **rougit**.

**Pourquoi cette règle existe** (#701) : `check-file-sizes.php` et `check-method-sizes.php`
affichaient tous deux « ✓ … respectent la limite » lorsqu'on les appelait sans argument — en
n'ayant rien contrôlé. Un garde-fou incapable de dire *N* ne peut pas distinguer « rien à redire »
de « je n'ai rien regardé », et son vert ne vaut rien.

Aggravant, et c'est la partie qu'il faut retenir : un **test** exigeait ce comportement
(`FileSizeGuardTest::test_ignores_non_app_files`). Un test peut entériner un défaut, et devient
alors l'obstacle à sa correction.

Modèle de référence : `scripts/check-phpstan-baseline.php`.

### 1.2 Sécurité Absolue
- **Règle** : Aucun secret en plaintext en base.
- **Règle** : Aucun `$e->getMessage()` exposé au client.
- **Règle** : Aucun endpoint sans authentification + rôle vérifié.
- **Contrôle** : Grep automatique avant commit pour `getMessage()`, `plaintext`, `token`.

### 1.3 Tests Obligatoires
- **Règle** : Chaque classe public-facing a au minimum 2 tests (happy path + edge case).
- **Règle** : Multi-tenant doit avoir 2 tests (institution A, institution B).
- **Contrôle** : `php artisan test` doit passer 100% avant PR.

### 1.4 Performance Garantie
- **Règle** : Zéro N+1 HTTP. Zéro N+1 SQL.
- **Règle** : Lazy-load avec `with()`, batch les requêtes.
- **Contrôle** : Debugbar (Laravel Debugbar) avant chaque PR pour vérifier requêtes.

### 1.5 Validation Systématique
- **Règle** : Tout input non vérifiable dans une Form Request.
- **Règle** : Format de réponse JSON identique pour tous les endpoints.
- **Contrôle** : Postman collection mise à jour par PR.

### 1.6 SOLID & Architecture Décennale

- **Règle S — Single Responsibility** : chaque classe a UNE seule raison de changer.
- **Règle O — Open/Closed** : ouvert à l'extension, fermé à la modification. Nouveaux comportements via héritage/composition, pas en éditant le code existant.
- **Règle L — Liskov Substitution** : un sous-type doit être substituable à son type parent sans casser le comportement attendu. Pas de `throw new NotImplementedException` dans une classe enfant.
- **Règle I — Interface Segregation** : pas d'interface "fourre-tout". Plusieurs petites interfaces ciblées valent mieux qu'une grosse.
- **Règle D — Dependency Inversion** : on dépend des abstractions, jamais des implémentations concrètes. Services injectés via le constructor, jamais via `new` ou Facades en code métier.

- **Règle scalabilité** : toute solution doit tenir à 10× le volume actuel sans réécriture. Si la solution ne tient pas à 200 000 utilisateurs (10× les 20k visés), elle ne tient pas.
- **Règle maintenabilité** : un nouveau dev doit pouvoir comprendre une feature en lisant ses tests + son `design.md`, sans poser de questions à l'auteur.

**Contrôle** : avant chaque PR, vérifier que la classe modifiée :
1. Pourrait être substituée par un mock dans un test sans contournement → L respecté
2. N'a aucune dépendance instanciée avec `new` ou `Facade::method()` directement → D respecté
3. Ne fait qu'une chose (une méthode résumable en un verbe) → S respecté

---

## 2. WORKFLOW DE DÉVELOPPEMENT

### Phase 1 : Audit Critique
1. Lire le code actuel du problème
2. Identifier la racine du problème (pas le symptôme)
3. Vérifier si le problème touche d'autres fichiers
4. Estimer l'impact sur les tests existants

### Phase 2 : Design
1. Proposer UNE solution (pas d'alternatives)
2. Vérifier que la solution respecte les 5 principes fondamentaux
3. Vérifier que la solution ne crée pas de nouveau problème
4. Si doute → demander au user avant de coder

### Phase 3 : Implémentation
1. Coder avec commentaires de logique non-évidente
2. Appliquer le standard du fichier (pas de style personnel)
3. Vérifier que les tests passent PENDANT le codage
4. Relire le code avant de le tester

### Phase 4 : Validation
1. Tests DOIVENT passer 100%
2. Debugbar DOIT montrer zéro N+1
3. Grep pour les anti-patterns (getMessage, plaintext, etc.)
4. Vérifier que la PR ne break rien existant

### Phase 5 : Documentation
1. Commit message explique LE POURQUOI pas le QUOI
2. Postman collection mise à jour si API change
3. Migration si DB change
4. MEMORY.md mis à jour

---

## 3. CHECKLIST PRE-COMMIT

Avant CHAQUE commit :

- ☑ Aucun fichier ne dépasse 300 lignes
- ☑ Aucun $e->getMessage() dans le code
- ☑ Aucun token en plaintext
- ☑ Aucun endpoint sans auth:sanctum
- ☑ Aucun N+1 HTTP identifié (Debugbar)
- ☑ Aucun N+1 SQL identifié (Debugbar)
- ☑ Tests passent 100%
- ☑ Pas de PHP_EOL, var_dump, dd()
- ☑ Code relut (lisibilité, logique, sécurité)
- ☑ Commit message explique le POURQUOI
- ☑ MEMORY.md mis à jour
- ☑ Postman collection mise à jour (si API)
- ☑ Migration créée (si DB change)

---

## 4. LES 15 QUESTIONS SELF-CRITIQUE

Avant CHAQUE PR, répondre à ces questions. Si UNE réponse = non → réviser.

1. Cette solution résout-elle la racine du problème?
2. Cette solution crée-t-elle un nouveau problème ailleurs?
3. Les tests couvrent-ils happy path + edge cases?
4. Un collègue senior approuverait-il ce code?
5. Peut-on supprimer du code (duplication, complexité)?
6. Les noms de variables/fonctions sont-ils auto-documentés?
7. Y a-t-il des secrets en plaintext?
8. Y a-t-il des N+1?
9. Chaque "pourquoi non-évident" a-t-il un commentaire?
10. Les erreurs sont-elles gérées sans exposer le détail?
11. **C'est la meilleure solution architecturale, ou la plus rapide à coder ?** Si tu as choisi la rapide → justifier par écrit (commit message ou ADR), sinon refaire.
12. **Qu'est-ce que tu n'as PAS considéré ?** Lister 2 alternatives écartées et la raison du rejet. Si tu n'arrives pas à en lister 2, tu n'as pas assez exploré.
13. **Dans 2 ans à 10× le volume, ça tient toujours ?** Projection explicite : combien d'utilisateurs, de tenants, de requêtes/sec ? Si la réponse est "je ne sais pas" → mesure ou simulation obligatoire.
14. **Cites-tu une source ou bluffes-tu ?** Chaque "best practice" invoquée doit pointer vers : doc officielle, RFC, livre reconnu, ou benchmark interne. Pas de "je pense que" non sourcé.
15. **Qu'est-ce qui te ferait changer d'avis ?** Si tu ne peux pas répondre, ta conviction est dogmatique, pas raisonnée. Définir le critère qui invaliderait ta solution = test de solidité du raisonnement.

---

## 5. STANDARDS PAR TYPE DE CODE

### Controllers (max 200 lignes)
- Validation via Form Request
- Appel service
- Retour response
- JAMAIS: logique métier, DB directe, transformation complexe

### Services (max 300 lignes)
- Une responsabilité (SRP strict)
- Méthodes ≤ 40 lignes
- Dépendances injectées (pas de new)
- JAMAIS: logique métier multi-domaines

### Modèles (max 150 lignes)
- Relations
- Casts
- Scopes
- JAMAIS: updateStatistics(), accessors DB

### Migrations
- Foreign keys avec cascade
- Indexes sur les FK
- Tokens chiffrés ou en vault
- Dates immutables

### Tests
- Setup (fixtures)
- Action (appel endpoint)
- Assert (résultat)
- Multi-tenant test (2 institutions)
- JAMAIS: sleep(), DB mocks, external deps

---

## 6. PAS D'ALTERNATIVES — UNE SEULE SOLUTION

Jamais proposer "on pourrait faire A ou B".

Proposer: "on doit faire A car [raison vérifiée]".

Si doute sur la meilleure solution → demander au user AVANT de proposer.

---

## OBJECTIF FINAL

Aucune PR n'est fusionnée sans être conforme à ce manifeste.
