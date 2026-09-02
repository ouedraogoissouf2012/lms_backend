# Déploiement de la visioconférence — guide complet

> Ce qui a été installé les 30–31 août 2026, comment le refaire, et **les pièges qui
> ont réellement coûté du temps**.
> Complément opérationnel de [VISIO_JIBRI_FINALIZE.md](VISIO_JIBRI_FINALIZE.md).

---

## 1. L'architecture, en une page

```
                    Internet
                       │
                  ┌────┴────┐
                  │ Traefik │  (Dokploy, TLS Let's Encrypt)
                  └────┬────┘
         ┌─────────────┼──────────────┐
         │             │              │
  visio.klassci.com  apilms.       lms.klassci.com
         │           klassci.com        (front)
    ┌────┴─────────────────────┐   │
    │  pile visio-base         │   │
    │  ├─ web      (interface) │   │
    │  ├─ prosody  (comptes)   │   │
    │  ├─ jicofo   (chef d'orchestre)
    │  └─ jvb      (pont média)│   │
    └────┬─────────────────────┘   │
         │ réseau visio-meet-jitsi │
    ┌────┴─────────────┐           │
    │ pile visio-jibri │           │
    │  └─ jibri-1      │           │
    └────┬─────────────┘           │
         │ écrit                   │ lit (lecture seule)
    /opt/visio/recordings ─────────┘
```

**Cinq services Dokploy** : `visio-base`, `visio-jibri`, `lms-backend`,
`lms-frontend`, `lms-mysql`.

Les piles visio et le LMS sont sur des **réseaux Docker disjoints** : Jibri joint le
LMS par son URL publique, à travers Traefik. C'est acceptable — le webhook est signé —
et mesuré à 22 ms.

---

## 2. Les secrets, et comment ils s'articulent

Quatre secrets, dont **deux doivent être identiques de part et d'autre**. Une
divergence ne produit **aucun message exploitable** : juste un refus.

| Secret | Où | Doit correspondre à |
|---|---|---|
| `JWT_APP_SECRET` | `visio-base` / prosody | `JITSI_APP_SECRET` côté LMS |
| `JITSI_APP_SECRET` | `lms-backend` | `JWT_APP_SECRET` côté prosody |
| `LMS_WEBHOOK_SECRET` | `visio-jibri` | `VISIO_RECORDING_WEBHOOK_SECRET` côté LMS |
| `VISIO_RECORDING_WEBHOOK_SECRET` | `lms-backend` | `LMS_WEBHOOK_SECRET` côté Jibri |

S'y ajoutent quatre mots de passe XMPP internes (`JICOFO_AUTH_PASSWORD`,
`JVB_AUTH_PASSWORD`, `JIBRI_XMPP_PASSWORD`, `JIBRI_RECORDER_PASSWORD`), qui ne
sortent jamais des piles visio.

### Générer un secret

```powershell
# PowerShell (openssl n'est pas dans le PATH Windows)
$b = [byte[]]::new(32)
[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
($b | ForEach-Object { $_.ToString('x2') }) -join ''
```

```bash
# Git Bash ou serveur
openssl rand -hex 32
```

### Vérifier une correspondance sans afficher les valeurs

```bash
P=$(docker ps --format '{{.Names}}' | grep -i prosody | head -1)
L=$(docker ps --format '{{.Names}}' | grep -iE "lmsbackend.*web" | head -1)
docker exec "$P" sh -c 'printf "%s" "$JWT_APP_SECRET"'   | sha256sum | cut -c1-16
docker exec "$L" sh -c 'printf "%s" "$JITSI_APP_SECRET"' | sha256sum | cut -c1-16
```

Deux empreintes identiques = valeurs identiques.

### Rotation

1. Générer **une** valeur
2. La poser dans `visio-base` (`JWT_APP_SECRET`) → **Deploy** ⚠️ coupe les réunions en cours
3. La poser dans `lms-backend` (`JITSI_APP_SECRET`) → **Deploy**
4. Vérifier les empreintes, puis valider un jeton réel (§6.2)

**Un simple *Save* ne suffit pas.** Seul le *Deploy* recrée le conteneur et injecte
les variables.

---

## 3. Réglages de capacité

Posés dans `visio-base`, ils bornent la charge — le serveur héberge d'autres
applications.

