# Plan d'unification de l'enveloppe JSON (#522, partie 2)

> **Statut : PLAN / conception.** Aucun changement de code dans cette PR — l'unification
> est un changement de contrat **cassant** qui exige une coordination frontend
> (cf. `docs/BREAKING_CHANGES_POLICY.md`). Ce document trace la cible et le chemin.

## 1. Problème (PRODUCTION_STANDARDS §1.5)

§1.5 exige un **format JSON identique pour tous les endpoints**. Or le trait
`app/Http/Controllers/Concerns/RespondsWithJson.php` reconnaît et **préserve
volontairement 3 formes concurrentes** (migration « DRY-only », axe #1) :

| Forme | Exemple d'endpoint |
|---|---|
| `{ success, data }` (sans `message`) | index / show / getByChapter |
| `{ success, message }` (sans `data`) | destroy |
| `{ success, message, data }` | store / update |

Règles actuelles du trait :
- Succès : `{ "success": true, "message"?, "data"?, "meta"? }` — `message` omis si `''`, `data` omis si `null`, `meta` omis si vide.
- Erreur : `{ "success": false, "message", "errors"? }` — `errors` omis si vide.

Le client doit donc gérer l'**absence** possible de `data`/`message` selon l'endpoint → contrat non uniforme.

## 2. État actuel (ne pas casser)

- Le trait `RespondsWithJson` **centralise déjà la construction** (DRY) sans imposer une forme unique — c'est un prérequis sain, à conserver.
- Un chantier de migration des controllers vers ce trait est **en cours** (branche `work-axe1-cleanup`, PRs #281/#284…). L'unification doit **rider dessus**, pas le doubler.
- Son docblock acte explicitement : « L'uniformisation du contrat (toujours les 3 clés) est un chantier distinct nécessitant une coordination frontend ».

## 3. Cible — enveloppe à clés FIXES

Toujours les mêmes clés, présentes même si vides (plus d'omission conditionnelle) :

```jsonc
// Succès
{ "success": true,  "message": "",   "data": null, "meta": {} }
// Erreur
{ "success": false, "message": "…",  "errors": {} }
```

- `data` : `null` (pas absent) quand il n'y a pas de payload.
- `message` : `""` (pas absent) quand il n'y en a pas.
- `meta` : `{}` (pagination/compteurs) — clé toujours présente.
- Erreur : `errors` toujours présent (`{}` si pas d'erreurs de champ).

Bénéfice client : un seul type de réponse à typer (TS/SDK), zéro branche « la clé existe-t-elle ? ».

## 4. Chemin de migration (incrémental, non cassant jusqu'au switch)

1. **Geler la forme cible** ici + dans le SDK (types générés) — fait par ce document.
2. **Tests de caractérisation** par endpoint AVANT tout changement (déjà le pattern du chantier axe #1, ex. `KnowledgeCheckCrudResponseTest`) : verrouiller la forme actuelle, puis la faire évoluer sciemment.
3. **Ajout additif** : introduire les clés manquantes (`data: null`, `message: ""`, `meta: {}`) **sans retirer** les formes courtes → le client actuel continue de fonctionner (il ignore les clés en trop).
4. **Coordination frontend** : publier la forme cible, laisser le front migrer sa lecture (via `docs/BREAKING_CHANGES_POLICY.md` + versioning `docs/API_VERSIONING.md`).
5. **Bascule** : une fois le front prêt, `successResponse()` émet toujours les 3 clés (retrait de l'omission conditionnelle). Un seul point de changement (le trait) grâce à la centralisation déjà en place.
6. **Garde-fou** : un test transverse asserte que TOUTE réponse d'API expose exactement le jeu de clés cible (anti-régression).

## 5. Pourquoi pas maintenant

- **Cassant** : retirer l'omission de `data`/`message` change le JSON vu par des clients existants → doit passer par la politique de breaking changes + une version d'API.
- **Dépendance** : le chantier `work-axe1-cleanup` doit d'abord finir de router tous les controllers via `RespondsWithJson` (sinon l'unification ne couvre pas tout).
- **Périmètre #522** : l'issue demande de **planifier** (ce document), les parties « enum » et « observer » étant, elles, livrées en code dans la même PR.

## 6. Prochaines actions (hors #522)

- [ ] Finir la migration des controllers vers `RespondsWithJson` (`work-axe1-cleanup`).
- [ ] Ouvrir une issue « API v-next : enveloppe à clés fixes » référencant ce plan.
- [ ] Générer les types SDK sur la forme cible et coordonner la bascule front.
