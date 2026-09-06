# Lexique V2 — Programme / Période / Créneau / Classe

**Version :** 1.0  
**Date :** 2026-09-06  
**Issue :** #711 (épique #697)

Ce lexique s'applique aux **tables**, **segments d'URL**, **clés i18n** et **commentaires de code**. Le renommer après coup toucherait des dizaines de tables : le figer maintenant coûte une page.

| Terme | Signifie | Ne signifie pas |
|---|---|---|
| **Programme** | Le contenu pédagogique versionnable (cours, chapitres, quiz). Sans dates de session. | Une promotion, une cohorte, un run. |
| **Période** (`training_session`) | Le parcours **daté** qui contient les créneaux. Tarif, inscriptions, assiduité, recordings. | Une réunion live. Chez Open edX / Moodle, « cohort » désigne autre chose. |
| **Créneau** | Une occurrence datée sous la Période : début, fin, lieu ou salle virtuelle, formateur. Assiette de l'émargement et de l'heure-stagiaire. | La Période entière. |
| **Classe** | Un **regroupement d'apprenants** (inscription, notes, présences, surcharges locales). Lit le Programme **par référence**. | Un conteneur temporel. Ne clone pas le contenu. |
| **Séance** (`seance`) | Une **réunion live** (visio / présentiel). Déjà le mot du code. | Le parcours daté (`training_session`). |

Rappel Open edX / Moodle : *cohort* = sous-groupe ou lot d'inscrits **sans** dates de run. Nous n'employons **pas** « cohorte » pour la Période.

Les tables existantes `seances`, `classes`, `classe_etudiant` gardent leur nom jusqu'à une migration #710 calée sur ce lexique.
