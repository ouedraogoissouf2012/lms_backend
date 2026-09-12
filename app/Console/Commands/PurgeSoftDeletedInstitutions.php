<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Alias historique de `purge:run institutions` (#690).
 *
 * Conservée pour les mêmes raisons que `users:purge-deleted` : elle peut figurer
 * dans une procédure d'exploitation, et c'est une commande de DESTRUCTION — la
 * supprimer ferait échouer un runbook au pire moment. Signature inchangée.
 *
 * ## Deux écarts rattrapés en déléguant
 *
 * Son corps faisait un `->get()` sur tout le trashed : la mémoire dépendait de
 * l'arriéré, seule des quatre commandes à ne pas découper en lots. Et son
 * message final annonçait comme purgées des institutions que la boucle venait
 * d'épargner — un chiffre faux sur une commande de destruction.
 *
 * Le moteur découpe désormais partout, et distingue ce qui a été détruit de ce
 * qui a été délibérément épargné.
 *
 * @see app/Services/Retention/Policies/SoftDeletedInstitutionsPolicy.php
 */
final class PurgeSoftDeletedInstitutions extends Command
{
    protected $signature = 'institutions:purge-deleted
        {--force : Exécute la purge réelle (par défaut : dry-run)}
        {--days=30 : Délai de grâce en jours avant purge définitive}';

    protected $description = 'Purge physiquement les institutions soft-deletées sans lignes filles (#567)';

    public function handle(): int
    {
        $this->comment('institutions:purge-deleted est un alias de « purge:run institutions ». Préférez la forme directe.');

        return $this->call('purge:run', [
            'domaine' => 'institutions',
            '--force' => (bool) $this->option('force'),
            '--days' => $this->option('days'),
        ]);
    }
}
