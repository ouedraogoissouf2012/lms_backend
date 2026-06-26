# Manifeste — Refactoring & taille des fichiers

> Règle d'or : **tout ajout ou modification de fonctionnalité respecte les limites de taille et le pattern en place. Si une fonctionnalité force à dépasser la limite, on découpe — on ne grossit pas le fichier.**

Ce manifeste **ne remplace pas** [PRODUCTION_STANDARDS.md](../PRODUCTION_STANDARDS.md) (source de vérité unique) : il en rappelle la règle la plus structurante et explique pourquoi elle est désormais **outillée**.

## La règle

| Cible | Limite | Référence |
|---|---|---|
| `app/**` (controllers, services, requests, jobs, …) | **≤ 300 lignes** | §1.1 |
| `app/Models/**` | **≤ 150 lignes** (relations / casts / scopes uniquement) | §5 |

Hors périmètre (non contrôlés) : `database/migrations/**`, `config/**`, specs OpenAPI, fixtures.

## Pourquoi un garde-fou automatique

Cette règle existait déjà dans PRODUCTION_STANDARDS.md — et a quand même été violée (`KlassciUserSynchronizer` est monté à 430 lignes sous la pression d'un correctif). **Un document non outillé se contourne.** Le job CI **`File size guard`** ([`scripts/check-file-sizes.php`](../scripts/check-file-sizes.php)) fait désormais **échouer toute PR** qui pousse un fichier modifié au-dessus de sa limite. Il ne contrôle que les fichiers **modifiés par la PR** : le legacy non touché n'est jamais bloqué, seul le code qu'on ajoute/modifie doit être propre.

## Quand on atteint la limite : on découpe

Le réflexe n'est jamais « tasser » mais **extraire une responsabilité** dans un collaborateur DIP, en suivant le pattern déjà présent :

- **Controller trop gros** → extraire un **Service** (logique métier) et/ou un **Presenter** (construction des réponses JSON).
- **Service trop gros** → extraire des **collaborateurs** à responsabilité unique (un fetcher, un resolver, un guard, un synchronizer…), injectés par constructeur (**DI strict**, aucune Facade).
- **Modèle trop gros** → ne garder que relations / casts / scopes ; déplacer la logique métier en service.

Exemple de référence : le découpage de `KlassciUserSynchronizer` (430 → 216 lignes) en `StudentClassSynchronizer`, `KlassciEnseignantIdResolver`, `KlassciEmailConflictGuard` (issue #275).

## En pratique, avant chaque PR

1. Le code ajouté/modifié reste sous la limite (le job CI le vérifie, mais on n'attend pas la CI pour le savoir).
2. Le pattern HTTP → service → repository et la DI stricte sont respectés à la lettre.
3. Tests d'abord (TDD), invariants de sécurité préservés.

Vérification locale rapide :

```bash
php scripts/check-file-sizes.php $(git diff --name-only origin/lms...HEAD -- 'app/**/*.php')
```
