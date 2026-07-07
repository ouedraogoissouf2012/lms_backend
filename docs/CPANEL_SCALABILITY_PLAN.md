# Plan De Scalabilite cPanel

> Decision du 2026-07-07 : le plan VPS est abandonne pour le moment.
> La production reste sur un hebergement cPanel mutualise. Les choix de
> performance et d'exploitation doivent donc respecter cette contrainte.

## Limites Runtime

Un cPanel mutualise signifie que l'application ne peut pas dependre de :

- services systeme installes manuellement, comme Redis, daemons MySQL ou unites systemd ;
- processus permanent `php artisan queue:work` ;
- Laravel Octane, FrankenPHP, Supervisor ou workers PHP longue duree ;
- installation de paquets root ou configuration Nginx/Apache custom ;
- reservations CPU/RAM previsibles.

La cible production sure reste donc un runtime Laravel conservateur :

- MySQL comme base durable ;
- stores `database` ou `file` pour cache/session ;
- scheduler Laravel via cron cPanel ;
- drains courts de queue via scheduler ;
- optimisations code avant les optimisations infrastructure.

## Mode Operatoire Actif

Les sources de verite actuelles restent :

- `docs/DEPLOIEMENT_CPANEL.md` pour le deploiement ;
- `docs/DEPLOYMENT_OPS.md` pour cron, scheduler et drain de queue ;
- `routes/console.php` pour les taches planifiees.

Le scheduler cPanel doit tourner chaque minute :

```cron
* * * * * /usr/local/bin/php /home/c2569688c/public_html/lms-backend/artisan schedule:run >> /dev/null 2>&1
```

Le traitement des queues reste borne et non resident :

```bash
php artisan queue:work --stop-when-empty --max-time=55
```

Ce modele est moins puissant que des workers supervises, mais c'est le seul
compatible avec un hebergement mutualise.

## Remplacement Des Objectifs VPS

| Ancien objectif VPS | Remplacement compatible cPanel |
|---|---|
| Redis cache/session/rate-limit | stores database/file, TTL plus courts, moins d'ecritures |
| Workers Redis | drains courts lances par le scheduler |
| Octane/FrankenPHP | cycle de requete PHP-FPM/hebergement mutualise |
| systemd/Supervisor | cron cPanel uniquement |
| preuve k6 a forte charge soutenue | smoke/load checks modestes qui respectent l'hebergeur |
| alertes profondeur queue via Redis | compteurs `jobs` / `failed_jobs` via healthcheck planifie |

## Priorites

1. Reduire le travail HTTP :
   - deplacer PDF, conversion et notifications non essentiels dans des jobs ;
   - repondre en synchrone seulement quand le frontend a vraiment besoin du resultat.

2. Reduire la pression base de donnees :
   - ajouter des indexes sur les filtres chauds ;
   - eviter les requetes N+1 dans dashboards, notifications, seances et rapports ;
   - garder des TTL cache courts mais utiles.

3. Garder les queues compatibles cron :
   - rendre les jobs idempotents ;
   - declarer explicitement `tries`, `backoff` et `timeout` ;
   - drainer en moins de 55 secondes et laisser le tick cron suivant continuer.

4. Ajouter une observabilite compatible cPanel :
   - heartbeat scheduler ;
   - nombre de jobs echoues ;
   - age du plus vieux job pending depuis la table `jobs` ;
   - controle de taille des logs ;
   - sortie healthcheck exploitable par les alertes email cron.

5. Garder le deploiement simple :
   - changement local -> push -> `git pull` serveur ;
   - pas d'edition manuelle de fichiers sur cPanel ;
   - pas d'hypothese serveur indisponible sur mutualise.

## Issues Impactees

Les anciennes issues de scalabilite qui exigeaient un VPS ne peuvent pas etre
validees telles qu'ecrites :

- #372 : le baseline k6 doit devenir une mesure compatible cPanel.
- #374 : Redis n'est plus une cible production.
- #378 : Octane/FrankenPHP n'est plus une cible production.
- #379 : les workers supervises deviennent des jobs draines par cron.
- #380 : les metriques queue doivent venir de la base, pas de Redis.

Chaque issue doit etre fermee comme non planifiee ou remplacee par une issue
plus petite compatible cPanel.

## Acceptation Du Nouveau Plan

Un changement performance compatible cPanel est acceptable quand :

- il ne demande pas de paquet root, service systeme ou daemon ;
- il garde le flux de deploiement actuel ;
- il a des tests locaux ou une preuve par commande ;
- il documente tout changement cron ou `.env` ;
- il ameliore la fiabilite ou la latence sans promettre un debit niveau VPS.
