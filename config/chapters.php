<?php

return [
    // #674 — Durée pendant laquelle un chapitre mis à la corbeille reste
    // récupérable. Passé ce délai, la ligne ET ses fichiers sont détruits
    // définitivement, sur les deux disques.
    //
    // Pourquoi 30 jours. La corbeille (#689) existe pour rattraper une erreur,
    // pas pour archiver : au-delà d'un mois, une suppression n'est plus un
    // accident mais une décision. Et la durée doit rester COURTE pour une raison
    // qui n'est pas de confort — tant qu'un chapitre supprimé existe, ses
    // diapositives restent énumérables sans authentification, leurs URL étant
    // prédictibles (#598).
    //
    // Contrainte réglementaire à ne pas perdre de vue : conserver au-delà de la
    // durée DÉCLARÉE est une infraction en soi (loi burkinabè 001-2021). Ne
    // déclarer ici que ce que la commande `chapters:purge` sait réellement
    // appliquer.
    'retention_days' => (int) env('CHAPTERS_RETENTION_DAYS', 30),

    // Taille des lots parcourus par `chapters:purge`. Chaque chapitre entraîne
    // une transaction et deux suppressions de dossier : les lots restent petits
    // pour ne pas tenir un verrou long sur une table de contenu.
    'purge_chunk_size' => (int) env('CHAPTERS_PURGE_CHUNK_SIZE', 100),
];
