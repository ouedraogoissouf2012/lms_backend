# Requirements — Fenêtre d'évaluation fail-closed sur échec KLASSCI (#499)

## Contexte & preuves

`EvaluationAttemptStateService` vérifie la fenêtre temporelle KLASSCI d'une
évaluation en **fail-open** :

- `fetchWindowSafe` (`:141-151`) : sur exception KLASSCI (timeout/panne réseau) →
  `Log::warning` → **`return null`**.
- `checkWindow` (`:157-161`) : `if (!$window || $window['is_open'] || $isPracticeMode) return null;`
  → `null` ⇒ **aucune erreur de fenêtre** ⇒ tentative autorisée.

**Deux cas distincts produisent `$window = null`** :
1. **Légitime** : KLASSCI répond, l'évaluation n'a **pas** de fenêtre configurée →
   « toujours ouverte » → démarrer est **correct**.
2. **Buggé (fail-open)** : l'appel KLASSCI **échoue** → catch → `null` → traité
   comme « aucune restriction » → l'étudiant démarre une évaluation dont la
   fenêtre pourrait être **fermée**.

Chemin : `POST /api/evaluations/{id}/start` → `startEvaluation`
(`EvaluationStudentAttemptController:34-67`) → `startAttempt:43` →
`fetchWindowSafe:61` + `checkWindow:63`. Le statut `'ok'` crée une
`EvaluationSubmission 'en_cours'` (`:95`) et renvoie 200.

Déclencheur = panne KLASSCI (non contrôlable par l'attaquant), d'où MEDIUM ; mais
un **contrôle d'accès temporel fail-open** est un défaut de sécurité fonctionnelle.

## Portée

- **IN** : distinguer « échec de récupération » de « pas de fenêtre » ; faire
  échouer **fermé** (fail-closed) le `start` quand la fenêtre n'a pas pu être
  vérifiée, hors mode entraînement ; nouveau statut + mapping HTTP.
- **OUT** : `getTimeStatus` (endpoint informatif read-only, reste tolérant au
  null) ; la logique de fenêtre elle-même (`has_started`/`has_ended`) inchangée ;
  le mode entraînement (`isTerminee`) reste permissif (pas de fenêtre appliquée).

## Exigences (EARS)

**REQ-1 — Distinguer échec vs absence de fenêtre**
THE SYSTEM SHALL distinguer, à la récupération de la fenêtre KLASSCI, le cas
« appel échoué » du cas « appel réussi sans fenêtre configurée ».

**REQ-2 — Fail-closed sur échec (hors entraînement)**
WHEN le `start` d'une évaluation ne peut PAS vérifier la fenêtre KLASSCI (appel
en échec) ET que l'évaluation n'est PAS en mode entraînement, THE SYSTEM SHALL
**refuser** le démarrage (aucune `EvaluationSubmission` créée) avec un statut
dédié `window_check_failed`.

**REQ-3 — Mapping HTTP transitoire**
THE SYSTEM SHALL mapper `window_check_failed` sur **HTTP 503** (dépendance
KLASSCI indisponible — transitoire, réessayer), avec un message clair invitant à
réessayer.

**REQ-4 — Absence légitime de fenêtre inchangée**
WHEN l'appel KLASSCI **réussit** mais l'évaluation n'a pas de fenêtre configurée
(window absente), THE SYSTEM SHALL continuer d'autoriser le démarrage (comportement
actuel préservé — ce n'est pas un échec).

**REQ-5 — Fenêtre fermée inchangée**
WHEN l'appel réussit et la fenêtre est **fermée** (`has_ended`/`!has_started`),
THE SYSTEM SHALL continuer de renvoyer `window_closed` (403) — comportement actuel.

**REQ-6 — Mode entraînement permissif**
WHERE l'évaluation est en mode entraînement (`isTerminee()`), THE SYSTEM SHALL
autoriser le démarrage même si la fenêtre n'a pas pu être vérifiée (pas de
fenêtre appliquée en entraînement — comportement actuel).

**REQ-7 — getTimeStatus inchangé**
THE SYSTEM SHALL préserver le comportement de `getTimeStatus` (informatif) :
sur échec, `window: null` toléré, pas d'exception.

## Critères d'acceptation

1. Panne KLASSCI (mock qui lève) au `start` d'une éval **non-entraînement** →
   HTTP **503**, **aucune** `EvaluationSubmission` créée.
2. Panne KLASSCI au `start` en **mode entraînement** → démarrage autorisé (200).
3. KLASSCI OK + éval **sans fenêtre** → démarrage autorisé (200) (REQ-4).
4. KLASSCI OK + fenêtre **fermée** → **403** `window_closed` (REQ-5, non-régression).
5. KLASSCI OK + fenêtre **ouverte** → démarrage autorisé (200).
6. `php artisan test` 100 %, PHPStan level 9 vert, garde tailles OK.

## Q15 — Critères d'invalidation

- ❌ Confondre « window null car pas de fenêtre » (autoriser) et « window null car
  échec » (refuser) → le fix doit les séparer nettement.
- ❌ Faire échouer fermé le mode entraînement (sur-correction — l'entraînement n'a
  pas de fenêtre).
- ❌ Casser `getTimeStatus` (le rendre bloquant).
- ❌ Régresser `window_closed` (403) ou l'absence légitime de fenêtre (200).
- ❌ Mapper l'échec KLASSCI en 403 « interdit » (trompeur : ce n'est pas un refus
  d'accès mais une indisponibilité — 503 transitoire).
