# Guide de Test - Mode Sombre du Calendrier

## Comment activer le mode sombre

1. **Trouver le bouton de basculement de thème** dans la barre de navigation (en haut)
2. **Cliquer sur l'icône de lune/soleil** pour basculer entre mode clair et mode sombre
3. Le changement est instantané et sauvegardé dans `localStorage`

## Couleurs attendues en mode sombre

### En-tête du calendrier (lignes 1347-1348)
- **Jours de la semaine (lun, mar, mer, etc.)**
  - ✅ Background: Gradient bleu foncé `#0a1929` → `#001e3c` (couleur sidebar mode sombre)
  - ✅ Texte: Blanc `#ffffff`
  - ✅ Border: `#001e3c`

### Navigation (lignes 1170-1227)
- **Boutons précédent/suivant**
  - Border: Bleu clair transparent `rgba(59, 130, 246, 0.5)`
  - Texte: Blanc `#ffffff`
  - Hover: Background bleu clair `rgba(59, 130, 246, 0.3)`, border orange `#ea580c`

- **Mois actuel (décembre 2025)**
  - Texte: Blanc `#ffffff`
  - Icône calendrier: Orange LMS `#ea580c`

- **Bouton "Aujourd'hui"**
  - Border: Orange `#ea580c`
  - Background: Bleu transparent `rgba(59, 130, 246, 0.2)`
  - Texte: Blanc `#ffffff`
  - Hover: Background orange `#ea580c`, texte bleu foncé `#1e3a8a`

- **Sélecteur de vue (Mois/Semaine/Jour)**
  - Background: Bleu foncé transparent `rgba(30, 41, 59, 0.5)`
  - Border: Bleu clair transparent `rgba(59, 130, 246, 0.5)`
  - Bouton actif: Gradient orange `#ea580c`, texte bleu foncé `#1e3a8a`
  - Icône du bouton actif: Bleu foncé `#1e3a8a`

### Filtres (lignes 1230-1288)
- **Titre "Filtres"**
  - Texte: Blanc `#ffffff`
  - Icône: Orange `#ea580c`

- **Bouton "Réinitialiser"**
  - Texte: Blanc transparent `rgba(255, 255, 255, 0.7)`
  - Hover: Background bleu clair `rgba(59, 130, 246, 0.3)`, texte orange `#ea580c`

- **Champs de sélection (Type, Période, etc.)**
  - Background: Bleu foncé transparent `rgba(30, 41, 59, 0.8)`
  - Border: Bleu clair transparent `rgba(59, 130, 246, 0.5)`
  - Texte: Blanc `#ffffff`
  - Labels: Blanc transparent `rgba(255, 255, 255, 0.8)`
  - Icônes des labels: Orange `#ea580c`
  - Hover: Border orange `#ea580c`
  - Focus: Border orange `#ea580c`, shadow orange

- **Compteur d'événements**
  - Background: Gradient orange `#ea580c`
  - Texte: Bleu foncé `#1e3a8a`
  - Icône: Bleu foncé `#1e3a8a`

### Calendrier FullCalendar (lignes 1328-1441)
- **Cases des jours**
  - Background: Bleu foncé transparent `rgba(30, 41, 59, 0.3)`
  - Border: Bleu clair transparent `rgba(59, 130, 246, 0.3)`
  - Numéros des jours: Blanc `rgba(255, 255, 255, 0.9)`

