# ADR-803-02 — La validation est atomique et délivre un lien d'activation à usage unique

**Date :** 2026-09-15  
**Statut :** accepté  
**Issue :** #803 (backend) · dépend de #793

## Décision

Quand le supradmin plateforme valide une demande, **une seule transaction** produit :

1. l'`Institution`, avec `klassci_api_url = NULL` — autonome par construction ;
2. le premier `User`, `role = superAdmin`, rattaché à cette institution ;
3. le passage de la demande en `validee`, reliée à l'`institution_id` créé.

Si l'une des trois échoue, **aucune** n'est écrite.

**Aucun mot de passe n'est choisi, transmis ni affiché.** La transaction émet un **lien d'activation à usage unique**, affiché au supradmin, qu'il transmet hors bande (WhatsApp, SMS, de vive voix). Le titulaire pose son mot de passe lui-même.

Le jeton d'activation :

- est **à usage unique** — une colonne `consomme_le` le prouve, l'expiration seule ne suffit pas ;
- **expire** — durée courte, portée en configuration, jamais en dur ;
- est stocké **haché**, jamais en clair ;
- ne révèle rien sur le compte cible dans son URL.

La règle `UniqueEmailInInstitution` reçoit l'institution cible **explicitement**, par une sœur de `forCreationBy(?User $acteur)` — l'appel actuel est en `CreateUserRequest.php:46` — prenant l'`Institution` en argument. Le comportement *fail-closed* n'est pas assoupli.

Le même mécanisme d'activation s'applique aux comptes créés par import — `app/Services/Import/ImportApplyService.php:69`.

## Pourquoi

### L'atomicité n'est pas du confort, c'est le défaut #793

Aujourd'hui, `InstitutionCrudService::create` (`app/Services/Institution/InstitutionCrudService.php:46-49`) fait `Institution::create($validated)` et **rien d'autre**. L'institution naît vide, et aucun chemin ne permet d'y créer le premier compte :

- `POST /api/users` (`routes/api/core.php:123`) n'accepte pas `institution_id` (`app/Http/Requests/CreateUserRequest.php:44-50` — les cinq règles sont `name`, `email`, `password`, `role`, `klassci_id`) ;
- le supradmin plateforme a `institution_id` à `NULL` (`database/seeders/SupradminSeeder.php:45`) et `ResolveInstitution` ne lui pose jamais de tenant (`app/Http/Middleware/ResolveInstitution.php:121-123`) ;
- `UniqueEmailInInstitution` refuse alors en 422 (`:97-99`).

Une validation en deux temps reproduirait exactement cet état : une école que personne ne peut habiter. L'atomicité est la définition même du correctif.

### La hiérarchie autorise déjà cette création

`app/Rules/AssignableRole.php` n'autorise qu'un rôle **strictement moins permissif** que celui de l'acteur. L'échelle (`app/Enums/Role.php:97-106`) place `Supradmin` à 6 et `SuperAdmin` à 5 : **6 > 5**, la création passe. Aucune règle nouvelle, aucun assouplissement.

Ce qui manque n'est pas l'autorisation, c'est la **désignation de l'institution cible**. D'où la sœur explicite plutôt qu'un contournement de la règle : `forCreationBy` dérive l'institution de l'acteur, ce qui est correct pour un coordinateur mais indéterminé pour un supradmin plateforme. Lui passer la cible restaure le déterminisme sans ouvrir de brèche.

### Pourquoi un lien plutôt qu'un mot de passe transmis

Il n'existe **aucun canal de courriel** : `config/mail.php:17` vaut `log`, et `app/` ne contient ni `Mail::` ni `->notify()`. Le mot de passe devrait donc circuler de vive voix ou par messagerie. Trois raisons de refuser :

1. un mot de passe dicté ou collé dans WhatsApp **reste valide indéfiniment** — la conversation le conserve ;
2. un lien consommé ou expiré **cesse d'être dangereux**, ce qu'un mot de passe ne fait jamais ;
3. le titulaire choisit son secret : personne d'autre, jamais, ne l'a connu.

Et surtout : le jour où le courriel existera, **le même jeton part par mail**. Le transport change, le mécanisme non. Choisir le mot de passe transmis obligerait à tout réécrire à ce moment-là.

### L'usage unique exige une colonne, pas seulement une expiration

Une URL signée expirante reste rejouable autant de fois qu'on veut pendant sa fenêtre de validité. Si le lien transite par une messagerie de groupe, plusieurs personnes peuvent l'ouvrir. Seule une marque de consommation en base garantit qu'un second usage échoue.

### Le même trou existe déjà pour les étudiants

`ImportApplyService.php:69` écrit `'password' => Str::password(16)`. Le cast `'password' => 'hashed'` (`app/Models/User.php:58`) garantit qu'il n'est pas stocké en clair — il n'y a pas de fuite. Mais ce mot de passe **n'est communiqué à personne** : chaque étudiant importé possède un compte auquel il ne peut pas accéder.

Traiter l'activation comme un mécanisme générique, et non comme une étape du parcours d'inscription d'école, répare les deux cas avec un seul objet.

## Conséquences

- Une table de jetons d'activation, ou des colonnes dédiées sur `users` — à trancher à l'implémentation, avec `consomme_le` dans les deux cas.
- `UniqueEmailInInstitution` gagne un constructeur nommé ; `tests/Unit/Rules/UniqueEmailInInstitutionTest.php` doit prouver que le *fail-closed* d'origine survit.
- Un test doit prouver l'atomicité : provoquer l'échec de l'étape 2 et vérifier qu'**aucune** institution n'a été écrite.
- Le refus d'une demande exige un `motif_refus` ; il ne crée ni institution ni compte.
- #793 devient un sous-ensemble de cet ADR : la voie de création du premier compte est celle décrite ici.
