# Checklist pour surveiller Claude — KLASSCI LMS

> **Pour le user (non-développeur).** Garde cette page ouverte. À chaque interaction avec Claude, vérifie qu'il respecte ces règles. Si une violation → dis-le-lui immédiatement.
>
> Cette checklist est la version courte. Les règles complètes sont dans [`PRODUCTION_STANDARDS.md`](../PRODUCTION_STANDARDS.md) et [`CONTRIBUTING.md`](../CONTRIBUTING.md).

---

## 1. Comportement en chaque message

À cocher dans CHAQUE réponse de Claude :

- [ ] **UNE seule solution proposée** (pas A/B/C, pas de « tu préfères X ou Y ? »)
- [ ] **Justification de la solution citée** (« per §1.6 », « per `best-not-fastest` », etc.)
- [ ] **Pas de bluff** : chaque « best practice » pointe vers une source (doc/RFC/benchmark)
- [ ] **Honnêteté sur les compromis** : si dette technique, il la nomme explicitement
- [ ] **Pas de « tu as raison j'ai oublié »** : si cette phrase apparaît, c'est qu'il a sauté un test/vérification

### Phrases à challenger immédiatement

Si Claude écrit l'une de ces phrases, **arrête-le** et demande qu'il relise les règles :

| Phrase de Claude | Pourquoi c'est une violation |
|---|---|
| « On pourrait faire A ou B » | Viole §6 PRODUCTION_STANDARDS |
| « Best practice 2025 » sans source | Viole Q14 |
| « Pas d'erreurs / ça compile » présenté comme « validé » | Viole Q3 5-questions |
| « Tu as raison, j'avais oublié » | A sauté Phase 4 ou un test |
| « Veux-tu que je [push / commit / merge] ? » | OK si demande d'accord, suspect si déjà fait |
| « Je pense que… » sans citer une règle | Drift hors framework |

---

## 2. Avant chaque tâche (5 questions à lui poser)

Si tu doutes, pose-lui ces 5 questions. Il DOIT répondre **avant** de coder :

1. **C'est vraiment la solution ?** (racine, pas symptôme)
2. **As-tu fait des recherches avancées ?** (3+ sources externes)
3. **As-tu des preuves que c'est robuste ?** (chiffres, pas estimations)
4. **Viable pour un projet 10+ ans / 200k users ?** (projection explicite)
5. **As-tu suivi la procédure des règles ?** (cite les sections appliquées)

Les 5 questions doivent passer **AVANT, PENDANT, APRÈS** chaque tâche.

---

## 3. Avant chaque commit / push

- [ ] `php artisan test` = **100% PASS** (sinon Phase 4 violée)
- [ ] Aucun fichier > 300 lignes
- [ ] Aucun `$e->getMessage()` exposé au client
- [ ] Aucun token / secret en clair
- [ ] Aucun endpoint sans `auth:sanctum`
- [ ] Commit message explique le **POURQUOI** (pas le « QUOI »)
- [ ] Claude a demandé ton **accord explicite** avant `git commit` / `git push`
- [ ] Branche correcte : `fix/*` (hotfix) / `feature/*` (feature) / `docs/*` (doc) / `chore/*` (maintenance) — **jamais commit direct sur `lms`**

---

## 4. Les 15 questions self-critique (avant chaque PR)

Si UNE réponse = non → la PR ne doit pas être créée.

1. Résout la racine ? (pas symptôme)
2. Pas de nouveau problème créé ailleurs ?
3. Tests happy path + edge cases ?
4. Un senior approuverait ?
5. Code en moins possible (pas de dup) ?
6. Noms auto-documentés ?
7. Aucun secret plaintext ?
8. Aucun N+1 ?
9. Commentaires sur les « pourquoi non-évidents » ?
10. Erreurs gérées sans exposer le détail ?
11. **Meilleure archi ou la plus rapide ?** Si rapide → justifier sinon refaire
12. **2 alternatives écartées listées** avec raison ?
13. **À 10× volume (200k users), tient ?** Projection chiffrée
14. **Source citée ou bluff ?** Doc/RFC/benchmark
15. **Critère d'invalidation explicite ?** Sinon dogmatique

---

## 5. Workflow Git que Claude doit respecter

| Action | Règle |
|---|---|
| Toute modification | Branche locale → commit → push → PR vers `lms` |
| Édition prod (cPanel) | **INTERDIT.** Toujours via git pull sur serveur après merge |
| Commit / push | **Accord explicite** du user à chaque fois |
| Test échoué | **Le fixer.** Pas le supprimer, pas le `skip`. |
| Fichier > 300 lignes | **Refactoring obligatoire** avant merge |

---

## 6. Limites architecturales

Si Claude crée ou modifie un fichier, vérifie la taille :

| Type | Limite |
|---|---|
| Controller | 200 lignes max |
| Service | 300 lignes max |
| Modèle | 150 lignes max |

---

## 7. Si Claude viole une règle

1. **Stoppe-le.** Dis « tu viole [règle X] »
2. **Demande-lui de relire** : `PRODUCTION_STANDARDS.md` + `CONTRIBUTING.md` + ses mémoires
3. **Demande-lui de re-proposer** avec la règle appliquée correctement
4. Si récurrent : ajoute une nouvelle règle à sa mémoire dans `MEMORY.md`

---

## 8. Engagement non-négociable du projet

> **« On choisit toujours la meilleure solution architecturale, jamais la plus rapide. »**
>
> Conséquences :
> - 10 fichiers au lieu de 2 si l'architecture le demande
> - 5 vérifications au lieu d'une intuition
> - Pas de prototype en production
> - Pas de raccourci pour gagner du temps
> - En cas de doute → ralentir, vérifier, demander

---

**Version** : 1.0 — créée le 2026-05-15
**Source** : [`PRODUCTION_STANDARDS.md`](../PRODUCTION_STANDARDS.md), [`CONTRIBUTING.md`](../CONTRIBUTING.md), mémoire Claude
