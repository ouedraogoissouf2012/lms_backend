# Index de la documentation — KLASSCI LMS

> **Point d'entrée unique.** Toute la documentation du projet est référencée ici.
> Si un document n'y figure pas, c'est qu'il n'existe pas — ou qu'il a été oublié :
> l'ajouter à cet index fait partie de la PR qui le crée.

---

## Par où commencer

| Vous êtes… | Lisez d'abord |
|---|---|
| Nouveau sur le projet | [SETUP.md](SETUP.md) puis [../CONTRIBUTING.md](../CONTRIBUTING.md) |
| Sur le point d'écrire du code | [../PRODUCTION_STANDARDS.md](../PRODUCTION_STANDARDS.md) — **non négociable** |
| En train de déployer | [DEPLOY_DOKPLOY.md](DEPLOY_DOKPLOY.md) — **la cible actuelle** |
| Sur la visioconférence | [VISIO_DEPLOIEMENT.md](VISIO_DEPLOIEMENT.md) |
| En quête de la direction produit | [PLAN_AUTONOMIE_KLASSCI.md](PLAN_AUTONOMIE_KLASSCI.md) |

---

## 1. Règles de travail

| Document | Objet |
|---|---|
| [../PRODUCTION_STANDARDS.md](../PRODUCTION_STANDARDS.md) | Les 6 principes non négociables, les 15 questions avant PR |
| [../CONTRIBUTING.md](../CONTRIBUTING.md) | Workflow spec-driven, conventions git, checklist pré-commit |
| [CLAUDE_RULES_CHECKLIST.md](CLAUDE_RULES_CHECKLIST.md) | Vérifications systématiques |
| [TEAM_CHECKLIST.md](TEAM_CHECKLIST.md) | Rituels d'équipe |
| [../REFACTORING_ROADMAP.md](../REFACTORING_ROADMAP.md) | Dette technique priorisée |
| [MANIFESTE_REFACTORING.md](MANIFESTE_REFACTORING.md) | Doctrine de refonte |

## 2. Déploiement et exploitation

⚠️ **La cible est Dokploy sur VPS.** L'hébergement mutualisé cPanel a été abandonné le
2026-08-29 — voir §6.

| Document | Statut | Objet |
|---|---|---|
| [DEPLOY_DOKPLOY.md](DEPLOY_DOKPLOY.md) | ✅ **actuel** | Déploiement du LMS sur Dokploy |
| [VISIO_DEPLOIEMENT.md](VISIO_DEPLOIEMENT.md) | ✅ **actuel** | Visioconférence : architecture, secrets, pièges |
| [VISIO_JIBRI_FINALIZE.md](VISIO_JIBRI_FINALIZE.md) | ✅ actuel | Enregistrement → chapitre vidéo |
| [ENV_VARIABLES.md](ENV_VARIABLES.md) | ✅ actuel | **Toutes** les variables d'environnement |
| [INSTALLATION_SERVEUR.md](INSTALLATION_SERVEUR.md) | ⚠️ à réviser | Ne mentionne ni cPanel ni Dokploy |
| [RUNBOOK_PURGE_HISTORIQUE.md](RUNBOOK_PURGE_HISTORIQUE.md) | ✅ actuel | Procédure de purge |
| [LOAD_TESTING.md](LOAD_TESTING.md) | ✅ actuel | Tests de charge |
| [SECURITY_CI.md](SECURITY_CI.md) | ✅ actuel | Garde-fous de sécurité en intégration continue |

## 3. API

| Document | Objet |
|---|---|
| [README.md](README.md) | Index **de l'API** (OpenAPI, SDK) — à ne pas confondre avec ce fichier |
| [API_MAINTENANCE_GUIDE.md](API_MAINTENANCE_GUIDE.md) | Fonctionnement du système de documentation |
| [ADDING_NEW_ENDPOINTS.md](ADDING_NEW_ENDPOINTS.md) | Ajouter un point d'entrée |
| [API_VALIDATION.md](API_VALIDATION.md) · [API_VERSIONING.md](API_VERSIONING.md) | Validation, versionnage |
| [BREAKING_CHANGES_POLICY.md](BREAKING_CHANGES_POLICY.md) | Politique de rupture de contrat |
| [CLIENT_SDK_GENERATION.md](CLIENT_SDK_GENERATION.md) · [SDK_TESTING.md](SDK_TESTING.md) | Génération et test du SDK client |

## 4. Domaines fonctionnels

| Document | Objet |
|---|---|
| [INTEGRATION_KLASSCI.md](INTEGRATION_KLASSCI.md) | Le LMS comme satellite de KLASSCI |
| [VISIO_RECORDING_RETENTION.md](VISIO_RECORDING_RETENTION.md) | Durées de conservation des enregistrements |
| [VISIO_RECORDING_SECURITY_RGPD.md](VISIO_RECORDING_SECURITY_RGPD.md) | Sécurité et conformité des enregistrements |
| [AUDIT_LOG.md](AUDIT_LOG.md) | Journal d'audit |
| [DEPENDENCY_GRAPH.md](DEPENDENCY_GRAPH.md) | Graphe de dépendances |

## 5. Direction produit

