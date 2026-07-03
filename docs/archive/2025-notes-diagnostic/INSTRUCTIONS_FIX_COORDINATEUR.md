# FIX: Coordinateur ne peut pas rejoindre une visio en cours

## 🔴 PROBLÈME

Le coordinateur voit une conférence en direct mais n'a pas de bouton "Rejoindre":
- Il voit seulement: "La fenêtre pour démarrer le cours est fermée"
- Les étudiants peuvent rejoindre
- L'enseignant qui a démarré peut rejoindre
- ❌ Le coordinateur ne peut pas rejoindre

## 🔍 CAUSE

**Fichier**: `lms-frontend/src/views/seances/SeanceDetails.vue`

**Ligne 263**: Le coordinateur est bien dans `isTeacher`:
```javascript
isTeacher() {
  return this.user && ['enseignant', 'coordinateur', 'teacher'].includes(this.user.role)
}
```

**Lignes 100-116**: Logique du bouton pour `isTeacher`:
```vue
<div v-if="isTeacher">
  <button v-if="visio.window?.can_start" @click="startVisio">
    Démarrer le cours
  </button>
  <div v-else>
    La fenêtre pour démarrer le cours est fermée  ← Le coordinateur voit ça!
  </div>
</div>
```

**Problème**: Une fois que l'enseignant a démarré (`status = 'active'`), `can_start` devient `false`, donc le coordinateur voit seulement le message "fermée".

## ✅ SOLUTION

Ajouter une vérification pour `status === 'active'` qui affiche le bouton "Rejoindre" pour les enseignants/coordinateurs.

### Code à modifier

**Fichier**: `lms-frontend/src/views/seances/SeanceDetails.vue`

**Remplacer lignes 99-116**:

```vue
        <!-- Bouton Enseignant -->
        <div v-if="isTeacher">
          <button
            v-if="visio.window?.can_start"
            @click="startVisio"
            class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition flex items-center justify-center gap-2"
          >
            <span class="btn-icon">▶</span> Démarrer le cours
          </button>
          <div v-else class="text-center py-4 text-gray-600">
            <p v-if="!visio.window?.has_started">
              Vous pourrez démarrer le cours 15 minutes avant l'heure prévue
            </p>
            <p v-else>
              La fenêtre pour démarrer le cours est fermée
            </p>
          </div>
        </div>
```

**Par ce code**:

```vue
        <!-- Bouton Enseignant / Coordinateur -->
        <div v-if="isTeacher">
          <!-- Si le cours est déjà actif, permettre de rejoindre (pour coordinateur aussi) -->
          <div v-if="visio.status === 'active'">
            <div class="mb-3 p-3 bg-red-50 border-2 border-red-400 rounded-lg text-center">
              <span class="text-red-600 font-bold text-lg">🔴 COURS EN DIRECT</span>
            </div>
            <button
              @click="joinVisio"
              class="w-full px-6 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition flex items-center justify-center gap-2"
            >
              <span class="btn-icon">◉</span> Rejoindre le cours
            </button>
          </div>
          <!-- Sinon, afficher le bouton de démarrage (enseignant seulement) -->
          <div v-else>
            <button
              v-if="visio.window?.can_start"
              @click="startVisio"
              class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition flex items-center justify-center gap-2"
            >
              <span class="btn-icon">▶</span> Démarrer le cours
            </button>
            <div v-else class="text-center py-4 text-gray-600">
              <p v-if="!visio.window?.has_started">
                Vous pourrez démarrer le cours 15 minutes avant l'heure prévue
              </p>
              <p v-else>
                La fenêtre pour démarrer le cours est fermée
              </p>
            </div>
          </div>
        </div>
```

## 📝 ÉTAPES POUR APPLIQUER

### Option 1: Modification manuelle (recommandée)

1. Ouvre `lms-frontend/src/views/seances/SeanceDetails.vue` dans ton éditeur
2. Va à la ligne 99
3. Remplace le code comme indiqué ci-dessus
4. Sauvegarde le fichier
5. Rebuild le frontend:
   ```bash
   cd "C:/Users/USER PC/Documents/propre à moi/lms-frontend"
   npm run build
   ```
6. Rafraîchis la page dans le navigateur

### Option 2: Via Git (si tu veux garder l'historique)

```bash
cd "C:/Users/USER PC/Documents/propre à moi/lms-frontend"
# Modifier le fichier manuellement
git add src/views/seances/SeanceDetails.vue
git commit -m "fix: Permettre au coordinateur de rejoindre une visio en cours"
npm run build
```

## 🧪 TEST

1. **Connecte-toi en tant que coordinateur**
2. **Va sur la page Visio activées** (ou Séances)
3. **Clique sur la séance 58 qui est EN COURS**
4. **Tu devrais voir**:
   - Badge rouge "🔴 COURS EN DIRECT"
   - Bouton vert "Rejoindre le cours"
5. **Clique sur "Rejoindre le cours"**
6. **Jitsi devrait s'ouvrir** avec ton nom

## ✅ RÉSULTAT ATTENDU

### AVANT (problème):
- Enseignant: ✅ Peut démarrer
- Enseignant: ✅ Peut rejoindre si déjà démarré
- Coordinateur: ❌ Ne peut PAS rejoindre (voit "fermée")
- Étudiant: ✅ Peut rejoindre

### APRÈS (corrigé):
- Enseignant: ✅ Peut démarrer
- Enseignant: ✅ Peut rejoindre si déjà démarré
- Coordinateur: ✅ **PEUT REJOINDRE** si déjà démarré ← CORRIGÉ
- Étudiant: ✅ Peut rejoindre

## 📊 FICHIERS CONCERNÉS

- ✅ Backend: Rien à changer (l'API fonctionne déjà)
- ✅ Frontend: `lms-frontend/src/views/seances/SeanceDetails.vue` (lignes 99-116)

## 🔧 AUTRES VÉRIFICATIONS

### Vérifier que l'API autorise le coordinateur

Le backend doit autoriser le coordinateur à appeler `/api/lms/seances/{id}/join`.

**Fichier**: `lms-backend/app/Http/Controllers/API/LMSDataController.php`

Cherche la méthode `joinVisio` et vérifie qu'elle autorise les coordinateurs.

Si besoin, je peux vérifier ça aussi!

---

**Date**: 2025-11-18
**Status**: ⚠️ À APPLIQUER
