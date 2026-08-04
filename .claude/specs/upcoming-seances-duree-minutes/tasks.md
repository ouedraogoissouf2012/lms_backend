# Tasks — `duree_minutes` sur le listing « séances à venir » (#487)

Ordre TDD strict : RED (test qui échoue) → GREEN (correction) → refactor/vérif.

## 1. Test de contrat (RED)

- [ ] **1.1** Créer `tests/Feature/LMS/Seances/UpcomingSeancesDureeMinutesTest.php`.
  - **1.1a** `test_upcoming_listing_exposes_duree_minutes_from_programmation` :
    monter un scénario non-manager (étudiant/enseignant) qui traverse
    `UpcomingSeancesFetcher::fetch()` via l'endpoint `/api/lms/seances/upcoming`,
    avec un payload KLASSCI mocké produisant une séance dont
    `programmation.heure_debut = ...T08:00:00` et
    `programmation.heure_fin = ...T09:30:00` → assert `duree_minutes === 90`.
    _(REQ-1, REQ-3 — DOIT échouer avant correction : clé absente.)_
  - **1.1b** `test_upcoming_listing_omits_duree_minutes_when_heure_missing` :
    séance sans `programmation.heure_fin` → assert clé `duree_minutes`
    **absente** + réponse 200 sans exception. _(REQ-2.)_
  - **1.1c** (si faisable simplement) assert qu'aucune autre clé de contrat ne
    régresse (`programmation`, `matiere`, `classe`, `visio_enabled`). _(REQ-4.)_
  - Réutiliser les helpers existants (`disableKlassciMiddleware`, mock
    `KlassciProxyService::requestWithUserToken` sur `matieres` +
    `fetchManyMatieresDetails`) — cf. `LMSSeancesListResponseTest`.
  - **Lancer → voir RED** (`duree_minutes` non ajouté aujourd'hui).

## 2. Correction (GREEN)

- [ ] **2.1** Dans `app/Services/Seances/UpcomingSeancesFetcher::enrichWithVisio()`,
  remplacer le bloc durée `:244-251` par la lecture depuis `programmation.*`
  (extrait §2.1 du design) : `diffInMinutes(..., absolute: true)`, `(int)`,
  garde sur les 2 heures non nulles. Supprimer toute lecture racine morte
  (`date_seance` / `heure_debut` / `heure_fin`). _(REQ-1, REQ-2, REQ-3, REQ-5.)_
- [ ] **2.2** Vérifier que `Carbon` reste utilisé (import déjà présent) et que
  la méthode demeure ≤ 40 lignes, fichier ≤ 300 lignes.
- [ ] **2.3** Lancer le test 1.1 → **GREEN**.

## 3. Vérification globale

- [ ] **3.1** `php artisan test tests/Feature/LMS/Seances/` → 100 % (aucune
  régression du contrat manager ni des tests d'isolation tenant / N+1).
- [ ] **3.2** PHPStan level 9 sur le fichier touché → 0 erreur.
- [ ] **3.3** Garde de taille : `php scripts/check-file-sizes.php` sur le
  fichier modifié → OK.
- [ ] **3.4** Grep de contrôle : aucune occurrence de `['date_seance']`,
  `['heure_debut']`, `['heure_fin']` (racine) dans `enrichWithVisio`. _(REQ-5.)_

## 4. Clôture

- [ ] **4.1** Après merge PR : fermer #487 explicitement + récap
  (GitHub n'auto-ferme pas via « Closes #N » ici).

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (calcul bonne source) | 1.1a, 2.1, 2.3 |
| REQ-2 (résilience) | 1.1b, 2.1 |
| REQ-3 (type entier ≥0) | 1.1a, 2.1 |
| REQ-4 (non-régression contrat) | 1.1c, 3.1 |
| REQ-5 (pas de code mort) | 2.1, 3.4 |
