# CONTRIBUTING — Règles de travail KLASSCI LMS

> **Projet long terme (10+ ans).** Tout contributeur doit lire ce document AVANT son premier commit.

Ce projet a **deux systèmes de règles complémentaires** que tu dois suivre simultanément :

| Système | Quand l'appliquer | Document de référence |
|---|---|---|
| **A. Spec-Driven Workflow** | Pour TOUTE nouvelle feature ou refacto > 1 fichier | [`.claude/system-prompts/spec-workflow-starter.md`](.claude/system-prompts/spec-workflow-starter.md) |
| **B. Production Standards** | Pour CHAQUE commit, sans exception | [`PRODUCTION_STANDARDS.md`](PRODUCTION_STANDARDS.md) |

Les deux ne se remplacent pas — ils s'empilent.

---

## A. Workflow Spec-Driven — pour les nouvelles features

### Quand l'utiliser

- Nouvelle feature (ex : "ajouter notifications WhatsApp aux parents")
- Refactoring qui touche plus d'un fichier
- Changement d'architecture
- Migration de données non triviale

### Quand NE PAS l'utiliser

- Hotfix d'un bug isolé
- Correction d'un typo
- Mise à jour de dépendance
- Ajout d'un test manquant

### Les 5 phases obligatoires

```
1. REQUIREMENTS  →  .claude/specs/{feature}/requirements.md
   Format EARS (WHEN / IF / WHERE / WHILE + SHALL)
   ⚠️ APPROBATION user obligatoire avant l'étape suivante

2. DESIGN        →  .claude/specs/{feature}/design.md
   Architecture + diagrammes Mermaid
   Data models, Business processes, Error handling, Testing strategy
   ⚠️ APPROBATION user obligatoire

3. TASKS         →  .claude/specs/{feature}/tasks.md
   Checklist hiérarchique max 2 niveaux (1, 1.1, 1.2)
   Chaque tâche référence un requirement (_Requirements: 1.2_)
   ⚠️ APPROBATION user obligatoire

4. IMPL          →  une tâche à la fois
   spec-impl agent code la tâche {task_id}
   Marquer - [x] dans tasks.md à la fin

5. TEST          →  tests/{module}.md + tests/{module}.test.php
   Documentation et code 1:1 (chaque cas de test documenté = un test exécuté)
   Pattern AAA (Arrange / Act / Assert)
```

### Sub-agents à utiliser

| Phase | Sub-agent | Fichier de règles |
|---|---|---|
| Requirements | `spec-requirements` | [`.claude/agents/kfc/spec-requirements.md`](.claude/agents/kfc/spec-requirements.md) |
| Design | `spec-design` | [`.claude/agents/kfc/spec-design.md`](.claude/agents/kfc/spec-design.md) |
| Tasks | `spec-tasks` | [`.claude/agents/kfc/spec-tasks.md`](.claude/agents/kfc/spec-tasks.md) |
| Implémentation | `spec-impl` | [`.claude/agents/kfc/spec-impl.md`](.claude/agents/kfc/spec-impl.md) |
| Tests | `spec-test` | [`.claude/agents/kfc/spec-test.md`](.claude/agents/kfc/spec-test.md) |
| Évaluation versions parallèles | `spec-judge` | [`.claude/agents/kfc/spec-judge.md`](.claude/agents/kfc/spec-judge.md) |

### Règles non-négociables du workflow

1. **JAMAIS sauter une phase** sans approbation explicite ("yes", "approved", "looks good")
2. **JAMAIS combiner plusieurs étapes** dans une seule interaction
3. **Une seule tâche à la fois** en mode par défaut
4. Marquer `- [x]` dans `tasks.md` IMMÉDIATEMENT après la complétion d'une tâche
5. Pour requirements/design/tasks parallèles → demander combien d'agents (1-128)
6. `spec-judge` évalue les versions parallèles : ceil(n/4) judges au round 1, jusqu'à ≤ 3 docs

---

## B. Production Standards — pour CHAQUE commit

### Les 6 principes non-négociables