| Réglage | Valeur | Effet |
|---|---|---|
| `JICOFO_CONF_MAX_AUDIO_SENDERS` | 8 | plafond de micros ouverts simultanément |
| `JICOFO_CONF_MAX_VIDEO_SENDERS` | 4 | plafond de caméras actives |
| `JICOFO_MAX_MEMORY` | 1024m | sans `-Xmx` explicite, la JVM vise 3 Go et se fait tuer |
| `route-loudest-only` | true | seuls les flux audibles sont relayés |
| `num-loudest` / `jvb-last-n` | 3 / 6 | flux audio et vidéo relayés au maximum |

⚠️ `route-loudest-only` **n'a pas de variable d'environnement**. Il vit dans
`/etc/dokploy/jitsi-cfg/jvb/custom-jvb.conf`, monté dans le conteneur. Le format de
configuration **ignore silencieusement** un fichier absent : vérifier que la
directive est bien chargée, jamais supposer.

---

## 4. Le service d'enregistrement

### 4.1 Ce que la pile de base fournit déjà

`ENABLE_RECORDING=1` sur prosody suffit à créer les comptes nécessaires, et jicofo
configure seul le salon de recrutement. **Rien à ajouter côté base.**

```bash
# les comptes existent (chercher dans /var/lib/prosody, PAS dans /config)
docker exec "$P" sh -c 'find /var/lib/prosody -name "*.dat" | grep -E "jibri|recorder"'
```

### 4.2 Trois prérequis matériels

| Prérequis | Valeur | Conséquence si absent |
|---|---|---|
| `shm_size` | **2 Go** | Chrome refuse de démarrer |
| Propriétaire des dossiers | **UID 1000** | voir §5.1 — le piège principal |
| `/opt/visio/logs` | doit exister | le serveur d'affichage meurt au démarrage |

### 4.3 Réglages d'encodage retenus

`1280x720` à 15 images/s, `crf 28`, préréglage `ultrafast`.

720p et non 360p parce que le contenu est fait de **diapositives**, dont le texte
doit rester lisible. Sur une image quasi immobile, le coût processeur dépend surtout
du nombre d'images par seconde, pas de la résolution.

Ces réglages pilotent **à la fois** l'encodeur et la définition de l'écran virtuel.
Ils ne limitent **pas** la qualité reçue par les participants.

---

## 5. Les pièges — ceux qui ont réellement coûté du temps

### 5.1 L'UID 1000, pas 995

**Le plus coûteux.** L'image contient un compte `jibri` d'UID 995, mais **les
processus tournent sous l'UID 1000** (`s6`).

Symptôme : le serveur d'affichage ne peut pas ouvrir son journal, **meurt au
démarrage**, et le conteneur reste marqué `healthy` — aucun enregistrement n'est
possible, sans le moindre message parlant.

```bash
chown -R 1000:1000 /opt/visio /etc/dokploy/jitsi-cfg/jibri-1
```

### 5.2 L'indentation YAML qui fusionne deux variables

Une ligne plus enfoncée que la précédente n'est plus un élément de liste : elle en
devient la **continuation**. Docker et Dokploy ne s'en plaignent pas.

```yaml
      - TZ=UTC
        - JIBRI_FINALIZE_RECORDING_SCRIPT_PATH=/config/finalize-recording.sh
```

Résultat mesuré dans le conteneur :

```
TZ = [UTC - JIBRI_FINALIZE_RECORDING_SCRIPT_PATH=/config/finalize-recording.sh]
```

**Deux variables cassées pour deux espaces.** Ce piège s'est produit trois fois de
suite, en se déplaçant d'une ligne à chaque correction — parce qu'on corrigeait la
ligne fautive sans regarder sa voisine.

**La parade** : après tout déploiement, vérifier **toutes** les variables, pas
seulement celles qu'on vient d'ajouter.

```bash
docker exec "$J" sh -c 'env | grep -E "^(XMPP_|JIBRI_|LMS_|TZ=)" | sort'
```

Un cas était grave : `XMPP_HIDDEN_DOMAIN` corrompu porte le domaine du compte
d'enregistrement. Jibri aurait démarré, Chrome se serait lancé, puis aurait échoué à
entrer dans la salle — en produisant un répertoire vide.

### 5.3 Les contrôles de santé qui ne surveillent rien

Deux fautes symétriques rencontrées :

| Service | Faute | Conséquence |
|---|---|---|
| Jibri | ne vérifiait pas l'affichage | `healthy` alors qu'aucun enregistrement n'était possible |
| jicofo | interroge un chemin disparu | `unhealthy` en permanence alors que tout va bien |

**Un contrôle qui ne peut pas échouer, comme un contrôle qui ne peut pas passer, ne
surveille rien.** Toujours valider **dans les deux sens** : provoquer la panne et
vérifier que l'indicateur bascule.

