# CI Security Pipeline

Ce document explique le pipeline d'audit sécurité automatique qui s'exécute à chaque pull request vers la branche `lms` (production).

L'objectif est de répondre à l'exigence de [PRODUCTION_STANDARDS.md §1.2](../PRODUCTION_STANDARDS.md) — **vérification continue de la sécurité, pas un audit manuel une fois par an**.

Ce pipeline est construit de façon **incrémentale** (issue [#65](../../../issues/65)) :

| Étape | État | Outil | Détecte |
|---|---|---|---|
| 1 | ✅ **Cette PR** | `composer audit` | CVE connus dans les dépendances Composer |
| 2 | À venir | PHPStan + Larastan niveau 9 | Bugs typés, logiques fragiles, API mal utilisée |
| 3 | À venir | Semgrep (ruleset OWASP) | Patterns SAST (SQL injection, XSS, secrets in code) |
| 4 | À venir | OWASP ZAP | DAST scan sur staging avant release |

---

## Étape 1 — `composer audit`

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

---

## Sources & références

- [`PRODUCTION_STANDARDS.md`](../PRODUCTION_STANDARDS.md) §1.2 Sécurité Absolue
- [Composer audit documentation](https://getcomposer.org/doc/03-cli.md#audit)
- [Packagist security advisories database](https://packagist.org/security-advisories)
- Issue [#65](../../../issues/65) — pipeline complet
- OWASP ASVS v5 chapitre 14.2 (Dependency vulnerability scanning)

---

## Prochaine étape

Quand cette PR est mergée et que le workflow tourne proprement sur quelques PRs, on passe à l'**étape 2** : PHPStan + Larastan niveau 9. Voir issue [#65](../../../issues/65) pour le suivi.