| # | Principe | Vérification |
|---|---|---|
| 1.1 | **Zero God Code** | `find app/ -name '*.php' | xargs wc -l | awk '$1>300'` doit être vide |
| 1.2 | **Sécurité Absolue** | `grep -r 'getMessage()' app/Http/Controllers` = 0 |
| 1.3 | **Tests Obligatoires** | `php artisan test` = 100% |
| 1.4 | **Performance Garantie** | Laravel Debugbar = zero N+1 |
| 1.5 | **Validation Systématique** | Tout input via FormRequest |
| 1.6 | **SOLID & Architecture Décennale** | Liskov respecté + injection de deps + scale 10× sans réécriture |

### Checklist pre-commit (13 points)

```
☑ Aucun fichier > 300 lignes
☑ Aucun $e->getMessage() exposé au client
☑ Aucun token plaintext en DB
☑ Aucun endpoint sans auth:sanctum
☑ Aucun N+1 HTTP (vérifié via Debugbar)
☑ Aucun N+1 SQL (vérifié via Debugbar)
☑ php artisan test = 100% PASS
☑ Pas de PHP_EOL, var_dump, dd() dans le code
☑ Code relu (lisibilité, logique, sécurité)
☑ Commit message explique le POURQUOI (pas le QUOI)
☑ MEMORY.md à jour si décision impactante
☑ Postman collection à jour si l'API change
☑ Migration créée si la DB change
```

### Les 15 questions self-critique avant CHAQUE PR

Si UNE réponse = non → ne pas merger.

1. Cette solution résout-elle la **racine** du problème (pas le symptôme) ?
2. Crée-t-elle un nouveau problème ailleurs ?
3. Les tests couvrent-ils happy path **ET** edge cases ?
4. Un développeur senior approuverait-il ce code ?
5. Peut-on supprimer du code (duplication, complexité) ?
6. Les noms de variables/fonctions sont-ils auto-documentés ?
7. Y a-t-il des secrets en plaintext ?
8. Y a-t-il des N+1 ?
9. Chaque "pourquoi non-évident" a-t-il un commentaire ?
10. Les erreurs sont-elles gérées sans exposer le détail au client ?
11. **C'est la meilleure solution architecturale, ou la plus rapide à coder ?** Si rapide → justifier par écrit, sinon refaire.
12. **Qu'est-ce que tu n'as PAS considéré ?** Lister 2 alternatives écartées et la raison du rejet.
13. **Dans 2 ans à 10× le volume, ça tient toujours ?** Projection explicite des chiffres.
14. **Cites-tu une source ou bluffes-tu ?** Chaque best practice invoquée pointe vers doc/RFC/benchmark.
15. **Qu'est-ce qui te ferait changer d'avis ?** Définir le critère qui invaliderait la solution.

### Standards par type de code

| Type | Taille max | Règles |
|---|---|---|
| **Controller** | 200 lignes | Validation via FormRequest, appel service, retour response. JAMAIS de logique métier ni DB directe |
| **Service** | 300 lignes | SRP strict, méthodes ≤ 40 lignes, dépendances injectées (jamais de `new`) |
| **Modèle** | 150 lignes | Relations + casts + scopes uniquement |
| **Migration** | — | FK avec cascade, index sur les FK, tokens chiffrés, dates immutables |
| **Test** | — | Setup + Action + Assert. 2 institutions pour multi-tenant. Pas de `sleep()`, pas de DB mocks |

### Règle "Une seule solution"

**Jamais** proposer "on pourrait faire A ou B".
**Toujours** proposer "on doit faire A car [raison vérifiée]".
Si doute → demander au user **avant** de coder.

---

## C. Workflow Git

### Branches

| Branche | Usage |
|---|---|
| `lms` | Production. Protégée. Merge via PR uniquement. |
| `dev` | Intégration. Tests E2E avant merge dans `lms`. |
| `feature/*` | Nouvelles features (suivre le spec-workflow) |
| `fix/*` | Hotfixes (suivre les production-standards uniquement) |
| `chore/*` | Tâches de maintenance (doc, config, ...) |
| `critical-XX/*` | Items de [REFACTORING_ROADMAP.md](REFACTORING_ROADMAP.md) TIER 0 |

### Format des commits

```
type(scope): description courte au présent

Détails (optionnel, expliquer le POURQUOI)

Refs: #issue-number
```

