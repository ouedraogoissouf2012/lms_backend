# CI Security Pipeline

Ce document explique le pipeline d'audit sécurité automatique qui s'exécute à chaque pull request vers la branche `lms` (production).

L'objectif est de répondre à l'exigence de [PRODUCTION_STANDARDS.md §1.2](../PRODUCTION_STANDARDS.md) — **vérification continue de la sécurité, pas un audit manuel une fois par an**.

Ce pipeline est construit de façon **incrémentale** (issue [#65](../../../issues/65)) :

| Étape | État | Outils | Détecte |
|---|---|---|---|
| **1** | ✅ | `composer audit` + Dependabot + Dependency Review | CVE connus dans dépendances Composer + GitHub Actions, détection proactive ET réactive |
| **2** | ✅ **Cette PR** | PHPStan + Larastan **niveau 9** + baseline | Bugs typés, logiques fragiles, API Laravel mal utilisée, null-safety, types génériques |
| 3 | À venir | Semgrep (ruleset OWASP) + secret scanning | Patterns SAST (SQL injection, XSS, hardcoded secrets) |
| 4 | À venir | OWASP ZAP | DAST scan sur staging avant release |

---

## Étape 1 — Défense en profondeur sur les dépendances

Trois mécanismes complémentaires se déclenchent automatiquement :

| Mécanisme | Type | Quand | Bloque ? |
|---|---|---|---|
| `composer audit` (workflow) | **Réactif** | À chaque PR vers `lms` | Oui si CVE détecté |
| GitHub Dependency Review | **Réactif diff-only** | À chaque PR qui touche les deps | Oui si dep ajoutée a CVE `high+` |
| Dependabot | **Proactif** | Quotidien indépendamment des PRs | Non — ouvre une PR de fix |

Pourquoi les trois ?
- `composer audit` vérifie l'**état actuel** de `composer.lock`
- `dependency-review-action` vérifie **uniquement le diff** de la PR (catche aussi une dep ajoutée juste publiée comme malicieuse, avant Packagist advisory DB)
- Dependabot **agit même sans PR humaine** (CVE découvert un dimanche, PR de fix créée lundi matin)

### Quand le workflow se déclenche

- À chaque **pull request** ciblant `lms`
- À chaque **push direct** sur `lms` (filet de sécurité)

### Ce que le workflow fait

1. Setup PHP 8.3 + Composer 2
2. `composer install --no-dev` : installe **uniquement les dépendances de prod** (pas Tinker, Faker, etc.)
3. `composer audit --abandoned=ignore` : compare `composer.lock` contre la [base de données des advisories Packagist](https://packagist.org/security-advisories)
4. `composer outdated --direct` (informationnel, ne bloque pas)

### Sévérités

| Sévérité | Comportement |
|---|---|
| `critical`, `high` | Échec du workflow → PR non mergeable |
| `medium`, `low` | Échec du workflow par défaut (à ajuster si trop bruyant) |
| `abandoned` (package non maintenu) | Ignoré ici — pas un CVE, à traiter séparément en maintenance |

### Pourquoi `--no-dev`

Auditer les `require-dev` produit du bruit :
- `laravel/tinker` n'est pas en prod (cf. issue #41)
- `phpunit/phpunit`, `mockery/mockery` ne tournent qu'en CI
- Leurs CVE potentiels n'affectent pas la prod

### Lever un faux positif

Si un advisory remonte alors que le risque n'est pas réel pour notre usage :

1. Documenter dans `docs/SECURITY_CI.md` une section « Advisories acceptées » avec :
   - Référence CVE (ex: `CVE-2024-xxxxx`)
   - Package + version concernée
   - Raison d'acceptation (ex: « notre code n'utilise pas la fonction vulnérable X »)
   - Date d'acceptation + qui a validé
2. Utiliser `composer audit --ignore-severity=low` ou `--ignore=CVE-xxxx` en dernier recours
3. **Toujours préférer mettre à jour la dépendance** plutôt qu'ignorer

### Test du workflow

Le workflow s'exécute automatiquement à l'ouverture de la PR qui le contient. Voir l'onglet « Checks » de la PR.

Pour lancer `composer audit` localement avant push :

```bash
composer audit --abandoned=ignore
```

### Dependabot — configuration

Voir [`.github/dependabot.yml`](../.github/dependabot.yml). Tourne quotidiennement sur :
- `composer` (toutes les dépendances PHP, scope `/`)
- `github-actions` (toutes les versions des actions utilisées dans `.github/workflows/*.yml`)

Limite : **5 PRs ouvertes maximum** par écosystème — évite la noyade en cas de salve de mises à jour. Si la limite est atteinte, Dependabot mettra en pause les nouvelles PRs jusqu'à ce qu'on traite les existantes.

Si Dependabot devient trop bruyant (par exemple, 5 PRs par semaine de patches mineurs) :
- Passer à `interval: weekly`
- Ou ignorer les patches de sécurité non-`high` :

```yaml
ignore:
  - dependency-name: "*"
    update-types: ["version-update:semver-patch"]
```

### GitHub Actions supply-chain — best practice 2025

Les Actions tierces dans les workflows sont une surface d'attaque (cf. incident `tj-actions/changed-files` 2025). Dependabot monitore les versions, mais en plus, **considérer le pin à un SHA commit** plutôt qu'à un tag de version pour les Actions critiques. Trade-off : sécurité renforcée vs. maintenance lourde. Pas encore appliqué ici pour éviter de surcharger l'étape 1 ; à évaluer dans une PR séparée.

---

## Sources & références

- [`PRODUCTION_STANDARDS.md`](../PRODUCTION_STANDARDS.md) §1.2 Sécurité Absolue
- [Composer audit documentation](https://getcomposer.org/doc/03-cli.md#audit)
- [Packagist security advisories database](https://packagist.org/security-advisories)
- Issue [#65](../../../issues/65) — pipeline complet
- OWASP ASVS v5 chapitre 14.2 (Dependency vulnerability scanning)

---

## Étape 2 — PHPStan / Larastan niveau 9 + baseline

### Stratégie

Standard 2025 pour adopter PHPStan sur un projet Laravel existant : **démarrer au niveau maximum (9) avec un fichier baseline** qui « grandfather » les violations historiques. Tout NOUVEAU code doit passer level 9 ; le legacy migre progressivement.

Sources :
- [`laravel.io` — From 0 to 9 with Larastan](https://laravel.io/articles/how-to-get-your-laravel-app-from-0-to-9-with-larastan)
- [`phpstan.org` — Rule Levels](https://phpstan.org/user-guide/rule-levels)
- [`larastan/larastan` GitHub repo](https://github.com/larastan/larastan) (extension officielle Laravel)

### Configuration

`phpstan.neon.dist` (versionné) :
- `level: 9`
- `paths: [app]`
- `parallel.processTimeout: 300`
- `tmpDir: storage/framework/cache/phpstan`
- Inclut `phpstan-baseline.neon`

### Le baseline (`phpstan-baseline.neon`)

- **1648 violations** grandfathered au moment de l'adoption
- Versionné dans git
- **Régénéré automatiquement** quand on touche un fichier listé : `composer phpstan:baseline`
- À chaque PR qui touche un fichier dans la baseline, **fix les violations de ce fichier dans la même PR** (sinon la baseline diverge)

### Workflow CI

Le job `phpstan-analysis` s'exécute à chaque PR vers `lms`. Il installe les deps (avec dev), restaure le cache PHPStan, et lance `vendor/bin/phpstan analyse`. Si une violation hors baseline est détectée → PR bloquée.

### Commandes locales (DX)

\`\`\`bash
# Lancer PHPStan localement avant push
composer phpstan

# Régénérer la baseline (à faire après avoir fixé des violations legacy)
composer phpstan:baseline
\`\`\`

### Limitations connues

- La baseline contient beaucoup d'erreurs « PHPDoc » manquants — la majorité peut être résolue par génération automatique via `phpdoc-parser` ou IDE
- Quand `app/Http/Controllers/API/LMSDataController.php` (>2000 lignes) sera splitté (TIER 1 du `REFACTORING_ROADMAP.md`), une grosse partie de la baseline tombera

---

## Prochaine étape

Quand cette PR est mergée et que le workflow tourne proprement, on passera à l'**étape 3** : Semgrep SAST avec ruleset OWASP + secret scanning. Voir issue [#65](../../../issues/65) pour le suivi.
