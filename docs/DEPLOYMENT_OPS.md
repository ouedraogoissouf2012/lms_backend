# DEPLOYMENT_OPS — Scheduler & Worker en production (cPanel)

> **Issue #369** — fiabilisation opérationnelle n°1 du système.
> Sans le cron ci-dessous : les visios ne se ferment jamais, les présences ne
> sont jamais finalisées, et les jobs s'accumulent indéfiniment dans la table
> `jobs` (`QUEUE_CONNECTION=database`).
>
> Ce document est la **référence versionnée** pour installer cron + worker +
> healthcheck. Le guide de déploiement global (`GUIDE_DEPLOIEMENT_PRODUCTION.md`)
> fera le raccord vers ce document (issue #370).

---

## 1. Architecture opérationnelle

La prod est un **cPanel mutualisé Linux** (`/home/c2569688c/public_html/lms-backend`) :
pas de Supervisor, pas de systemd, pas de démon possible. Tout repose donc sur
**UN SEUL cron** qui exécute le scheduler Laravel chaque minute ; le scheduler
orchestre lui-même les 12 tâches versionnées dans [`routes/console.php`](../routes/console.php),
**y compris le worker de queue** (voir §3).

```
cron cPanel (1 ligne, chaque minute)
   └── php artisan schedule:run
         ├── 10 tâches métier (tableau §5)
         ├── scheduler:heartbeat      → marqueur de vie en cache (§4)
         └── queue:work --stop-when-empty --max-time=55   → draine la table jobs (§3)
```

## 2. Cron cPanel — installation

**cPanel → Cron Jobs → Add New Cron Job** (fréquence : *Once Per Minute*) :

```cron
* * * * * /usr/local/bin/php /home/c2569688c/public_html/lms-backend/artisan schedule:run >> /dev/null 2>&1
```

- `/usr/local/bin/php` : binaire PHP CLI du serveur mutualisé (vérifier avec
  `which php` en SSH si le chemin diffère ; certains hébergeurs exposent
  `/usr/local/bin/ea-php83`).
- `>> /dev/null 2>&1` : recommandation officielle Laravel
  ([docs 12.x, *Running the Scheduler*](https://laravel.com/docs/12.x/scheduling#running-the-scheduler)) —
  la sortie normale est muette pour ne pas générer un e-mail cPanel par minute.
  L'observabilité passe par le healthcheck (§4) et `storage/logs/laravel.log`.

**Vérification immédiate (SSH)** :

```bash
cd /home/c2569688c/public_html/lms-backend
php artisan schedule:list        # les 12 tâches du §5 doivent apparaître
php artisan schedule:run         # exécution manuelle d'un tick
php artisan scheduler:healthcheck && echo OK   # OK après 1-2 minutes de cron
```

## 3. Worker de queue sur mutualisé — stratégie

**Contrainte** : pas de Supervisor → impossible de maintenir un démon
`php artisan queue:work` permanent (tout process long est tué par l'hébergeur).

**Solution versionnée** (dans `routes/console.php`, rien à installer de plus) :
le scheduler lance chaque minute

```
queue:work --stop-when-empty --max-time=55
```

| Choix | Pourquoi |
|---|---|
| `--stop-when-empty` | Le worker draine tous les jobs en attente puis **rend la main** — pas de process résident. |
| `--max-time=55` | Borne le drain sous le tick d'une minute — un process court n'est jamais tué en plein job par l'hébergeur. |
| `runInBackground()` | Le drain ne bloque pas les autres tâches du même tick `schedule:run`. |
| `withoutOverlapping(10)` | Un seul worker à la fois ; verrou à expiration **10 min** — si le process est tué net, la queue s'auto-guérit au lieu de rester gelée 24 h (expiration par défaut du verrou). |

**Latence résultante** : un job dispatché attend au maximum ~60 s avant
traitement. Acceptable pour les jobs du système (sync, notifications,
fermetures de séances) qui tolèrent tous la minute.

## 4. Healthcheck du scheduler

Deux commandes dédiées (issue #369) :

| Commande | Rôle | Sortie |
|---|---|---|
| `scheduler:heartbeat` | Planifiée **chaque minute** par le scheduler — pose un timestamp en cache (`scheduler:last_heartbeat_at`). | Toujours silencieuse. |
| `scheduler:healthcheck` | À appeler par le monitoring — **exit 0** si dernier battement < 5 min, **exit 1** sinon (+ ligne d'erreur + log `[SchedulerHealthcheck]`). | Silencieuse en succès, parlante en échec. |

Un scheduler mort est donc détecté en **< 10 minutes** (critère d'acceptation
de l'issue : battement chaque minute + seuil de péremption 5 min).

**Câblage monitoring minimal (second cron cPanel, toutes les 5 minutes)** :

```cron
*/5 * * * * /usr/local/bin/php /home/c2569688c/public_html/lms-backend/artisan scheduler:healthcheck
```

cPanel envoie un e-mail dès qu'un cron produit une sortie : comme le
healthcheck n'écrit **que en cas d'échec**, chaque e-mail reçu = alerte réelle
(renseigner l'adresse dans *Cron Jobs → Cron Email*).

> ⚠️ Ce second cron dépend du même démon cron que le premier — il couvre les
> pannes du `schedule:run` (chemin PHP cassé après montée de version, crash
> applicatif, `.env` invalide…), pas la mort du démon cron lui-même. Pour ce
> dernier cas, brancher un monitoring **externe** (ex. UptimeRobot sur une
> future route dédiée, ou un cron d'une autre machine en SSH).

## 5. Tableau des tâches planifiées (12)

| # | Nom (`schedule:list`) | Type | Fréquence | Rôle |
|---|---|---|---|---|
| 1 | `sync-klassci-seances` | Job | `*/5 * * * *` | Synchronise les séances depuis KLASSCI. |
| 2 | `detect-disconnected-participants` | Job | `*/2 * * * *` | Marque `disconnected` les participants sans heartbeat depuis 5 min. |
| 3 | `auto-close-empty-seances` **(nouveau #369)** | Job | `*/5 * * * *` | Ferme les visios abandonnées (prof déconnecté 5 min / tous déconnectés 10 min / personne 30 min). |
| 4 | `finalize-seance-attendances` | Job | `*/10 * * * *` | Finalise les présences des séances terminées depuis 30+ min. |
| 5 | `clean-obsolete-seances` | Job | `*/30 * * * *` | Purge les séances supprimées côté KLASSCI. |
| 6 | `archive-old-seances` | Job | `0 2 * * *` | Archive les séances de plus de 2 semaines. |
| 7 | `clean-old-evaluations` | Job | `0 3 * * *` | Archive les évaluations terminées sans soumission (7+ j). |
| 8 | `purge-audit-logs` | Commande | `30 3 * * *` | Purge du journal d'audit au-delà de la rétention (#215). |
| 9 | `notify-upcoming-evaluations` | Commande | `0 8 * * *` | Rappels étudiants 24 h avant évaluation. |
| 10 | `cleanup-old-notifications` | Closure | `0 4 * * 0` | Supprime les notifications lues > 30 j. |
| 11 | `scheduler-heartbeat` **(nouveau #369)** | Commande | `* * * * *` | Marqueur de vie lu par `scheduler:healthcheck`. |
| 12 | `queue-worker` **(nouveau #369)** | Commande | `* * * * *` | Draine la table `jobs` (§3). |

Le test [`tests/Feature/Console/ScheduleRegistrationTest.php`](../tests/Feature/Console/ScheduleRegistrationTest.php)
fige le câblage des 3 tâches critiques ajoutées par #369.

## 6. Poste de développement Windows

L'outillage Planificateur de tâches Windows (équivalent dev du cron) vit sous
[`scripts/dev-windows/`](../scripts/dev-windows/) — **dev uniquement**, jamais
utilisé en prod.

## 7. Dépannage rapide

| Symptôme | Diagnostic | Action |
|---|---|---|
| `scheduler:healthcheck` exit 1 | Cron absent/cassé | Vérifier la ligne cron (§2), le chemin PHP, puis `php artisan schedule:run` manuel en SSH. |
| Table `jobs` qui grossit | Worker ne draine plus | `php artisan queue:work --stop-when-empty` manuel ; si le verrou est bloqué : `php artisan cache:clear` restaure le mutex (verrou auto-expirant à 10 min sinon). |
| Jobs en échec | Voir table `failed_jobs` | `php artisan queue:failed` puis `queue:retry <id>`. |
| Visios non fermées malgré cron OK | Heartbeats frontend absents | Voir `DIAGNOSTIC_HEARTBEAT_PROBLEM.md` — le gate `HeartbeatHealthChecker` bloque volontairement la fermeture. |
