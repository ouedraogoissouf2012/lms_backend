# Finalisation des enregistrements Jibri

> Issue **#469**. Comment un enregistrement produit par Jibri devient un chapitre
> vidéo dans la formation, et quoi faire quand ça ne marche pas.

---

## 1. Le trajet, en une page

```
enseignant                LMS                    Jitsi / Jibri
    │                      │                          │
    ├─ « Démarrer » ──────►│ crée SeanceRecording      │
    │                      │ (statut Recording)        │
    │                                                  │
    ├─ clique « Enregistrer » DANS l'onglet Jitsi ────►│ jicofo recrute un Jibri
    │                                                  │ ffmpeg capture
    ├─ « Arrêter » ───────►│ statut Processing         │
    │                                                  │
    │                                                  ├─ finalize-recording.sh
    │                      │◄─ webhook signé ──────────┤   { room, session }
    │                      │                           │
    │                      ├─ salon → enregistrement actif
    │                      ├─ ImportJibriRecordingMedia (file `low`)
    │                      │    lit le .mp4 sur le chemin monté
    │                      │    le copie dans le stockage du LMS
    │                      ├─ ProcessSeanceRecordingReady
    │                      └─ chapitre vidéo créé dans la leçon
```

> ⚠️ **Deux gestes sont aujourd'hui nécessaires** : le bouton du LMS crée la ligne
> en base, mais **ne pilote pas Jibri**. L'enseignant doit aussi lancer
> l'enregistrement depuis l'onglet Jitsi. C'est un défaut connu, suivi dans une
> issue dédiée : la visio est ouverte via `window.open`, ce qui rend l'API de
> pilotage Jitsi inaccessible au front.

---

## 2. Installer le script côté serveur visio

### 2.1 Déposer le script

`ops/visio/finalize-recording.sh` est versionné dans ce dépôt. Le déploiement le
monte dans le conteneur Jibri, sous `/config` :

```
/etc/dokploy/jitsi-cfg/jibri-1/finalize-recording.sh
```

L'image le rend exécutable toute seule si nécessaire
(`/etc/s6-overlay/scripts/config:23-26`).

**Le fichier doit appartenir à l'UID 1000**, comme tout ce que lit ce conteneur —
voir §4.

### 2.2 Trois variables à ajouter au service `jibri-1`

```yaml
      - JIBRI_FINALIZE_RECORDING_SCRIPT_PATH=/config/finalize-recording.sh
      - LMS_WEBHOOK_URL=https://<api>/api/webhooks/visio/recording-ready
      - LMS_WEBHOOK_SECRET=<identique à VISIO_RECORDING_WEBHOOK_SECRET côté LMS>
```

Le secret **doit être identique des deux côtés**. Une divergence produit un
**401 sans message exploitable** : côté Jibri le journal montrera un refus
définitif, côté LMS une signature invalide.

### 2.3 Deux variables côté LMS

```
VISIO_RECORDING_WEBHOOK_SECRET=<le même secret>
VISIO_RECORDINGS_ROOT=/mnt/visio-recordings
```

Et le montage **en lecture seule** du répertoire d'enregistrements dans le
conteneur du LMS :

```yaml
      - /opt/visio/recordings:/mnt/visio-recordings:ro
```

> **Sans `VISIO_RECORDINGS_ROOT`, la voie Jibri est inactive** — délibérément.
> Cette racine est concaténée à un identifiant de session pour localiser un
> fichier : un défaut deviné ferait lire un répertoire arbitraire au job
> d'import. Une fonctionnalité éteinte vaut mieux qu'un chemin supposé.

---

## 3. Vérifier que ça marche

```bash
# 1. Le script est vu par Jibri
docker exec <jibri> sh -c 'grep finalize /run/jibri/config/jibri.conf'
#    doit montrer finalize-script = "/config/finalize-recording.sh"

# 2. Après un enregistrement réel
docker exec <jibri> sh -c 'tail -20 /storage/logs/finalize.log'
#    "accepté par le LMS (202)." = trajet complet

# 3. Côté LMS
php artisan tinker --execute="dump(App\Models\SeanceRecording::latest()->first()?->only(['status','provider','recording_url']));"
```

