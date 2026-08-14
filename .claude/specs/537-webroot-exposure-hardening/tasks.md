# Tasks — #537 [P0][SECURITY] Exposition potentielle du webroot cPanel (.env/.git)

- [x] 1. Créer `.htaccess` à la racine du dépôt (defense-in-depth Apache)
  - Révisé après audit `spec-security` (finding HIGH) : règle générique
    `RewriteCond %{REQUEST_URI} (^|/)\.` (+ exception `/.well-known/`) au lieu
    d'une énumération par nom (`.git` seul laissait passer `.claude/specs/`, qui
    contient des specs de sécurité versionnées) — voir design.md §1 révisé
  - Repli `<FilesMatch "^\.">` + `RedirectMatch` explicites si `mod_rewrite` absent
  - `Options -Indexes`
  - _Requirements: R1, R2, R3, R4_

- [x] 2. Durcir `.cpanel.yml` : copie sélective non-destructive
  - Remplacer `/bin/cp -R . $DEPLOYPATH` par
    `rsync -a --exclude='.git' --exclude='.env*' --exclude='tests' --exclude='.claude' ./ $DEPLOYPATH`
    (`.claude` ajouté suite au même finding sécurité que la tâche 1)
  - Commentaire expliquant le risque écarté (destruction d'un `.git` de destination
    déjà actif) et la nécessité de vérifier le chemin `rsync` au premier vrai déploiement
  - _Requirements: R5, R6, R7_

- [x] 3. Combler le gap de `docs/DEPLOIEMENT_CPANEL.md`
  - Ajouté les 2 commandes `curl` de vérification webroot (`.env`, `.git/HEAD`) en
    section 6 (vérifications de santé), avec seuil d'alerte (200 → rotation secrets
    immédiate)
  - _Requirements: R8_

- [x] 4. Vérification locale des 3 fichiers
  - `.htaccess` : validation syntaxique manuelle (relecture + comparaison à la doc
    Apache citée dans design.md) — pas de linter Apache dispo localement
  - `.cpanel.yml` : validé via `Symfony\Component\Yaml\Yaml::parseFile()` — 4 tâches
    parsées correctement, commentaires bien ignorés
  - `docs/DEPLOIEMENT_CPANEL.md` : relu, cohérent avec le reste du document
  - Audit `spec-security` + `spec-architect` exécutés en parallèle (CONTRIBUTING.md
    §A) : 1 finding HIGH sécurité corrigé (règle `.htaccess` généralisée), 1 finding
    MEDIUM architecture corrigé (specs absentes du worktree isolé — copiées ici)
  - _Requirements: R3, R7_

- [ ] 5. PR vers `lms` avec description honnête des limites (pas de test PHPUnit
      applicable, hypothèses rsync/Apache non vérifiées en SSH — cf. design.md §4)
  - _Requirements: (documentation du compromis, §6 manifeste)_