```bash
docker exec "$J" sh -c 'pgrep -x XorgInexistant >/dev/null && ... ' ; echo $?   # doit valoir 1
```

### 5.4 Ce qui n'est pas un problème

- **`read_only: true` sur Jibri** : ne pas l'activer. Le service audio écrit dans son
  répertoire personnel, et le masquer cacherait sa propre configuration.
- **Erreurs `dbus` dans les journaux** : bénignes, il n'y a pas de bus système en
  conteneur. Une trentaine par démarrage, sans effet.
- **Pilotes clavier/souris manquants au démarrage de l'affichage** : normal, l'écran
  est virtuel.
- **Un `.mp4` de 48 octets pendant l'enregistrement** : normal. L'index du format est
  écrit **à la fin**. La taille réelle n'apparaît qu'après l'arrêt.

---

## 6. Vérifications

### 6.1 État général

```bash
docker ps --format '{{.Names}}  {{.Status}}' | grep -iE "visio|jibri"
docker exec "$JICOFO" sh -c 'curl -s http://127.0.0.1:8888/stats' | grep -o '"jibri_detector":[^}]*}'
# attendu : {"count": 1, "available": 1}
```

### 6.2 Un jeton du LMS est-il accepté par le serveur de visioconférence ?

Vérifie la **chaîne de confiance**, pas seulement l'égalité des valeurs.

```bash
TOKEN=$(docker exec "$LMS" sh -c '
NOW=$(date -u +%s)
H=$(printf "%s" "{\"alg\":\"HS256\",\"typ\":\"JWT\"}" | openssl base64 -A | tr "+/" "-_" | tr -d "=")
P=$(printf "%s" "{\"iss\":\"$JITSI_APP_ID\",\"aud\":\"$JITSI_AUDIENCE\",\"sub\":\"meet.jitsi\",\"room\":\"verif\",\"iat\":$NOW,\"nbf\":$((NOW-10)),\"exp\":$((NOW+600))}" | openssl base64 -A | tr "+/" "-_" | tr -d "=")
S=$(printf "%s.%s" "$H" "$P" | openssl dgst -sha256 -hmac "$JITSI_APP_SECRET" -binary | openssl base64 -A | tr "+/" "-_" | tr -d "=")
printf "%s.%s.%s" "$H" "$P" "$S"')

docker exec "$PROSODY" sh -c "
TOK='$TOKEN'; SIGNED=\"\${TOK%.*}\"; SIG=\"\${TOK##*.}\"
EXP=\$(printf '%s' \"\$SIGNED\" | openssl dgst -sha256 -hmac \"\$JWT_APP_SECRET\" -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')
[ \"\$SIG\" = \"\$EXP\" ] && echo VALIDE || echo INVALIDE"
```

### 6.3 Un enregistrement est-il réellement valide ?

**La seule vérification qui distingue une vidéo utilisable d'une piste muette
produite sans erreur.**

```bash
F=$(docker exec "$J" sh -c 'find /storage/recordings -name "*.mp4" | head -1')
docker exec "$J" sh -c "ffmpeg -hide_banner -nostats -i '$F' -af volumedetect -f null - 2>&1 | grep mean_volume"
```

| Mesure | Interprétation |
|---|---|
| ≈ **−91 dB** | **silence numérique** — rien n'a été capté |
| **−56 dB** de moyenne, crêtes vers −18 dB | parole réelle (référence mesurée) |

Compléter par le nombre d'images décodées :

```bash
docker exec "$J" sh -c "ffprobe -v error -select_streams v:0 -count_frames -show_entries stream=nb_read_frames -of csv=p=0 '$F'"
# 1789 images / 119,3 s = 15 im/s exactement, aucune perdue
```

---

## 7. Ce qui reste ouvert

| Réf. | Sujet |
|---|---|
| **#673** | Le bouton d'enregistrement du LMS ne pilote pas Jibri — l'enseignant doit encore cliquer dans l'onglet Jitsi |
| **#674** | Supprimer un chapitre laisse ses fichiers sur le disque |
| **#675** | jicofo marqué `unhealthy` en permanence (correctif prêt, non déployé) |

**Capacité à 5 enregistrements simultanés : non mesurée.** Le relevé disponible
(0,5–1,7 % processeur, 579 Mo) porte sur un écran quasi immobile — c'est un plancher,
pas une capacité. Chaque enregistrement supplémentaire demande **un conteneur
supplémentaire** : un service Jibri traite un seul enregistrement à la fois.
