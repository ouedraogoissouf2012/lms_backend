<?php

/**
 * Réinitialiser toutes les séances actives à 'programmee'
 * Connexion directe MySQL
 */

echo "🔄 Réinitialisation des séances actives...\n\n";

// Connexion MySQL directe
$host = 'localhost';
$dbname = 'esbtp_lms';
$username = 'root';
$password = 'ESBTP2024';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✅ Connexion MySQL établie\n\n";

    // Mettre toutes les séances 'active' à 'programmee'
    $stmt = $pdo->prepare("
        UPDATE esbtp_seances
        SET visio_status = 'programmee',
            visio_active = 0,
            visio_started_at = NULL
        WHERE visio_status = 'active'
    ");

    $stmt->execute();
    $updated = $stmt->rowCount();

    echo "✅ $updated séance(s) réinitialisée(s) à 'programmee'\n\n";

    // Afficher l'état des séances
    $stmt = $pdo->query("
        SELECT id, klassci_seance_id, matiere_nom, visio_status, visio_enabled
        FROM esbtp_seances
        WHERE visio_enabled = 1
        ORDER BY id DESC
    ");

    $seances = $stmt->fetchAll(PDO::FETCH_OBJ);

    echo "📋 État des séances avec visio activée:\n";
    foreach ($seances as $seance) {
        echo sprintf(
            "  ID:%d | Séance#%d | %s | Status:%s\n",
            $seance->id,
            $seance->klassci_seance_id ?? 'N/A',
            $seance->matiere_nom ?? 'N/A',
            $seance->visio_status
        );
    }

    echo "\n✅ TERMINÉ!\n\n";
    echo "Maintenant:\n";
    echo "1. Rechargez le navigateur (Ctrl+Shift+R)\n";
    echo "2. Enseignant clique 'Démarrer maintenant'\n";
    echo "3. Étudiant pourra alors 'Rejoindre'\n";

} catch (PDOException $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
