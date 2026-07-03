# RUNBOOK — Purge de `database.sqlite` de l'historique git (issue #362, phase B)

> **Statut : NON EXÉCUTÉ.** Ce document prépare la phase B. La phase A (untrack +
> `.gitignore`) est faite. NE PAS dérouler cette procédure sans avoir validé
> TOUS les prérequis de la section 2 — elle réécrit l'historique et invalide
> tous les clones existants.

## 1. Contexte et verdict de la phase A

`database.sqlite` (831 488 octets) a été versionné à la racine du dépôt entre
les commits `ebb8e0c` et le retrait de la phase A. L'historique contient
**4 blobs distincts** à purger :

| Commit introducteur | SHA du blob |
|---|---|
| `ebb8e0c` | `c50c8bf14b57ceb954521abf8965a707046e406e` |
| `0ef1fd3` | `b8808dea5385367240135aec42fd7be74ecd5a10` |
| `509e0f0` | `f8fe902b7d2b009762f4c720b8dd1e2f0f93cc37` |
| `7e38a94` | `d082e566e7bb1abbf4c50f69cc2f23848ea9bf8d` |

**Verdict phase A (audit du 2026-07-03, détail en commentaire de l'issue #362)** :
les 4 blobs sont des bases *schéma-seul* — 0 ligne dans toutes les tables métier,
0 e-mail dans le binaire (pages libres incluses), 0 token. **Aucune PII, aucune
rotation de secrets requise.** La purge relève de l'hygiène du dépôt (le schéma
est déjà public via `database/migrations/`), pas de la réponse à incident —
d'où son report en phase B coordonnée plutôt qu'une purge en urgence.

## 2. Prérequis — TOUS obligatoires avant de commencer

1. **Toutes les PR ouvertes sont mergées ou fermées.** Une réécriture
   d'historique rend les branches de PR non-mergeables (ancêtres réécrits).
   Vérifier : `gh pr list --state open` → doit être vide.
2. **Tous les worktrees/clones locaux de l'équipe sont poussés puis supprimés.**
   Après la purge, un `git pull` depuis un vieux clone réintroduit les blobs.
   La consigne post-purge est : **re-cloner, jamais puller** (§6).
3. **Fenêtre de gel convenue avec l'équipe** (aucun push pendant l'opération).
4. **Coordination cPanel** : le serveur (`/home/c2569688c/public_html/lms-backend/`)
   est un clone git. Il devra être resynchronisé selon §6.3 — planifier un créneau
   hors heures de cours. Respecter la consigne en vigueur : pas d'action cPanel
   sans ordre explicite du propriétaire du dépôt.
5. **Sauvegarde miroir** réalisée et vérifiée (§4, étape 1).
6. **`git filter-repo` installé** : `pip install git-filter-repo` (ou
   `pipx install git-filter-repo`). Vérifier : `git filter-repo --version`.

## 3. Outil retenu : `git filter-repo`

C'est l'outil recommandé par la documentation GitHub « Removing sensitive data
from a repository » et par la page d'aide de `git-filter-branch` elle-même
(qui se déclare déprécié au profit de filter-repo).

Alternatives écartées :

- **BFG Repo-Cleaner** : rapide, mais nécessite un runtime Java, ne réécrit pas
  le commit HEAD protégé (d'où des cas limites), et son dépôt est peu maintenu.
  filter-repo couvre le même besoin sans dépendance supplémentaire.
- **`git filter-branch`** : officiellement déprécié (lent, pièges de sécurité
  documentés dans `man git-filter-branch`, section WARNING).

## 4. Procédure

Travailler dans un répertoire NEUF, jamais dans un clone de travail.

```bash
# 1. Sauvegarde miroir (rollback complet possible tant qu'on la garde)
git clone --mirror git@github.com:ouedraogoissouf2012/lms_backend.git lms_backend-backup.git
tar czf lms_backend-backup-$(date +%Y%m%d).tar.gz lms_backend-backup.git

# 2. Clone miroir de travail (filter-repo exige un clone frais)
git clone --mirror git@github.com:ouedraogoissouf2012/lms_backend.git lms_backend-purge.git
cd lms_backend-purge.git

# 3. Purge : supprime database.sqlite de TOUS les commits, toutes branches, tous tags
git filter-repo --invert-paths --path database.sqlite

# 4. Vérifications AVANT push (voir §5 — toutes doivent passer)

# 5. Force-push de toutes les refs réécrites
#    (filter-repo retire l'origin par sécurité : le remettre)
git remote add origin git@github.com:ouedraogoissouf2012/lms_backend.git
git push origin --force --all
git push origin --force --tags
```

Note : la protection de branche de `lms` peut refuser le force-push. La
désactiver temporairement (Settings → Branches), pousser, la réactiver
immédiatement.

## 5. Vérifications post-purge (critères d'acceptation de l'issue)

Dans le miroir purgé, puis dans un clone frais après push :

```bash
# a) Plus aucun commit ne référence le fichier
git log --all --oneline -- database.sqlite          # → vide

# b) Les 4 blobs n'existent plus dans l'object store
for b in c50c8bf14b57ceb954521abf8965a707046e406e \
         b8808dea5385367240135aec42fd7be74ecd5a10 \
         f8fe902b7d2b009762f4c720b8dd1e2f0f93cc37 \
         d082e566e7bb1abbf4c50f69cc2f23848ea9bf8d; do
  git cat-file -e "$b" 2>/dev/null && echo "ENCORE PRESENT: $b"
done                                                 # → aucune ligne

# c) Aucun fichier de base de données tracké
git ls-files | grep -iE 'sqlite|\.dump' || echo OK   # → OK

# d) L'application est intacte : cloner, installer, tester
composer install && php artisan test                 # → 100% PASS
```

Côté GitHub, l'ancien historique peut rester accessible via les caches
(vues de commits par SHA, refs de PR). Sans PII c'est acceptable ; pour une
disparition complète, ouvrir un ticket au support GitHub pour purger ces caches
(procédure décrite dans leur doc « Removing sensitive data »).

## 6. Après le push — resynchronisation de tous les environnements

### 6.1 Équipe
Annoncer que l'historique a changé. Chaque personne **supprime son clone et
re-clone**. Interdit : `git pull` ou `git rebase` depuis un ancien clone
(réintroduirait les anciens objets).

### 6.2 Worktrees locaux
Les worktrees (`lms-worktrees/wt-*`) dérivent du clone principal : les
supprimer (`git worktree remove`) avant de supprimer le clone, puis recréer.

### 6.3 Serveur cPanel (sur ordre explicite uniquement)
Le serveur ne peut pas puller après la réécriture. Procédure :

```bash
cd /home/c2569688c/public_html
mv lms-backend lms-backend.old                        # rollback possible
git clone --branch lms <remote> lms-backend
cd lms-backend
cp ../lms-backend.old/.env .env                       # .env n'est pas versionné
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache
# vérifier le site, puis (après quelques jours) : rm -rf ../lms-backend.old
```

`storage/` : rapatrier les fichiers uploadés depuis `lms-backend.old/storage/`
avant toute suppression.

### 6.4 Nettoyage final
- Supprimer `lms_backend-purge.git` local.
- Conserver `lms_backend-backup-*.tar.gz` hors ligne 90 jours, puis détruire.
- Fermer l'issue #362 avec le récapitulatif des vérifications §5.

## 7. Rollback

Tant que la sauvegarde §4.1 existe : `cd lms_backend-backup.git &&
git push origin --force --all && git push origin --force --tags` restaure
l'historique d'origine à l'identique. Après restauration, refaire signer la
fenêtre de gel avant toute nouvelle tentative.
