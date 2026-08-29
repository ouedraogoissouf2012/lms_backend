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

## La taille du fichier ne suffit pas : la longueur des méthodes aussi

Un fichier de 280 lignes respecte la limite tout en concentrant 86 lignes dans une seule
méthode. C'est cette forme-là qui fragilise le plus : une méthode aussi longue n'est pas
testable unitairement, et chaque évolution s'y greffe au lieu de créer l'abstraction qui
manque.

PRODUCTION_STANDARDS.md §5 fixe **« Méthodes ≤ 40 lignes »** — règle qui n'était outillée
par rien. Le job CI **`Method length guard`**
([`scripts/check-method-sizes.php`](../scripts/check-method-sizes.php)) l'applique désormais,
sur le même principe que la garde de fichiers : seuls les fichiers **modifiés par la PR**
sont contrôlés.

L'analyse se fait par `token_get_all()`, pas par expression régulière : accolades dans les
chaînes, heredocs et closures imbriquées sont correctement traités. Le décompte porte sur
les **lignes de code effectives** — documenter une méthode ne la pénalise jamais.

### La baseline est un cliquet

[`scripts/method-length-baseline.php`](../scripts/method-length-baseline.php) liste les
**49 méthodes** déjà en dépassement au moment de l'introduction de la garde. Chacune est
tolérée **à sa longueur actuelle, jamais au-delà** :

- une méthode de la baseline qui **grossit** fait échouer la PR ;
- une **nouvelle** méthode au-dessus de 40 lignes fait échouer la PR ;
- une méthode qui **rétrécit** est signalée pour que la valeur soit mise à jour ;
- une méthode repassée sous 40 lignes sort de la liste.

La dette ne peut donc que diminuer. C'est la même logique que le ratchet PHPStan.

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
