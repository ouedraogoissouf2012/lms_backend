# Rétention des enregistrements visio

## Politique

La durée de rétention locale est de 365 jours (`config/recordings.php`). La date
de référence est, dans l'ordre, `processed_at`, `stopped_at`, puis `created_at`.
La borne est stricte : un élément daté exactement du jour limite est conservé.
Les statuts actifs (`recording`, `uploading`, `processing`) ne sont jamais purgés.

Pour chaque enregistrement expiré, `recordings:purge --apply` :

- supprime définitivement ses métadonnées et son URL dans `seance_recordings` ;
- supprime définitivement le chapitre uniquement s'il a été généré pour cette
  séance et appartient à la même institution ;
- conserve les entrées `audit_logs`, soumises à leur propre politique ;
- compte le fichier fournisseur comme ignoré, car aucun contrat/API de
  suppression fournisseur n'existe actuellement dans le backend.

## Exécution cPanel

Le scheduler lance la purge chaque jour à 03:45. Le cron cPanel reste l'unique
ligne documentée dans `docs/DEPLOYMENT_OPS.md` :

```cron
* * * * * /usr/local/bin/php /home/c2569688c/public_html/lms-backend/artisan schedule:run >> /dev/null 2>&1
```

Avant une première activation en production :

```bash
php artisan recordings:purge --dry-run
php artisan recordings:purge --apply
```

Sans `--dry-run` ou `--apply`, la commande refuse de démarrer. Chaque exécution
journalise le seuil, les éléments éligibles, purgés, ignorés et en échec.

## Rollback et limites

La suppression locale est irréversible hors restauration d'une sauvegarde
MySQL prise avant l'exécution. Pour revenir en arrière : désactiver temporairement
la tâche `purge-visio-recordings`, restaurer la sauvegarde, puis vérifier les
relations chapitre/enregistrement avant de réactiver le scheduler.

Les fichiers distants ne sont pas supprimés par cette version. Leur rétention
doit être configurée chez le fournisseur. Une future intégration devra supprimer
le fichier distant avec confirmation avant d'effacer l'identifiant local, et
gérer retries, idempotence et indisponibilité fournisseur.
