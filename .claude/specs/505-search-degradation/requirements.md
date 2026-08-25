# Requirements — #505 Drapeau de dégradation par source (recherche globale)

> Sous-issue de #496 · Sévérité LOW (UX trompeuse en dégradation partielle) · Branche `fix/505-search-degradation`

## Contexte mesuré (Phase 1)

`GlobalSearchService::aggregate()` agrège **5 sources** (`:116-123`) : `users`,
`lessons`, `evaluations` (base locale) + `classes`, `matieres` (**KLASSCI**).

Les deux sources KLASSCI avalent leur panne :

| Source | Preuve | Effet |
|---|---|---|
| `searchClasses` | `app/Services/Search/GlobalSearchService.php:226-232` | `catch (Throwable) → logger->error → return []` |
| `searchMatieres` | `app/Services/Search/GlobalSearchService.php:261-267` | idem |

Le payload (`:90-100`) n'expose que `results`, `total` et `categories` : **rien** ne
distingue « aucune classe ne correspond » de « KLASSCI est en panne ». Pire, le
résultat dégradé est mémorisé **5 minutes** (`:79-83`, `cache->remember`, TTL 300 s),
donc une panne d'une seconde continue de servir des buckets vides bien après le
rétablissement. `SearchController:64-70` relaie le payload tel quel.

**Nuance conservée de la vérification adversariale de #496** : ce n'est PAS « la
recherche renvoie silencieusement vide » — seuls 2 des 5 buckets tombent à 0, et
uniquement pour le personnel (gardes `:222` et `:257` : `isStaff()`).

## Exigences (EARS)

### R1 — Signalement par source

- **WHEN** une source KLASSCI échoue pendant une recherche, la réponse **SHALL**
  nommer cette source dans un drapeau `sources_failed`.
- **WHEN** toutes les sources aboutissent, `sources_failed` **SHALL** être présent
  et vide — un client ne doit pas avoir à distinguer « clé absente » de « aucune
  panne ».
- **WHERE** une source échoue, les buckets des autres sources **SHALL** rester
  intacts (dégradation partielle, pas panne totale).
- **IF** une source KLASSCI échoue, **THEN** le système **SHALL** journaliser
  l'erreur côté serveur **sans** exposer le détail technique au client (§1.2).

### R2 — Un résultat dégradé n'est jamais mis en cache

- **IF** au moins une source a échoué, **THEN** le système **SHALL NOT** écrire le
  résultat au cache.
- **WHEN** la recherche suivante survient après rétablissement de KLASSCI, elle
  **SHALL** renvoyer le résultat complet (aucune attente du TTL de 5 minutes).
- **WHEN** toutes les sources aboutissent, le résultat **SHALL** continuer d'être
  mis en cache 300 s (comportement inchangé).

### R3 — Contrat de réponse

- La réponse de `GET /api/search` **SHALL** conserver ses clés racine existantes
  (`success`, `query`, `results`, `total`, `categories`) et y **ajouter**
  `sources_failed`. Extension additive : aucun client lisant les clés connues
  n'est cassé.

### R4 — Aucune régression de périmètre

- Le système **SHALL** conserver les gardes de rôle existantes : `users` réservé
  aux managers, `classes`/`matieres` réservées au personnel.

## Hors périmètre (déclaré)

- `SearchSuggestionsService` : audité, **aucune source distante** — ses 3 sources
  (`users`, `lessons`, `evaluations`) sont locales et ne comportent aucun
  `catch → return []`. Rien à dégrader, donc aucune modification.
- `SearchHistoryService` : cache utilisateur, hors sujet.
- Le fait que `searchClasses`/`searchMatieres` itèrent la réponse KLASSCI BRUTE
  (`collect($allClasses)`) plutôt que sa clé `data` — anomalie distincte,
  **signalée** en fin de PR, non corrigée ici.
