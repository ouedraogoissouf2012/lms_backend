<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

/**
 * Une mesure de joignabilité vers une cible KLASSCI (#744).
 *
 * ## La donnée qui compte est `connectMs`, pas le statut HTTP
 *
 * L'hébergeur de KLASSCI avale les SYN par salves. Or les deux situations se
 * distinguent uniquement à la phase de connexion :
 *
 * - `connectMs` renseigné, même avec un statut 404 → **le serveur répond**. La
 *   route sondée n'existe peut-être pas, c'est sans importance : la couche
 *   réseau est saine.
 * - connexion expirée → **le SYN est resté sans réponse**. C'est la signature
 *   d'une règle DROP de pare-feu, et c'est précisément ce qu'un dossier de mise
 *   en liste blanche doit démontrer.
 *
 * D'où un objet plutôt qu'un booléen : un « injoignable » sans durée ni motif
 * ne vaut rien face à un hébergeur.
 */
final class ReachabilityMeasure
{
    private function __construct(
        public readonly bool $reachable,
        public readonly ?int $connectMs,
        public readonly ?int $status,
        public readonly ?string $error,
    ) {}

    public static function reached(int $connectMs, ?int $status): self
    {
        return new self(true, $connectMs, $status, null);
    }

    /**
     * @param  string  $error  Motif brut (message cURL), utile tel quel dans un ticket.
     */
    public static function unreachable(string $error): self
    {
        return new self(false, null, null, $error);
    }

    /**
     * Contexte de journalisation — la forme sous laquelle la mesure devient
     * exploitable dans un relevé horodaté.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'reachable' => $this->reachable,
            'connect_ms' => $this->connectMs,
            'status' => $this->status,
            'error' => $this->error,
        ];
    }
}
