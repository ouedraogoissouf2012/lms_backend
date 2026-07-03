# Archive — notes de diagnostic et correctifs (nov-déc 2025)

Notes de travail ad-hoc produites pendant le développement (diagnostics d'incidents,
scripts d'installation du scheduler sur poste Windows). Déplacées ici depuis la racine
du repo dans le cadre de l'issue #377 (hygiène racine) — la racine du dépôt n'est pas
l'endroit pour ce type de document de travail, mais leur contenu garde une valeur
forensique (pourquoi tel correctif a été fait, quel diagnostic a mené à quelle décision).

Les scripts liés au scheduler (`*.bat`, `*.ps1`, `COMMENT_INSTALLER_SCHEDULER.txt`) sont
obsolètes : `docs/DEPLOYMENT_OPS.md` est la référence versionnée actuelle pour le
cron/worker/healthcheck en production cPanel (voir #369/#370).

Refs #377.
