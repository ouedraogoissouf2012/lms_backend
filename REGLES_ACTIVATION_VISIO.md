# 🔐 RÈGLES D'ACTIVATION DE LA VISIO

## ÉTAT ACTUEL ⚠️

Actuellement, **il n'y a aucune restriction de rôle** dans le backend:
- `activateVisio()` → Pas de vérification de rôle
- `startVisio()` → Pas de vérification de rôle

**Tout le monde peut activer et démarrer la visio!**

---

## RÈGLES À IMPLÉMENTER

### Option 1: **Coordinateur ET Enseignant peuvent tous les deux activer/démarrer**

#### Coordinateur active:
- ✅ Coordinateur clique "Activer la visio"
- ✅ Status → `programmee`
- ✅ Enseignant voit le bouton "Démarrer maintenant"
- ✅ Coordinateur voit aussi le bouton "Démarrer maintenant"
- ✅ Le premier qui clique "Démarrer" lance la visio

#### Enseignant active:
- ✅ Enseignant clique "Activer la visio"
- ✅ Status → `programmee`
- ✅ Coordinateur voit le bouton "Démarrer maintenant"
- ✅ Enseignant voit aussi le bouton "Démarrer maintenant"
- ✅ Le premier qui clique "Démarrer" lance la visio

**Avantages:**
- Flexibilité maximale
- Le coordinateur peut aider si l'enseignant a des problèmes
- L'enseignant garde le contrôle

**Inconvénients:**
- Peut créer de la confusion (qui démarre?)

---

### Option 2: **Séparation stricte des rôles**

#### Coordinateur SEUL peut activer:
- ✅ Coordinateur clique "Activer la visio" → Status `programmee`
- ❌ Enseignant ne peut PAS activer
- ✅ Enseignant voit "En attente d'activation par le coordinateur"
- ✅ Une fois activée, enseignant voit "Démarrer maintenant"

#### Enseignant SEUL peut démarrer:
- ✅ Enseignant clique "Démarrer maintenant" → Status `active`
- ❌ Coordinateur ne peut PAS démarrer
- ✅ Coordinateur voit "En attente que l'enseignant démarre"

**Avantages:**
- Rôles clairs et définis
- Pas de confusion
- Coordinateur contrôle, enseignant exécute

**Inconvénients:**
- Moins flexible
- Si coordinateur absent, pas de visio

---

### Option 3: **Activation flexible, démarrage par l'enseignant uniquement**

#### Activation (Coordinateur OU Enseignant):
- ✅ Coordinateur OU Enseignant clique "Activer"
- ✅ Status → `programmee`
- ✅ Les deux voient que c'est activé

#### Démarrage (Enseignant UNIQUEMENT):
- ✅ SEUL l'enseignant peut cliquer "Démarrer maintenant"
- ❌ Coordinateur ne voit PAS le bouton "Démarrer"
- ✅ Coordinateur voit "En attente que l'enseignant démarre"
- ✅ Enseignant clique → Status `active`

**Avantages:**
- L'enseignant garde le contrôle du démarrage
- Le coordinateur peut préparer à l'avance
- Logique pédagogique: l'enseignant commence son cours

**Inconvénients:**
- Coordinateur ne peut pas démarrer à la place de l'enseignant

---

## QUELLE OPTION PRÉFÉREZ-VOUS?

**Option 1**: Flexibilité totale (tout le monde peut tout faire)
**Option 2**: Séparation stricte (coordinateur active, enseignant démarre)
**Option 3**: Activation flexible, démarrage enseignant uniquement (RECOMMANDÉ)

---

## CODE À MODIFIER

### Backend: `LMSDataController.php`

```php
// Dans activateVisio()
public function activateVisio(int $seanceId, Request $request): JsonResponse
{
    $user = $request->user();

    // OPTION 2 ou 3: Vérifier le rôle
    if (!in_array($user->role, ['coordinateur', 'enseignant'])) {
        return response()->json([
            'success' => false,
            'message' => 'Seuls les coordinateurs et enseignants peuvent activer la visio'
        ], 403);
    }

    // ... reste du code
}

// Dans startVisio()
public function startVisio(int $seanceId, Request $request): JsonResponse
{
    $user = $request->user();

    // OPTION 2 ou 3: Seul l'enseignant peut démarrer
    if ($user->role !== 'enseignant') {
        return response()->json([
            'success' => false,
            'message' => 'Seul l\'enseignant peut démarrer la visio'
        ], 403);
    }

    // OPTION 3: Vérifier que c'est bien l'enseignant de cette séance
    $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

    if ($visio->klassci_enseignant_id !== $user->klassci_id) {
        return response()->json([
            'success' => false,
            'message' => 'Seul l\'enseignant de cette séance peut la démarrer'
        ], 403);
    }

    // ... reste du code
}
```

### Frontend: Affichage conditionnel des boutons

Dans `TeacherSeances.vue`, condition sur les boutons selon le rôle.

---

## DITES-MOI QUELLE OPTION VOUS VOULEZ

Et je l'implémente immédiatement!
