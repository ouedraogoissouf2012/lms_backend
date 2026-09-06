# ADR-711-05 — La Classe référence le Programme, ne le copie jamais

**Date :** 2026-09-06  
**Statut :** accepté  
**Issue :** #711

## Décision

La Classe porte inscription, progression, notes, présences et **surcharges locales**. Elle **lit** le contenu du Programme par **référence**. Si un gel est souhaité au démarrage, geler une **version** du Programme, pas dupliquer ses lignes.

## Pourquoi

LearnDash et Masteriyo recommandent de cloner le cours par cohorte → N copies divergentes, typo à corriger N fois, stats éclatées. La copie est plus simple à écrire que la référence : sans décision écrite, l'implémentation ira vers la copie.

## Conséquences

#710 : FK Classe → Programme (ou Période → Programme), **pas** de duplication de `chapters` / `lessons` par session. Trancher maintenant, implémenter dans #710.
