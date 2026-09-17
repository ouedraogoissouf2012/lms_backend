# ADR-796-01 — Le login local désambiguïse par le mot de passe, sous plafond

**Date :** 2026-09-17  
**Statut :** accepté  
**Issue :** #796 · épique #805

## Décision

`LocalLmsAuthenticator::attemptLocalAuth()` ne prend plus « le premier trouvé ». Il :

1. cherche d'abord les comptes dont l'**email** vaut l'identifiant ; s'il n'y en a aucun, ceux dont le **nom** le vaut ;
2. refuse d'emblée si le nombre de candidats dépasse `MAX_CANDIDATS = 3` ;
3. vérifie le mot de passe contre **chaque** candidat retenu ;
4. n'authentifie que si **exactement un** correspond. Zéro correspondance → `null`. Plus d'une → `null`, et un avertissement journalisé.

La recherche reste **inter-institution** (`withoutGlobalScope`). Ce n'est pas un oubli : c'est la conception, et elle est épinglée par `test_finds_user_cross_institution_via_without_global_scope`.

## Pourquoi

### Le défaut

```php
// avant — LocalLmsAuthenticator.php:57-62
User::withoutGlobalScope('institution')
    ->where(fn ($q) => $q->where('email', $identifier)->orWhere('name', $identifier))
    ->first();
```

En monde KLASSCI, l'unicité venait de l'amont. Elle n'existe plus :

- `users.name` n'a **aucune** contrainte d'unicité ;
- `users.email` n'est unique que **par institution** (`users_email_institution_unique`).

**Aucun identifiant n'est donc globalement unique.** `->first()` teste une seule ligne choisie par le hasard de l'ordre SQL : le second homonyme ne peut jamais se connecter, sans le moindre message qui l'explique.

Ce n'est pas un contournement d'authentification — `Hash::check` reste exigé sur la ligne trouvée — mais un **déni de service silencieux**. Et il est provocable : `ImportApplyService` crée des comptes dont le `name` vaut `"$prenom $nom"`, si bien qu'un import dans un établissement peut fabriquer la collision qui bloque un utilisateur d'un autre.

### Pourquoi pas « email seul »

C'est la première option proposée par #796, et le code la réfute : le login par nom est réellement utilisé. Les tests envoient aussi bien `marcel.ouedraogo` que `teacher@klassci.test`, et `test_returns_user_when_name_matches_and_password_correct` l'épingle. Supprimer cette branche casserait des connexions qui fonctionnent.

### Pourquoi pas « identifiant + institution »

C'est la seconde option. Elle exigerait que `POST /login` porte `X-Institution`, ce qu'il ne réclame pas — contrairement à `/forgot-password` et `/reset-password`, qui agissent DANS un établissement déjà connu. Au login, le tenant n'est pas encore résolu, et le supradmin n'appartient à aucune institution. Ce serait un changement de contrat front compris, pour un défaut qui se corrige sans.

### Pourquoi le mot de passe est le bon départageur

C'est la seule information que seul le bon compte possède. L'identifiant est ambigu par construction ; le secret ne l'est pas. Départager par le mot de passe rend au second homonyme sa connexion **sans rien relâcher** : chaque candidat est vérifié avec la même exigence qu'avant.

### Pourquoi refuser quand deux candidats correspondent

Authentifier « l'un des deux » revient à authentifier personne en particulier : la session porterait une identité **devinée**, et dans un système multi-tenant elle ouvrirait les données d'un établissement à quelqu'un d'un autre. Le cas exige un identifiant **et** un mot de passe identiques entre deux comptes — assez rare pour que le refus soit acceptable, assez grave pour qu'il soit obligatoire.

### Pourquoi un plafond, chiffré

Vérifier N mots de passe, c'est N calculs bcrypt. Mesuré sur la machine de développement, **442 ms par vérification** (bcrypt, coût 12 par défaut). `POST /login` est limité à `throttle:10,1` :

| Plafond | CPU par minute et par IP |
|---|---|
| aucun, 100 homonymes fabriqués | **442 s** — une IP sature plusieurs cœurs |
| 3 | 13 s |
| 1 (comportement actuel) | 4,4 s |

Sans plafond, quiconque peut créer des comptes — un import suffit — transforme le login en amplificateur CPU. Le plafond le borne.

**`MAX_CANDIDATS = 3`** : une collision légitime en compte deux (la même personne dans deux établissements) ; trois laisse une marge sans ouvrir l'amplification.

**Le plafond ne peut pas régresser par rapport à l'existant.** Aujourd'hui, sur N comptes partageant un identifiant, **un seul** peut se connecter. Avec le plafond, les trois premiers le peuvent, et au-delà personne. C'est strictement meilleur qu'avant pour N ≤ 3, et jamais pire pour N > 3.

## Conséquences

- Le chemin nominal — un seul compte porte l'identifiant — reste à **une** vérification bcrypt. Chercher l'email en premier garantit que la collision ne coûte que dans le cas où elle existe vraiment.
- Une ambiguïté refusée est **journalisée** (`warning`), sans l'identifiant en clair : plusieurs comptes partageant un identifiant est un signal d'exploitation autant qu'un accident.
- Le message rendu à l'utilisateur ne change pas : `attemptLocalAuth` rend `null`, et l'orchestrateur décide. Distinguer « introuvable » de « ambigu » côté client offrirait un oracle d'énumération.

## Limite connue

Un utilisateur au-delà du plafond reste bloqué, et rien dans l'interface ne le lui dit — la journalisation est côté serveur. C'est l'état d'aujourd'hui pour tout le monde sauf le premier ; ce lot le réduit sans le supprimer. Le supprimer vraiment demanderait une unicité d'identifiant à l'échelle de la plateforme, ou le passage de l'institution au login : deux changements de contrat qui dépassent ce correctif.
