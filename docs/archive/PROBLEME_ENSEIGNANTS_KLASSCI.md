# Problème : Accès aux Enseignants KLASSCI pour le Coordinateur

**Date:** 24 Octobre 2025
**Statut:** ⚠️ BLOQUÉ - Besoin support KLASSCI

## Résumé du Problème

Le coordinateur ne peut pas accéder à la liste des enseignants via l'API KLASSCI, alors que les statistiques indiquent qu'il y a **3 enseignants** dans le système.

## Données Disponibles

### Statistiques KLASSCI (dans `klassci_data` du coordinateur)

```json
"statistics": {
    "nb_enseignants": 3,  ← 3 enseignants existent
    "nb_etudiants": 3,
    "nb_classes_actives": 2,
    "nb_matieres_actives": 3
}
```

### Endpoints Testés

| Endpoint | Status | Résultat |
|----------|--------|----------|
| `/enseignants` | 200 | `{"data": [], "total": 0}` ❌ Vide |
| `/matieres` | 200 | Chaque matière a `"enseignants": []` ❌ Vide |
| `/classes` | 200 | Pas d'infos enseignants |
| `/utilisateurs` | 404 | N'existe pas |
| `/users` | 404 | N'existe pas |
| `/emploi-temps` | 500 | Erreur serveur |

## Tests Effectués

1. ✅ Recherche de `prof.bede.test` dans tous les endpoints → **Introuvable**
2. ✅ Vérification des matières → `enseignants: []` **vide**
3. ✅ Vérification des classes → Aucune info enseignant
4. ✅ Test de l'endpoint `/enseignants` direct → Tableau **vide**

## Metadata KLASSCI

```json
"readonly_data": [
    "classes",
    "etudiants",
    "enseignants",  ← Marqué en lecture seule
    "planning"
]
```

Cela indique que les enseignants sont en **lecture seule** mais apparemment **non accessibles** pour le coordinateur.

## Questions pour l'Équipe KLASSCI

1. **Quel endpoint utiliser pour récupérer la liste des enseignants en tant que coordinateur ?**
   - `/enseignants` retourne un tableau vide
   - Les matières ne contiennent pas d'infos enseignants
   - Les classes ne contiennent pas d'infos enseignants

2. **Est-ce normal que le coordinateur ne puisse pas voir les enseignants ?**
   - Les statistiques indiquent `nb_enseignants: 3`
   - Mais aucun endpoint ne les expose

3. **Y a-t-il un endpoint alternatif ?**
   - `/personnel` ?
   - `/staff` ?
   - `/matieres/{id}/enseignants` ?
   - `/classes/{id}/enseignants` ?

4. **Les enseignants sont-ils accessibles via l'emploi du temps ?**
   - `/emploi-temps` retourne actuellement une erreur 500

## Solutions Temporaires Possibles

### Option 1: Retourner un tableau vide (ACTUELLE)
```php
return $this->get('enseignants', [], 3600); // Retourne []
```

**Avantages:**
- Pas de données fictives
- Cohérent avec l'API KLASSCI

**Inconvénients:**
- La page Enseignants est vide
- Utilisateur pense qu'il n'y a pas d'enseignants

### Option 2: Afficher un message d'information
Modifier le frontend pour afficher :
> "⚠️ Les données des enseignants ne sont pas disponibles via l'API KLASSCI pour le rôle coordinateur. Veuillez contacter le support KLASSCI."

### Option 3: Synchroniser les enseignants manuellement
Créer un système de synchronisation manuel où un admin peut :
1. Se connecter à KLASSCI avec un compte admin complet
2. Récupérer la liste des enseignants
3. Les stocker dans la base de données locale

## Recommendation

**Action immédiate:** Contacter l'équipe KLASSCI avec les questions ci-dessus.

**Contact KLASSCI:**
- Email: support@klassci.com (à confirmer)
- Documentation: Vérifier la doc API officielle
- Ticket support: Ouvrir un ticket avec ce document

## Code Modifié

### Backend: `app/Services/KlassciProxyService.php`

Méthode actuelle (retourne données KLASSCI brutes) :
```php
public function getEnseignants(): array
{
    return $this->get('enseignants', [], 3600); // Retourne []
}
```

### Frontend: Déjà implémenté

- Page AdminEnseignants.vue créée
- Route `/admin/enseignants` ajoutée
- Menu Sidebar mis à jour
- Service `getEnseignants()` implémenté

**Tout est prêt** côté LMS, il manque juste les données de KLASSCI.

## Prochaines Étapes

1. ⏳ Exécuter `php chercher_enseignants_klassci.php` pour tester tous les endpoints
2. ⏳ Contacter l'équipe KLASSCI
3. ⏳ Attendre leur réponse sur l'endpoint correct
4. ⏳ Implémenter la solution fournie par KLASSCI

---

**Dernière mise à jour:** 24 Octobre 2025 23:50
