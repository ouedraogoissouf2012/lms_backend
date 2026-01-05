# 🧪 GUIDE DE TEST - Correction heures de départ

## Préparation

### 1. Ouvrir 2 terminaux

**Terminal 1** - Monitoring en temps réel :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
php monitor_seance_live.php
```

**Terminal 2** - Commandes de vérification :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
```

---

## 🎯 SCÉNARIO 1 : Bouton "Terminer" avec heures correctes

**But** : Vérifier que quand vous cliquez sur "Terminer", les participants gardent leurs vraies heures de départ.

### Étapes

1. **Rejoindre la séance** (en tant qu'enseignant)
   - Aller sur la page de la séance
   - Cliquer sur "Démarrer la visio"
   - La fenêtre Jitsi s'ouvre

2. **Inviter 2-3 participants** (étudiants)
   - Leur demander de rejoindre la séance
   - Vérifier dans le **Terminal 1** : Vous devriez voir les participants apparaître avec 🟢 CONNECTED

3. **Noter l'heure actuelle** : ___:___

4. **Faire partir les participants** (étudiants)
   - Les étudiants ferment leur fenêtre Jitsi (X rouge)
   - **Attendre 30 secondes**
   - Dans le **Terminal 1** : Vous devriez voir :
     ```
     🔴 MARCEL OUEDRAOGO (etudiant)
        Quitté : HH:MM:SS  ← Noter cette heure
     ```

5. **Attendre 2-3 minutes** (simulation du problème)
   - Vous (enseignant) restez dans Jitsi
   - Les étudiants sont déjà partis
   - Dans le **Terminal 1** : Vous restez 🟢, les étudiants sont 🔴

6. **Noter l'heure actuelle** : ___:___

7. **Cliquer sur "Terminer la séance"** (dans l'interface LMS, pas Jitsi)
   - Aller dans l'interface LMS
   - Cliquer sur le bouton "Terminer"

8. **Vérifier les résultats** (Terminal 2) :
   ```bash
   php -r "
   require 'vendor/autoload.php';
   \$app = require_once 'bootstrap/app.php';
   \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

   \$seance = App\Models\Seance::where('visio_active', false)
       ->orderBy('visio_ended_at', 'desc')
       ->first();

   echo \"Séance fermée à : \" . \$seance->visio_ended_at->format('H:i:s') . \"\n\n\";

   \$attendances = App\Models\ESBTPAttendance::where('seance_id', \$seance->id)
       ->with('user')
       ->get();

   foreach (\$attendances as \$att) {
       echo \$att->user->name . \" : \";
       echo \"Quitté à \" . (\$att->left_at ? \$att->left_at->format('H:i:s') : 'N/A');
       echo \" (last_seen_at: \" . (\$att->last_seen_at ? \$att->last_seen_at->format('H:i:s') : 'N/A') . \")\n\";
   }
   "
   ```

### ✅ Résultat attendu

```
Séance fermée à : 10:35:00  ← Heure où vous avez cliqué sur "Terminer"

Marcel OUEDRAOGO : Quitté à 10:32:15 ← Heure où il a vraiment quitté (son dernier heartbeat)
Issouf TRAORE    : Quitté à 10:32:30 ← Heure où il a vraiment quitté
Enseignant       : Quitté à 10:35:00 ← Vous (même heure que la fermeture)
```

### ❌ Si ça ne marche pas

Si tous ont la même heure (10:35:00), c'est que le problème persiste.

---

## 🎯 SCÉNARIO 2 : Popup enseignant ferme Jitsi

**But** : Vérifier que la popup apparaît quand l'enseignant ferme Jitsi.

### Étapes

1. **Rejoindre la séance** (en tant qu'enseignant)
   - Démarrer une nouvelle séance
   - La fenêtre Jitsi s'ouvre

2. **Inviter 2-3 participants** (étudiants)
   - Les étudiants rejoignent
   - Vérifier dans le **Terminal 1** : Tous 🟢 CONNECTED

3. **Faire partir les étudiants**
   - Les étudiants ferment Jitsi
   - **Attendre 30 secondes**
   - Dans le **Terminal 1** : Étudiants 🔴, vous 🟢

4. **Fermer VOTRE fenêtre Jitsi** (enseignant)
   - Cliquer sur le X rouge de la fenêtre Jitsi
   - **Attendre la popup** (elle apparaît après ~0.5 seconde)

5. **POPUP ATTENDUE** :
   ```
   🎓 Voulez-vous terminer la séance pour tous les participants ?

   ✅ OUI : La séance sera fermée pour tout le monde
   ❌ NON : Vous êtes déconnecté mais la séance reste ouverte
   ```

6. **Cliquer sur "OUI"**

7. **Vérifier les résultats** :
   - Dans le **Terminal 1** : La séance devrait passer à 🔴 TERMINÉE
   - Même commande que Scénario 1 dans Terminal 2

### ✅ Résultat attendu

- ✅ Popup apparaît quand l'enseignant ferme Jitsi
- ✅ Séance fermée immédiatement après avoir cliqué "OUI"
- ✅ Tous les participants ont leurs vraies heures de départ

### ❌ Si la popup n'apparaît pas

Vérifier la console navigateur (F12) :
```javascript
// Devrait voir ces logs :
[VisioStore] 🚪 Fenêtre Jitsi fermée
[VisioStore] 🔚 Fermeture de la séance pour tous
[VisioStore] ✅ Séance fermée avec succès
```

---

## 🎯 SCÉNARIO 3 : Étudiant ferme Jitsi (PAS de popup)

**But** : Vérifier qu'un étudiant ne voit PAS la popup.

### Étapes

1. **Rejoindre en tant qu'étudiant**
   - Se connecter avec un compte étudiant
   - Rejoindre la séance

2. **Fermer la fenêtre Jitsi** (en tant qu'étudiant)
   - Cliquer sur le X rouge

3. **Vérifier** :
   - ❌ AUCUNE popup ne devrait apparaître
   - ✅ L'étudiant est simplement déconnecté
   - ✅ La séance continue pour les autres

### ✅ Résultat attendu

- ❌ PAS de popup pour les étudiants
- ✅ Déconnexion propre
- ✅ Séance reste active

---

## 🔍 Commandes utiles pendant les tests

### Voir la séance en cours
```bash
php monitor_seance_live.php
```

### Voir les derniers heartbeats
```bash
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

\$atts = App\Models\ESBTPAttendance::whereHas('seance', fn(\$q) => \$q->where('visio_active', true))
    ->with('user')
    ->get();

foreach (\$atts as \$att) {
    echo \$att->user->name . ' : ';
    echo 'last_seen_at = ' . (\$att->last_seen_at ? \$att->last_seen_at->format('H:i:s') : 'JAMAIS') . \"\n\";
}
"
```

### Voir toutes les séances du jour
```bash
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

\$seances = App\Models\Seance::whereDate('visio_started_at', today())
    ->orderBy('visio_started_at', 'desc')
    ->get();

foreach (\$seances as \$s) {
    echo \"Séance #{\$s->id} : \";
    echo (\$s->visio_active ? '🟢 ACTIVE' : '🔴 TERMINÉE');
    echo \" | Démarrée: \" . \$s->visio_started_at->format('H:i:s');
    if (\$s->visio_ended_at) {
        echo \" | Terminée: \" . \$s->visio_ended_at->format('H:i:s');
    }
    echo \"\n\";
}
"
```

---

## 📋 Checklist finale

Après avoir fait tous les tests :

- [ ] **Scénario 1** : Bouton "Terminer" utilise les vraies heures
- [ ] **Scénario 2** : Popup apparaît pour l'enseignant qui ferme Jitsi
- [ ] **Scénario 3** : Pas de popup pour les étudiants
- [ ] **Heartbeats** : Les last_seen_at sont utilisés comme left_at
- [ ] **Console browser** : Pas d'erreur JavaScript
- [ ] **Logs Laravel** : Pas d'erreur PHP

---

## 🐛 En cas de problème

### Erreur : "Popup ne s'affiche pas"

**Vérifier** :
```bash
# Frontend en cours d'exécution ?
# Devrait montrer http://localhost:5175
```

**Solution** : Rebuild du frontend
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-frontend"
npm run build
npm run dev
```

### Erreur : "Heures toujours incorrectes"

**Vérifier** :
```bash
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Vérifier si markAsDisconnected() utilise bien last_seen_at
\$code = file_get_contents('app/Models/ESBTPAttendance.php');
if (strpos(\$code, 'last_seen_at ?? now()') !== false) {
    echo \"✅ Code modifié correctement\n\";
} else {
    echo \"❌ Modification non présente\n\";
}
"
```

### Erreur : "Cannot read property 'role' of null"

**Cause** : Le user n'est pas dans localStorage

**Solution** : Se reconnecter

---

## 📞 Retour de tests

Après avoir testé, notez :

1. ✅ Ce qui fonctionne :
   -
   -

2. ❌ Ce qui ne fonctionne pas :
   -
   -

3. 🐛 Erreurs rencontrées :
   -
   -
