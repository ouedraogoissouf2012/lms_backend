# ADR-687-02 — Seule une réponse KLASSCI conclusive produit « identifiants incorrects »

**Date :** 2026-11-22  
**Statut :** accepté  
**Issue :** #687 (critère « KLASSCI injoignable, compte potentiellement valide → 503 »), amende ADR-687-01

## Décision

Une réponse de KLASSCI, pendant la connexion, est **conclusive** ou **indéterminée**.

| Appel | Conclusive | Indéterminée |
|---|---|---|
| `POST {école}/auth/check-user` | 2xx lisible, `data.found` booléen | transport, tout non-2xx, corps illisible |
| `POST {école}/auth/login` | 2xx `success: true` (succès) ; 4xx hors 408 et 429, ou 2xx `success: false` (refus) | transport, 5xx, 408, 429, corps illisible |

- **Découverte** (`KlassciTenantDiscovery`) : si aucune école n'a trouvé le compte et qu'au moins une est indéterminée, `KlassciUnavailableException` (503). « Toutes injoignables » (#243) en est le cas extrême.
- **Connexion** (`KlassciAuthClient::attemptLogin`) : `null` ne veut plus dire que « refus conclusif ». Une réponse indéterminée lève `KlassciUnavailableException`.
- **Orchestrateur** (`LoginOrchestrator::attemptKlassci`) : si aucune école trouvée n'accepte et qu'au moins une a été indéterminée, la panne est relevée (503). Sinon, 401.
- **Compte local lié** (`refreshLinkedKlassciLogin`) : le compte est déjà authentifié en local ; une panne garde le repli local d'aujourd'hui.

## Pourquoi

L'ADR-687-01 a fermé l'oracle de temps local. Le symptôme de l'issue restait par deux chemins :

1. une école sautée en silence (injoignable, 5xx, 429 du filtre anti-rafale de KLASSCI, corps illisible) comptait pour « inconnu ». Avec une autre école qui disait « inconnu », la connexion rendait 401 ;
2. un compte trouvé par `check-user`, dont `/auth/login` tombait en panne, recevait le `null` d'un mauvais mot de passe, donc 401.

Dans les deux cas, un compte peut-être valide lisait « identifiants incorrects » pendant une panne. C'est le défaut que l'issue décrit, et le critère 3 de son tableau l'interdit.

## Énumération

- Le 503 de la découverte ne dépend que de la disponibilité des écoles, pas de l'identifiant.
- Le 503 de la connexion n'apparaît que pour un compte trouvé par `check-user`. Il dit donc « ce compte existe » pendant une panne de `/auth/login`. Mais `check-user` le dit déjà à quiconque (point suivant). C'est aussi la réponse que l'issue exige.

## L'oracle KLASSCI n'est pas fermé dans le LMS

Un identifiant inconnu de KLASSCI coûte un appel sortant (`check-user`), un identifiant connu en coûte deux (`check-user` puis `login`) : l'ADR-687-01 laissait #687 ouverte pour cet écart.

La découverte appelle `check-user` **sans aucun jeton** (`KlassciTenantDiscovery::findMatchingTenants`). Ce point d'entrée de KLASSCI répond donc à n'importe qui : un attaquant l'interroge directement, sans passer par le LMS. Égaliser les appels côté LMS ne lui retirerait rien. Les deux fermetures possibles dans le LMS sont pires :
- un `login` leurre enverrait l'identifiant et le mot de passe à une école qui ne connaît pas le compte (contre #1318 et #1334) ;
- un plancher de durée immobiliserait un travailleur PHP jusqu'à 10 s (ADR-687-01, `Timebox` écarté).

L'oracle appartient à KLASSCI, hors de ce dépôt. Il est signalé au user, qui porte la relation avec KLASSCI.

## Conséquences

- `KlassciAuthClient::attemptLogin` lève désormais `KlassciUnavailableException`. Ses deux appelants le traitent.
- Le journal distingue chaque cas indéterminé (école, statut ou catégorie), sans jamais l'identifiant en clair ni le mot de passe.
- Les écoles autonomes ne sont jamais interrogées (#1318) : rien ne change pour elles.