---

## 4. Le piège qui coûte le plus cher : l'UID

Les processus du conteneur Jibri tournent sous l'**UID 1000** (`s6`), et **non**
sous l'UID 995 du compte `jibri` visible dans l'image.

Une erreur ici est particulièrement traître : Xorg n'arrive pas à ouvrir son
journal, **meurt au démarrage**, et le conteneur reste malgré tout marqué
`healthy` si le contrôle de santé ne vérifie pas l'affichage — aucun
enregistrement n'est alors possible, sans le moindre message parlant.

```bash
chown -R 1000:1000 /opt/visio /etc/dokploy/jitsi-cfg/jibri-1
```

Le contrôle de santé du service `jibri-1` vérifie désormais `pgrep -x Xorg` en
plus de l'audio et de l'API : une panne permanente d'affichage bascule le
conteneur en `unhealthy` au bout de 3 échecs.

---

## 5. Quand une finalisation échoue

Le script **ne supprime jamais** le média. Tout échec laisse le fichier dans
`/opt/visio/recordings/<session>/`, donc rejouable.

### 5.1 Lire le motif

```bash
docker exec <jibri> sh -c 'tail -40 /storage/logs/finalize.log'
```

| Message | Cause | Geste |
|---|---|---|
| `Contrat d'appel différent de l'hypothèse` | Jibri ne passe pas le répertoire en `$1` | lire la ligne `finalize appelé avec…` juste au-dessus : elle montre les arguments réels |
| `LMS_WEBHOOK_URL et LMS_WEBHOOK_SECRET doivent être définis` | variables absentes | §2.2 |
| `REFUS DÉFINITIF du LMS (HTTP 401)` | secrets divergents ou horloges décalées de plus de 5 min | §2.2 ; vérifier `date -u` des deux côtés |
| `REFUS DÉFINITIF du LMS (HTTP 404)` | aucun enregistrement actif sur ce salon | §5.2 |
| `ÉCHEC après 3 tentatives` | LMS injoignable | relancer une fois le LMS revenu (§5.3) |

### 5.2 Le cas le plus fréquent : 404, enregistrement orphelin

Il survient quand l'enseignant a lancé l'enregistrement **depuis l'onglet Jitsi
sans passer par le bouton du LMS**. Le cours est bien enregistré, mais aucune
ligne `SeanceRecording` active n'existe pour le rattacher.

Le LMS le journalise nommément :

```
visio.recording.orphan_no_active_session   { room, session_id }
```

Le refus est **volontairement strict** : créer la ligne à la volée
contournerait le contrôle d'accès de `recording/start` (seul l'enseignant
propriétaire peut lancer) et effacerait l'acteur de la piste d'audit. Sur des
enregistrements de mineurs, « qui a décidé d'enregistrer » n'est pas une
métadonnée facultative.

**Rattachement manuel** — l'enseignant relance un cycle `start` / `stop` sur la
séance concernée, puis on rejoue la notification (§5.3).

### 5.3 Rejouer une notification

```bash
docker exec <jibri> sh -c 'LMS_WEBHOOK_URL="…" LMS_WEBHOOK_SECRET="…" \
  /config/finalize-recording.sh /storage/recordings/<session>'
```

Le nonce est régénéré à chaque exécution : un rejeu légitime n'est **pas**
confondu avec une attaque par répétition.

---

## 6. Ce que le LMS fait du média

| Étape | Où |
|---|---|
| Copie dans le stockage LMS | `storage/app/public/recordings/{id}/video/<aléatoire>.mp4` |
| URL persistée | `chapters.video_url`, **absolue** |
| Effacement | `SeanceRecordingRetentionService::purge()` supprime le fichier **et** les lignes |

Le nom de fichier est aléatoire : connaître l'identifiant d'un enregistrement ne
permet pas de deviner l'URL de sa vidéo.

> **Limite assumée, héritée de #598** : comme toute vidéo de chapitre, le fichier
> est servi sans authentification — `<video src>` ne s'authentifie pas. Le
> cloisonnement repose sur le caractère non devinable de l'URL. Rendre ces
> médias privés est un chantier distinct, hors #469.
