# NORMES DE QUALITÉ PRODUCTION — MANIFESTE

## Rigueur Militaire

Ce document définit les standards non-négociables pour transformer ce code MVP en production-grade.

---

## 1. PRINCIPES FONDAMENTAUX

### 1.1 Zero God Code
- **Règle** : Aucun fichier ne dépasse 300 lignes de code métier.
- **Contrôle** : Chaque commit inclut une vérification de taille. Si dépassement, refactoring obligatoire.
- **Exceptions** : Aucune.

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

## 4. LES 10 QUESTIONS SELF-CRITIQUE

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
