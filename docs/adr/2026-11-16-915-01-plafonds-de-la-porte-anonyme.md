# ADR-915-01 — Les plafonds de la porte anonyme ne comptent que les échecs

**Date :** 2026-11-16  
**Statut :** accepté  
**Issue :** #915 · amende ADR-803-03 (le seau `inscriptions`) et ADR-906-01 (le catalogue des motifs)

## Contexte

`POST /inscriptions`, la porte anonyme d'ADR-803-03, avait un seau nommé à deux bornes : 5 requêtes par minute et par
adresse, et **100 par jour pour toute la plateforme**. La borne journalière comptait **chaque requête**, réussie ou non
(`ThrottleRequests` frappe chaque borne avant le contrôleur). Une seule adresse, au rythme permis, la vidait en une vingtaine
de minutes : la porte se fermait alors pour **toutes les écoles** jusqu'au lendemain, et rien ne le distinguait d'une
rentrée chargée.

La borne globale avait une raison : freiner une énumération des codes RÉPARTIE sur de nombreuses adresses. Un code fait six
caractères (31⁶ valeurs).

Deux cas réels contredisaient ces bornes :
- **la rentrée** : une classe entière s'inscrit depuis le wifi de l'école, derrière UNE adresse (NAT). Quand l'enseignant
  dit « inscrivez-vous maintenant », 40 élèves arrivent en deux à trois minutes, soit 13 à 20 requêtes par minute. La borne
  de 5 par minute rendait 429 au 6e élève, et une borne de 10 au 11e ;
- **une adresse malveillante** fermait la porte de toutes les écoles.

## Décision

**Une inscription réussie n'est jamais un essai de trop.** Seuls les ÉCHECS (code inconnu, retiré, ou d'une école dont la
liste vient de KLASSCI : le même 404) sont comptés, sur le modèle de la porte authentifiée
(`RejoindreParCodeService::cleDesEchecs`, #885).

| Borne | Où | Compte | Au-delà |
|---|---|---|---|
| 60 requêtes / minute / adresse | seau de route `inscriptions` | toute requête | `429 ip_quota_exceeded`, avec `Retry-After` |
| 5 codes faux / minute / adresse | `EchecsDeLaPorteAnonyme` | les échecs | `429 ip_quota_exceeded` |
| 30 codes faux / jour / adresse | `EchecsDeLaPorteAnonyme` | les échecs | `429 ip_daily_cap_reached` |
| 200 codes faux / jour / école | `EchecsDeLaPorteAnonyme`, seau **partagé** avec la porte authentifiée | les échecs | `429 institution_cap_reached` |

- La borne de route ne sert plus qu'à empêcher un script de marteler la base. À 60 par minute, la classe de la rentrée passe.
- La borne par **école** remplace la borne **globale** : elle freine l'énumération répartie là où elle vise (les codes d'une
  école ne sont uniques que dans cette école), et une adresse ne peut plus éteindre que l'école qu'elle attaque, pour les
  seuls codes faux. Le seau est partagé avec la porte authentifiée : un même concept, un même plafond, un même motif
  (ADR-906-01, « un motif par concept »).
- Chaque plafond atteint est journalisé en `warning`, avec l'école et le motif, sans l'adresse.
- Les plafonds de codes faux sont des refus métier, comme celui de la porte authentifiée : ils ne portent pas `Retry-After`
  (#924).

Le motif `global_cap_reached` disparaît ; `ip_daily_cap_reached` le remplace dans le catalogue.

## Ce que cette décision coûte (déclaré)

- **Un code VALIDE qui a fuité** ouvre jusqu'à 60 comptes par minute et par adresse dans sa classe, puisque les réussites ne
  sont plus bornées. La parade existe : **le retrait du code par l'école** (`ClasseEnrolmentCodeService`). Aucune borne
  d'effectif ne s'applique aux portes aujourd'hui : `Classe::isComplete()` existe (`app/Models/Classe.php`), mais aucune
  porte ne l'appelle. L'appliquer aux deux portes est une **suite**, hors de cette décision.
- Une école attaquée par une énumération répartie voit ses deux portes fermées pour la journée, une fois ses 200 échecs
  atteints : la vérification précède la résolution, donc un élève qui saisit le BON code reçoit lui aussi le 429 tant que le
  plafond tient. C'est le prix assumé, le même que celui de la porte authentifiée depuis #885, et il est désormais borné à
  l'école attaquée au lieu de toute la plateforme.

## Preuve

`tests/Feature/Enrollment/PlafondsDeLaPorteAnonymeTest.php` :
- **40 élèves derrière une adresse en deux minutes** : 40 inscriptions, zéro 429, le compteur d'échecs de l'école à zéro ;
- après 10 inscriptions réussies, le 6e code faux de la minute est refusé (`ip_quota_exceeded`) ;
- 30 codes faux dans la journée ferment l'adresse, et elle seule (`ip_daily_cap_reached`) ;
- une école au plafond d'échecs n'éteint pas une autre école (`institution_cap_reached`).
