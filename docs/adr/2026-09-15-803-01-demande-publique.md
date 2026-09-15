# ADR-803-01 — La demande d'inscription est publique et ne crée qu'une ligne

**Date :** 2026-09-15  
**Statut :** accepté  
**Issue :** #803 (backend)

## Décision

Un endpoint **non authentifié** accepte une demande d'ouverture d'école. Il écrit **une seule ligne** dans `school_registration_requests`, table **hors périmètre multi-tenant**.

Cet endpoint ne crée **jamais** :

- de `User` — aucun compte, aucun jeton, aucune session ;
- d'`Institution` — aucun tenant, même inactif ;
- de réservation de `slug` — le demandeur exprime un `slug_souhaite`, il n'en obtient aucun.

La ligne écrite est **inerte** : aucun scope global ne la lit, aucun middleware ne la résout, aucune route authentifiée ne la traverse. Elle n'est visible que du supradmin plateforme.

Colonnes :

| Colonne | Rôle |
|---|---|
| `nom_demandeur`, `email_demandeur`, `telephone_demandeur` | le futur SuperAdmin |
| `nom_ecole`, `slug_souhaite` | souhaité, jamais réservé |
| `usage_prevu` | texte libre obligatoire |
| `statut` | `en_attente` \| `validee` \| `refusee` |
| `decide_par_user_id`, `decide_le`, `motif_refus` | traçabilité de la décision |
| `institution_id` | `NULL` jusqu'à la validation |

Protection : `throttle` par IP, selon la convention déjà en place sur les routes non authentifiées (`routes/api/core.php:47-51` — `throttle:10,1` au login, `throttle:5,1` sur le mot de passe).

Unicité : une seule demande `en_attente` par `email_demandeur`. Une seconde soumission met à jour la première au lieu d'en créer une deuxième.

## Pourquoi

### Ce n'est pas le `register` que le plan interdit

`docs/PLAN_AUTONOMIE_KLASSCI.md:193` interdit un `POST /auth/register` public. Cette interdiction vise la création publique de **comptes** sur un tenant existant — y compris les écoles KLASSCI, où un inconnu pourrait ainsi se fabriquer un accès.

Une **demande** n'est pas un compte. Elle ne confère aucun droit, ne s'authentifie pas, et ne devient rien tant qu'un humain n'a pas décidé. La décision est donc conforme au plan, pas dérogatoire.

Le même document présuppose au contraire l'ouverture d'espace : `:16` — « un formateur ou un organisme qui **ouvre un espace** » — et `:228` — « Créer un espace | Gratuit, toujours ». Le plan avait tranché l'intention sans trancher le mécanisme.

### La bonne question n'est pas « public ou fermé »

C'est **ce que l'endpoint public a le droit de créer**. Puisqu'il n'écrit qu'une ligne inerte hors tenancy, le pire scénario est du spam, pas une compromission. Un formulaire fermé ne réduirait pas ce risque — il est déjà nul — mais il ferait de l'exploitant le goulot d'étranglement pour simplement *savoir* que quelqu'un veut entrer.

### Pourquoi pas une Institution « en attente »

Créer l'`Institution` dès la demande, avec `is_active = false`, paraît plus simple. C'est un piège :

- elle occupe immédiatement un `slug` unique — n'importe qui squatte « esbtp » ou « ifad » sans rien prouver ;
- elle entre dans le graphe multi-tenant : chaque scope global, chaque middleware de résolution et chaque requête d'administration doivent désormais connaître cet état intermédiaire ;
- un refus laisse une institution fantôme à nettoyer, ou une contrainte d'unicité durablement consommée ;
- les données du **demandeur** — contact, téléphone, usage prévu — n'appartiennent pas à un établissement et n'ont aucune colonne où vivre.

### L'anti-spam sans courriel

`config/mail.php:17` fixe `MAIL_MAILER` à `log` par défaut, et `app/` ne contient **aucun** `Mail::` ni `->notify()` : le modèle `Notification` du projet est une table lue dans l'application, pas un courriel. La vérification d'adresse est donc **techniquement indisponible aujourd'hui**.

Trois mesures compensent, dans l'ordre d'efficacité :

1. **le supradmin est le filtre** — le verrou humain est déjà au cœur du mécanisme ;
2. `usage_prevu` obligatoire et téléphone — un spam automatisé coûte plus cher qu'un POST vide ;
3. `throttle` par IP.

Le jour où un canal de courriel existera, la vérification d'adresse s'ajoute comme **pré-filtre** avant la file du supradmin. L'architecture ne bouge pas : elle gagne un étage. C'est ce qui rend cette décision durable.

## Conséquences

- Nouvelle table `school_registration_requests`, **à ne pas inscrire** dans `config/tenancy.php`.
- Nouvelle route publique — la seule écriture non authentifiée du système. Elle doit rester étroite : un `FormRequest`, un service, une table.
- La validation fait l'objet d'un ADR distinct : [ADR-803-02](2026-09-15-803-02-validation-atomique.md).
- Aucune colonne `mode` n'est introduite : le discriminant reste `klassci_api_url IS NULL` (garde OCP `scripts/check-ocp.php`, CI `.github/workflows/security.yml:505`).
