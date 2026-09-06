# ADR-711-06 — Commanditaire et Financement réservés, zéro écran V2

**Date :** 2026-09-06  
**Statut :** accepté  
**Issue :** #711

## Décision

Réserver les colonnes :

- **Commanditaire** : qui achète, distinct de l'apprenant
- **Financement** porté par l'inscription : source (champ **ouvert**, pas d'enum figée), montant, référence de dossier, statut de remboursement

Le **tarif vit sur la Période**, pas sur le Programme.

**Aucun écran** Commanditaire / Financement en V2.

## Pourquoi

Digiforma réifie `Customer` et `FundingAgency`. Le FDFP ivoirien (0,4 % + 1,2 %) impose le triangle entreprise / organisme habilité / financeur. Réserver les colonnes coûte une migration ; les ajouter plus tard impose une reprise sur des sessions en cours.

Tarif sur le Programme = dupliquer le contenu par variante tarifaire (défaut LearnDash par une autre porte).

## Conséquences

#710 crée les tables/colonnes. Pas de FormRequest ni de routes dans cette issue.