- **Jour actuel (aujourd'hui)**
  - Background: Gradient orange transparent `rgba(234, 88, 12, 0.3)` → `rgba(234, 88, 12, 0.15)`
  - Border: Orange `#ea580c` (2px)
  - Numéro du jour: Orange `#ea580c`, gras

- **Jours d'autres mois**
  - Background: Bleu très transparent `rgba(30, 41, 59, 0.15)`
  - Numéros: Blanc très transparent `rgba(255, 255, 255, 0.4)`

- **Ligne de l'heure actuelle (vue semaine/jour)**
  - Couleur: Orange `#ea580c` (2px)
  - Flèche: Orange `#ea580c`

### Événements (lignes 1399-1431)
- **Séances (bleu)**
  - Background: Bleu LMS transparent `rgba(37, 99, 235, 0.9)`
  - Border: Bleu LMS `#2563eb`
  - Texte: Blanc `#ffffff`
  - Hover: Background bleu plein `#2563eb`, shadow bleue

- **Évaluations (orange)**
  - Background: Orange LMS transparent `rgba(234, 88, 12, 0.9)`
  - Border: Orange LMS `#ea580c`
  - Texte: Bleu foncé `#1e3a8a`
  - Hover: Background orange plein `#ea580c`, shadow orange

- **Évaluations urgentes (< 24h)**
  - Background: Rouge `#ef4444` !important
  - Border: Rouge `#ef4444` !important
  - Texte: Blanc `#ffffff` !important
  - Animation: Pulse (2s infinite)

### Légende (lignes 1308-1325)
- **Titre**
  - Texte: Blanc `#ffffff`
  - Icône: Orange `#ea580c`

- **Items de légende**
  - Background: Bleu foncé transparent `rgba(30, 41, 59, 0.5)`
  - Border: Bleu clair transparent `rgba(59, 130, 246, 0.3)`
  - Texte: Blanc `#ffffff`

### Cartes (cards) générales (lignes 1163-1167)
- **Toutes les cartes**
  - Background: Bleu foncé transparent `rgba(30, 41, 59, 0.6)`
  - Border: Bleu clair transparent `rgba(59, 130, 246, 0.3)`
  - Shadow: Noir `rgba(0, 0, 0, 0.3)`

## Liste de vérification pour le test

### ✅ Test 1: Activation du mode sombre
- [ ] Cliquer sur le bouton de basculement de thème
- [ ] Vérifier que l'arrière-plan de la page devient sombre
- [ ] Vérifier que la sidebar devient bleu très foncé (#0a1929 → #001e3c)

### ✅ Test 2: En-tête du calendrier
- [ ] Vérifier que les jours de la semaine ont le même gradient que la sidebar (bleu foncé)
- [ ] Vérifier que le texte est blanc et bien visible

### ✅ Test 3: Navigation du calendrier
- [ ] Vérifier que le mois actuel s'affiche en blanc
- [ ] Vérifier que l'icône calendrier est orange
- [ ] Cliquer sur "précédent" et "suivant" - vérifier le hover orange
- [ ] Cliquer sur "Aujourd'hui" - vérifier le hover orange
- [ ] Changer de vue (Mois/Semaine/Jour) - vérifier que le bouton actif est orange

### ✅ Test 4: Filtres
- [ ] Vérifier que tous les labels sont blancs et lisibles
- [ ] Vérifier que les icônes des labels sont oranges
- [ ] Ouvrir un menu déroulant - vérifier que le fond est bleu foncé
- [ ] Survoler un champ - vérifier que la bordure devient orange
- [ ] Vérifier que le compteur d'événements est orange avec texte bleu foncé

### ✅ Test 5: Calendrier principal
- [ ] Vérifier que les cases des jours ont un fond bleu foncé transparent
- [ ] Vérifier que le jour actuel (aujourd'hui) a une bordure orange
- [ ] Vérifier que les numéros de jours d'autres mois sont très pâles
- [ ] En vue semaine/jour: vérifier que la ligne de l'heure actuelle est orange

### ✅ Test 6: Événements
- [ ] Vérifier qu'une séance s'affiche en bleu (#2563eb) avec texte blanc
- [ ] Vérifier qu'une évaluation s'affiche en orange (#ea580c) avec texte bleu foncé
- [ ] Si une évaluation urgente existe, vérifier qu'elle est rouge avec animation pulse
- [ ] Survoler un événement - vérifier l'effet de shadow

### ✅ Test 7: Légende
- [ ] Vérifier que le titre est blanc avec icône orange
- [ ] Vérifier que les items ont un fond bleu foncé transparent

### ✅ Test 8: Cartes générales
- [ ] Vérifier que toutes les cartes (navigation, filtres, calendrier, légende) ont un fond bleu foncé transparent cohérent

### ✅ Test 9: Retour au mode clair
- [ ] Re-cliquer sur le bouton de thème
- [ ] Vérifier que tout revient en mode clair
- [ ] Vérifier que l'en-tête du calendrier redevient bleu vif (#0052cc → #0747a6)

## Problèmes potentiels à surveiller

1. **Contraste insuffisant**: Si du texte blanc n'est pas lisible sur fond sombre
2. **Couleurs inversées**: Si certains éléments gardent les couleurs du mode clair
3. **Animations**: Vérifier que l'animation pulse des évaluations urgentes fonctionne
4. **Hover states**: Vérifier que tous les effets de survol (hover) sont visibles en mode sombre
5. **Transitions**: Vérifier que le passage mode clair → mode sombre est fluide

## Commandes pour tester

```bash
# Depuis le dossier lms-frontend
npm run dev

# Ouvrir dans le navigateur
# http://localhost:5173 (ou le port configuré)

# Aller sur la page du calendrier
# Se connecter et naviguer vers la vue calendrier
```

## Notes importantes

- Le mode sombre utilise `:global(html.dark)` pour détecter le thème
- Les couleurs sont cohérentes avec la sidebar en mode sombre
- L'en-tête du calendrier utilise maintenant le **même gradient que la sidebar**
- Tous les emojis ont été supprimés, seules les Material Icons sont utilisées
- Le calendrier est entièrement réactif en mode sombre