| Document | Objet |
|---|---|
| [PLAN_AUTONOMIE_KLASSCI.md](PLAN_AUTONOMIE_KLASSCI.md) | **Rendre le LMS utilisable sans KLASSCI.** Benchmark, architecture cible, décisions actées (hébergement, modèle économique), conformité ARTCI/CIL |
| [REVERSE_ENGINEERING.md](REVERSE_ENGINEERING.md) | Rétro-ingénierie de l'existant |
| [axe1-racine-analyse.md](axe1-racine-analyse.md) | Analyse de l'enveloppe JSON |
| [ENVELOPE_JSON_UNIFICATION_PLAN.md](ENVELOPE_JSON_UNIFICATION_PLAN.md) | Unification du format de réponse |

> **Ce plan vivait hors de Git** jusqu'au 31 août, dans `~/.claude/plans/` sous un nom
> généré aléatoirement. Il contient des décisions engageantes : il a sa place ici.

## 6. Périmé — conservé pour mémoire, **ne pas suivre**

L'hébergement mutualisé cPanel a été abandonné (décision du 2026-08-29). Ces documents
décrivent une infrastructure qui n'est plus la cible, et leurs contraintes — pas de
worker permanent, drain de 55 s, pas de Redis — **ne s'appliquent plus**.

| Document | Pourquoi il induit en erreur |
|---|---|
| [DEPLOIEMENT_CPANEL.md](DEPLOIEMENT_CPANEL.md) | Procédure cPanel |
| [DEPLOYMENT_OPS.md](DEPLOYMENT_OPS.md) | Exploitation cPanel (scheduler, worker par cron) |
| [../GUIDE_DEPLOIEMENT_PRODUCTION.md](../GUIDE_DEPLOIEMENT_PRODUCTION.md) | Guide de déploiement cPanel |
| [CPANEL_SCALABILITY_PLAN.md](CPANEL_SCALABILITY_PLAN.md) | Plan de montée en charge sur mutualisé |
| [VISIO_RECORDING_CPANEL_DECISION.md](VISIO_RECORDING_CPANEL_DECISION.md) | **Factuellement faux** : affirme que le backend ne doit pas dépendre d'un Jitsi/Jibri auto-hébergé — c'est exactement ce qui tourne en production depuis le 2026-08-31 |

**Décision en attente** : les archiver dans `docs/archive/` ou les supprimer. Les
laisser au même niveau que la documentation vivante est le vrai risque — quelqu'un
suivra le mauvais guide.

## 7. Notes de session — valeur historique uniquement

| Document |
|---|
| [SESSION_COMPREHENSIVE_RECORD.md](SESSION_COMPREHENSIVE_RECORD.md) |
| [START_IMPROVEMENTS.md](START_IMPROVEMENTS.md) · [IMPROVEMENT_PRIORITIES.md](IMPROVEMENT_PRIORITIES.md) |
| [PROD_ITEMS_REVISED.md](PROD_ITEMS_REVISED.md) · [SHOULD_HAVE_ITEMS_REVISED.md](SHOULD_HAVE_ITEMS_REVISED.md) · [COULD_HAVE_ITEMS_REVISED.md](COULD_HAVE_ITEMS_REVISED.md) |
| [TODO_REVISED_EXAMPLE.md](TODO_REVISED_EXAMPLE.md) · [EXECUTION_GUIDE.md](EXECUTION_GUIDE.md) |
| [../CRITICAL-05_TIER1_COMPLETION.md](../CRITICAL-05_TIER1_COMPLETION.md) · [../OPENAPI_FIX_LOG.md](../OPENAPI_FIX_LOG.md) · [../DOCUMENTATION_PACKAGE.md](../DOCUMENTATION_PACKAGE.md) |
| [archive/2025-notes-diagnostic/](archive/2025-notes-diagnostic/) — 13 notes de diagnostic 2025, déjà archivées |

---

## 8. Spécifications

**57 spécifications** dans [`.claude/specs/`](../.claude/specs/), une par chantier, au
format exigences → conception → tâches ([CONTRIBUTING.md §A](../CONTRIBUTING.md)).

Elles ne sont pas listées ici : leur nom porte le numéro d'issue
(`469-jibri-recording-finalize`, `598-chapter-artifacts-private`…). Pour retrouver la
spécification d'un chantier, chercher son numéro d'issue.

⚠️ `/.claude/specs/` figure dans `.gitignore`, mais **171 fichiers y sont versionnés** :
la convention du projet est de les forcer (`git add -f`). Ne pas s'y tromper en créant
une nouvelle spécification.

---

## 9. Ce qui vit hors du dépôt

| Emplacement | Contenu | Faut-il le rapatrier ? |
|---|---|---|
| `~/.claude/plans/` | Plans en mode conception, **noms générés aléatoirement** | Oui dès qu'ils portent une décision — comme [PLAN_AUTONOMIE_KLASSCI.md](PLAN_AUTONOMIE_KLASSCI.md) |
| `~/.claude/projects/…/memory/` | 60 notes de mémoire d'assistant | Non — contexte de travail, pas documentation |
| `~/Documents/*.pdf` | Rapports générés | Non — régénérables depuis le dépôt |

**La règle** : un document qui engage une décision, décrit une procédure ou explique un
choix d'architecture appartient au dépôt. Le reste peut vivre ailleurs.