Types autorisés : `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `security`

### Workflow PR

1. Créer la branche depuis `lms` (ou `dev` pour features)
2. Suivre le spec-workflow si feature
3. Respecter la checklist pre-commit
4. Push + ouvrir PR vers `lms`
5. Référencer l'issue correspondante (`Closes #XX`)
6. Au moins **1 review** approuvée
7. Tous les checks CI verts
8. Merge en `squash` pour garder un historique propre

---

## D. Pour les nouveaux contributeurs (Day 1)

### Setup initial

```bash
# 1. Cloner et installer
git clone git@github.com:ouedraogoissouf2012/lms_backend.git
cd lms_backend
composer install
cp .env.example .env  # NB : .env.example absent, à reconstituer depuis [README.md](README.md)
php artisan key:generate
php artisan migrate

# 2. Lire dans l'ordre
cat CONTRIBUTING.md              # Ce fichier
cat PRODUCTION_STANDARDS.md      # Les 5 principes
cat REFACTORING_ROADMAP.md       # Plan global
cat docs/SETUP.md                # Setup détaillé
cat docs/EXECUTION_GUIDE.md      # Comment exécuter une tâche
cat docs/TEAM_CHECKLIST.md       # Checklists par scénario

# 3. Pour ta première contribution
#    a. Choisir une issue (TIER 0 CRITICAL en priorité)
#    b. Si > 1 fichier → spec-workflow (cf. section A)
#    c. Si hotfix → production-standards uniquement (cf. section B)
```

### Documents à connaître

| Doc | Quand le lire |
|---|---|
| **CONTRIBUTING.md** (ce fichier) | Day 1 — obligatoire |
| **PRODUCTION_STANDARDS.md** | Day 1 — obligatoire |
| **REFACTORING_ROADMAP.md** | Day 1 — comprendre le plan global |
| **docs/SETUP.md** | Day 1 — pour le setup du projet |
| **docs/EXECUTION_GUIDE.md** | Day 2 — avant la première tâche |
| **docs/TEAM_CHECKLIST.md** | Day 2 — checklists d'exécution |
| **docs/ADDING_NEW_ENDPOINTS.md** | Quand tu ajoutes un endpoint |
| **docs/API_MAINTENANCE_GUIDE.md** | Quand tu modifies un endpoint existant |
| **docs/IMPROVEMENT_PRIORITIES.md** | Avant de proposer une nouvelle tâche |
| **CRITICAL-05_TIER1_COMPLETION.md** | Exemple de récap de fin de phase |

---

## E. Que faire si...

| Situation | Réponse |
|---|---|
| J'ai une idée d'amélioration | Créer une issue référencée dans `docs/IMPROVEMENT_PRIORITIES.md` |
| Je trouve un bug en prod | Hotfix branche `fix/XX-description`, PR vers `lms`, suivre production-standards |
| Je veux ajouter une feature | Spec-workflow obligatoire : `.claude/specs/{feature}/requirements.md` d'abord |
| Une issue n'est plus pertinente | Commenter pourquoi puis fermer (jamais supprimer) |
| Un test échoue | Le **fixer**, jamais le supprimer ou le `skip` sans issue dédiée |
| Le code dépasse 300 lignes | Refacto obligatoire avant merge — pas d'exception |
| Je vois un `$e->getMessage()` exposé | C'est un bug de sécurité → fix immédiat |
| Je vois un `Cache::flush()` | C'est cassé en multi-tenant → utiliser `Cache::tags(["institution_X"])->flush()` |

---

## F. Liens rapides

- [Workflow Spec-Driven complet](.claude/system-prompts/spec-workflow-starter.md)
- [Production Standards](PRODUCTION_STANDARDS.md)
- [Roadmap globale](REFACTORING_ROADMAP.md)
- [Setup du projet](docs/SETUP.md)
- [Guide d'exécution](docs/EXECUTION_GUIDE.md)
- [Checklists équipe](docs/TEAM_CHECKLIST.md)
- [Ajouter un endpoint](docs/ADDING_NEW_ENDPOINTS.md)
- [Maintenance API](docs/API_MAINTENANCE_GUIDE.md)
- [Validation API](docs/API_VALIDATION.md)
- [Génération SDKs](docs/CLIENT_SDK_GENERATION.md)

---

**Version** : 1.0
**Dernière mise à jour** : mai 2026
**Question / suggestion** : ouvrir une issue avec le label `process-improvement`
