# Design — `duree_minutes` sur le listing « séances à venir » (#487)

## 1. Diagnostic figé (preuves `fichier:ligne`)

- `UpcomingSeanceMapper::mapRow()`
  (`app/Services/Seances/UpcomingSeanceMapper.php:50-79`) produit les datetimes
  **exclusivement** sous `programmation.heure_debut` / `programmation.heure_fin`
  (via `SeanceProgrammationNormalizer::alignDate`), et `programmation.date`.
  **Aucune** clé racine `date_seance` / `heure_debut` / `heure_fin`.
- `UpcomingSeancesFetcher::enrichWithVisio()`
  (`app/Services/Seances/UpcomingSeancesFetcher.php:244-251`) lit ces 3 clés
  **à la racine** → garde toujours fausse → `duree_minutes` jamais ajouté.
- `SeanceProgrammationNormalizer::alignDate()`
  (`:32-45`) renvoie un **datetime ISO complet** (`YYYY-MM-DDThh:mm:ss`), PAS un
  `hh:mm:ss`. ⇒ reconcaténer `date . ' ' . heure` produirait une double date.
- Référence correcte : `SeanceDetailQueryService:90-92` parse directement
  `programmation.heure_debut/heure_fin` **sans** reconcaténation.

## 2. Solution (unique)

Réécrire le bloc durée de `enrichWithVisio` pour :
1. lire `programmation.heure_debut` et `programmation.heure_fin` (sous-tableau) ;
2. parser chaque valeur telle quelle (datetime ISO déjà aligné) ;
3. calculer la durée en minutes, **non négative**.

### 2.1 Extrait cible

```php
// Durée : les heures de programmation sont des datetimes ISO déjà alignés sur
// la date de séance (UpcomingSeanceMapper + SeanceProgrammationNormalizer),
// on les parse tels quels — cohérent avec SeanceDetailQueryService.
$prog = KlassciPayload::asArray($seance['programmation'] ?? null);
$heureDebutRaw = KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null);
$heureFinRaw = KlassciPayload::toStringOrNull($prog['heure_fin'] ?? null);
if ($heureDebutRaw !== null && $heureFinRaw !== null) {
    $debut = Carbon::parse($heureDebutRaw);
    $fin = Carbon::parse($heureFinRaw);
    $seance['duree_minutes'] = (int) $debut->diffInMinutes($fin, absolute: true);
}
```

### 2.2 Décisions & justifications

| Décision | Pourquoi |
|---|---|
| Lire `programmation.*`, pas la racine | Seule source réellement produite par le mapper (§1). Corrige REQ-1/REQ-5. |
| Parser sans reconcaténer la date | `alignDate` renvoie déjà un datetime complet ; reconcaténer casserait le parse (Q15). |
| `diffInMinutes(..., absolute: true)` | Garantit `duree_minutes ≥ 0` (REQ-3, Q15 « pas de négatif »). Le détail n'utilise pas `absolute`, mais REQ-1 l'exige explicitement pour blinder le contrat client ; divergence mineure **assumée et tracée** ici (le détail suppose des données ordonnées, le listing walk KLASSCI est moins garanti). |
| `(int)` explicite | `diffInMinutes` renvoie `float` en Carbon 3 → REQ-3 (entier). |
| Réutiliser `KlassciPayload::asArray/toStringOrNull` | Cohérent avec tout le fichier (narrowing PHPStan level 9, pas de cast aveugle). |
| Garde inchangée sur données partielles | REQ-2 : si une heure manque, pas de clé, pas d'exception. |

## 3. Impact & non-régression

- **Sortie JSON** : ajoute la clé `duree_minutes` (int) sur le chemin
  non-manager quand les 2 heures sont présentes. Aucune autre clé touchée
  (REQ-4).
- **Chemin manager** : `fetchForManager` retourne AVANT `enrichWithVisio`
  (`:85`) → **inchangé**, ne reçoit toujours pas `duree_minutes` (OUT scope).
- **Frontend** : audit `lms-frontend` → aucune vue ne lit `seance.duree_minutes`
  sur le listing « à venir » (seuls `evaluation.duree_minutes` existent). L'ajout
  est donc **additif non cassant** (le frontend ignore une clé qu'il ne lit pas).
- **PHPStan** : `Carbon` déjà importé ; `KlassciPayload` déjà utilisé ; typage
  `(int)` explicite → 0 erreur attendue.

## 4. Fichiers touchés

| Fichier | Nature |
|---|---|
| `app/Services/Seances/UpcomingSeancesFetcher.php` | Réécriture du bloc durée dans `enrichWithVisio` (méthode reste ≤40 lignes). |
| `tests/Feature/LMS/Seances/UpcomingSeancesDureeMinutesTest.php` (NEW) | Test de contrat (REQ-1) + résilience (REQ-2). |

Aucun nouveau collaborateur : le changement est **local** à une méthode, pas une
extraction (le fichier reste sous 300 lignes, la méthode sous 40).
