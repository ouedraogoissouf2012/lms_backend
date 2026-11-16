# ADR-687-01 — Un échec de login local coûte une vérification, que le compte existe ou non

**Date :** 2026-11-16  
**Statut :** accepté  
**Issue :** #687 (critère « temps de réponse identique »), amende ADR-796-01

## Décision

Quand `LocalLmsAuthenticator::attemptLocalAuth()` n'a fait **aucune** vraie vérification de mot de passe, il en fait **une** contre une empreinte leurre (`App\Services\Auth\EmpreinteLeurre`) et ignore son résultat. Trois chemins sont concernés :

1. aucun compte ne porte l'identifiant ;
2. des comptes le portent, mais aucun n'a d'empreinte ;
3. le plafond de l'ADR-796-01 est dépassé.

L'empreinte leurre est celle d'un secret aléatoire aussitôt jeté. Elle est **gardée dans le cache applicatif** (clé `auth:empreinte-leurre`), et refaite quand `Hasher::needsRehash()` dit que le coût configuré a changé. Si le cache est en panne, elle est calculée à la volée et la panne est journalisée (`CaughtExceptionLog`). Le login ne casse jamais pour elle.

## Pourquoi

### Le défaut : le chronomètre disait si le compte existait

| Cas | Vérifications bcrypt avant | Après |
|---|---|---|
| identifiant inconnu | **0** | 1 (leurre) |
| compte local, mot de passe faux | 1 | 1 |
| candidats sans empreinte | **0** | 1 (leurre) |
| plafond dépassé | **0** | 1 (leurre) |

Mesuré le 2026-09-26 sur le chemin complet du login, au coût 12 : **~23 ms** pour un identifiant inconnu, **~519 ms** pour un compte existant avec un mauvais mot de passe. Tout compte KLASSCI déjà connecté existe en local, avec une empreinte aléatoire (`KlassciUserSynchronizer`, `hasher->make(uniqid())`). Le **supradmin**, compte purement local, s'énumérait de la même façon. En production, la gigue de KLASSCI (1 à 4 s) brouille la mesure sans la fermer : il suffit de moyenner, à 10 essais par minute (`throttle:10,1`).

### Pourquoi un leurre, et pas un plancher de durée (`Timebox`)

`Illuminate\Support\Timebox` garantit une durée minimale. Pour fermer l'oracle, son plancher devrait dépasser le chemin le plus long. Or ce chemin comprend les appels à KLASSCI, jusqu'à 10 s de délai (`KlassciTenantDiscovery`). Chaque échec de login immobiliserait alors un travailleur PHP pendant ce plancher, en tension directe avec l'axe de scalabilité (#381), et la limite de débit ne protège pas les travailleurs. Le leurre égalise la CAUSE (un bcrypt) au lieu d'attendre : il coûte ce qu'un mot de passe faux coûtait déjà. C'est la pratique d'OWASP (*Authentication Cheat Sheet*), de Django (`make_password` factice), de Spring Security et de Devise.

### Pourquoi le cache, et pas la mémoire du processus

La production sert l'API par Apache/PHP : l'état d'un singleton meurt avec la requête. Un leurre recalculé à chaque échec coûterait `make` + `check`, soit **deux** bcrypt contre un pour un mot de passe faux. L'oracle s'inverserait au lieu de se fermer. Le cache le garde d'une requête à l'autre, et sa lecture coûte de l'ordre de la milliseconde, contre quelques centaines pour un bcrypt.

Le premier échec qui en a besoin après un vidage du cache ou un changement de coût paie deux bcrypt, une seule fois.

### Pourquoi `needsRehash`

Un leurre moins coûteux qu'une vraie empreinte serait de nouveau reconnaissable. Si le coût configuré change, l'empreinte gardée est refaite au coût nouveau.

## Le coût configuré

- Le dépôt ne publie pas `config/hashing.php`. Le coût vient donc de la configuration du cadre : `'rounds' => env('BCRYPT_ROUNDS', 12)`, pilote `bcrypt` (`vendor/laravel/framework/config/hashing.php:18,32`).
- `BCRYPT_ROUNDS` n'est posé ni dans `docker-compose.prod.yml`, ni dans `Dockerfile.prod`, ni dans `.github/workflows/`. Seul `phpunit.xml:46` le pose, à **4**, pour les tests : les tests comptent les vérifications, ils ne les chronomètrent pas.
- **Le coût réellement configuré en production n'est PAS mesuré.** Il vaut 12 si l'environnement Dokploy ne pose pas `BCRYPT_ROUNDS`. C'est à relever en lecture seule, sur le serveur.

## Conséquences

- **Amendement de l'ADR-796-01** : au-delà du plafond, le refus coûte désormais **une** vérification, contre le leurre, au lieu de zéro. L'amplification reste bornée, à une vérification par requête et non N, et le débit reste à 10 par minute et par IP.
- Le chemin nominal (un compte, le bon mot de passe) reste à une vérification : le leurre n'est demandé que quand aucune vraie vérification n'a eu lieu.
- Ce qui fuirait d'une lecture du cache : l'empreinte d'un secret jeté, qui n'ouvre aucun compte.

## Limites connues, nommées

- **L'oracle KLASSCI reste ouvert.** Un identifiant inconnu de KLASSCI coûte un appel (`check-user`), un identifiant connu de KLASSCI en coûte deux (`check-user`, puis `login`). `LoginEnumerationTest` le fige. Le fermer est une autre décision de conception (#769) : un plancher immobilise un travailleur, et un appel leurre enverrait l'identifiant à un tenant qui ne le connaît pas. **#687 reste ouverte pour lui.**
- Deux ou trois homonymes sans correspondance coûtent deux ou trois vérifications (ADR-796-01). Égaliser à trois coûterait trois bcrypt à chaque échec.
- Une empreinte réelle d'un autre coût que le coût configuré, celle d'un compte ancien par exemple, diffère un peu du leurre.
