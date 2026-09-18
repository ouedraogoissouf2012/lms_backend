<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Tests\TestCase;

/**
 * #831 — le démarrage joue les migrations : garde contre le retour du défaut.
 *
 * ## Ce que ces tests figent, et pourquoi
 *
 * Le correctif de #831 vit dans un script shell. Rien, dans la CI, ne le
 * protège : un `git revert` malheureux ou une simplification bien intentionnée
 * le retirerait sans qu'un seul test ne rougisse. Or le défaut qu'il ferme
 * était invisible **par nature** — tout paraissait déployé et vert.
 *
 * Mesuré en production le 2026-09-16 : cinq migrations en attente, dont celle
 * du correctif de sécurité #824. Ce correctif était donc **déployé et inerte**,
 * l'enregistrement d'une séance restant téléchargeable sans compte (HTTP 200,
 * 2 409 692 octets) pendant que le code attendait déjà un chemin privé.
 * L'application était à la fois vulnérable et cassée.
 *
 * Le dépôt garde déjà ses fichiers non-PHP de cette manière — voir le test qui
 * vérifie que le `.htaccess` refuse bien les diapositives (#620).
 *
 * ## Les trois propriétés gardées
 *
 * 1. **Un seul rôle migre.** Les trois services partagent l'image et démarrent
 *    ensemble ; deux `migrate` concurrents se courent après sur la table
 *    `migrations`. Le propriétaire est celui qui sert le trafic.
 * 2. **Sous `www-data`.** L'entrypoint tourne en root (aucune directive `USER`
 *    dans `Dockerfile.prod`). Une migration qui écrit des fichiers les créerait
 *    en `drwx------ root`, illisibles par Apache : mesuré le même jour, la
 *    lecture légitime d'un enregistrement rendait 404 jusqu'au `chown` manuel.
 * 3. **L'échec est bruyant.** Un conteneur qui refuse de démarrer se voit en
 *    trente secondes ; une base non migrée est restée invisible plusieurs jours.
 */
final class EntrypointMigrationsTest extends TestCase
{
    private function entrypoint(): string
    {
        return (string) file_get_contents(base_path('docker/entrypoint.sh'));
    }

    /** @return string la portion exécutée par le rôle `web` */
    private function brancheWeb(): string
    {
        $contenu = $this->entrypoint();
        $debut = (int) strpos($contenu, 'web|*)');

        return substr($contenu, $debut);
    }

    public function test_le_demarrage_joue_les_migrations(): void
    {
        self::assertStringContainsString('migrate --force', $this->entrypoint());
    }

    public function test_seul_le_role_web_migre(): void
    {
        $contenu = $this->entrypoint();
        $avantAiguillage = substr($contenu, 0, (int) strpos($contenu, 'case "$role"'));

        // Dans le tronc commun, les TROIS conteneurs migreraient en parallele.
        self::assertStringNotContainsString('migrate --force', $avantAiguillage);
        self::assertStringContainsString('migrate --force', $this->brancheWeb());

        // Et jamais dans les roles worker/scheduler, qui demarrent en meme temps.
        $roles = substr($contenu, (int) strpos($contenu, 'case "$role"'));
        $worker = substr($roles, 0, (int) strpos($roles, 'web|*)'));
        self::assertStringNotContainsString('migrate --force', $worker);
    }

    public function test_les_migrations_ne_tournent_pas_en_root(): void
    {
        // Sans cela, tout fichier cree par une migration devient illisible par
        // Apache. C'est le defaut exact rencontre le 2026-09-16.
        self::assertStringContainsString('www-data', $this->brancheWeb());
    }

    public function test_l_echec_d_une_migration_empeche_de_servir(): void
    {
        $contenu = $this->entrypoint();

        // `set -e` interrompt le script ; encore faut-il que l'appel ne soit pas
        // neutralise comme les commandes best-effort voisines (`storage:link`).
        self::assertStringContainsString('set -e', $contenu);
        self::assertDoesNotMatchRegularExpression('/migrate --force[^\n]*\|\| true/', $contenu);
    }

    public function test_les_migrations_precedent_le_service_du_trafic(): void
    {
        $web = $this->brancheWeb();

        // On ne repond jamais a une requete sur un schema qui ne correspond pas.
        self::assertLessThan(
            (int) strpos($web, 'apache2-foreground'),
            (int) strpos($web, 'migrate --force'),
            'la migration doit preceder le demarrage du serveur',
        );
    }

    /**
     * #673 — le worker ne doit pas ecrire en root.
     *
     * ## Le defaut mesure
     *
     * `ImportJibriRecordingMedia` copie l'enregistrement depuis Jibri vers le
     * disque PRIVE (#824). Le conteneur worker demarrant en root, Laravel creait
     * l'arborescence avec la visibilite privee par defaut de Flysystem :
     *
     *   drwx------ root root  storage/app/private/recordings/4
     *
     * Apache tourne sous `www-data` et ne peut pas traverser ce repertoire.
     * Mesure en production le 2026-09-18, sur le premier enregistrement mene de
     * bout en bout : le media etait bien la, 8 075 548 octets, et la route
     * signee rendait 404. AUCUNE video n'aurait ete lisible.
     *
     * C'est la consequence directe du passage au disque prive : tant que les
     * fichiers etaient servis par Apache depuis `storage/app/public`, personne
     * ne s'en apercevait — ils n'etaient de toute facon pas proteges.
     *
     * Le scheduler suit la meme regle : `recordings:purge` et l'archivage
     * touchent les memes arborescences.
     */
    public function test_le_worker_n_ecrit_pas_en_root(): void
    {
        $roles = $this->rolesDeLEntrypoint();

        // Les roles DELEGUENT : c'est l'aide partagee qui abandonne les
        // privileges, pour que les trois suivent la meme regle.
        self::assertStringContainsString('sous_www_data', $roles['worker']);
        self::assertStringContainsString('sous_www_data', $roles['scheduler']);

        // Et cette aide fait bien ce que son nom promet.
        //
        // Borne au CORPS de l'aide. Une premiere version cherchait
        // `su ... www-data` dans tout le fichier avec l'option `/s` : le `.*`
        // traversait le script et retombait sur le `su` du role `web`.
        // Falsifiee en retirant le `su` de l'aide, la garde restait VERTE.
        self::assertStringContainsString('su -s /bin/sh www-data', $this->corpsDeLAide());
    }

    public function test_chaque_role_gere_le_cas_non_root(): void
    {
        // Meme contrat que #831 pour `web` : `su` echouerait si le conteneur
        // tournait deja sous un utilisateur non privilegie.
        // `web` teste `id -u` en propre ; `worker` et `scheduler` heritent du
        // meme repli par l'aide partagee. Les deux formes sont acceptables,
        // c'est la PROPRIETE qui compte : aucun role ne suppose etre root.
        $contenu = $this->entrypoint();
        self::assertSame(
            2,
            substr_count($contenu, 'id -u'),
            "chaque chemin de lancement doit savoir s'il tourne en root",
        );

        $roles = $this->rolesDeLEntrypoint();
        self::assertStringContainsString('id -u', $roles['web']);
    }

    /** Corps de `sous_www_data`, borne a ses propres accolades. */
    private function corpsDeLAide(): string
    {
        $contenu = $this->entrypoint();
        $debut = strpos($contenu, 'sous_www_data() {');
        self::assertNotFalse($debut, 'l\'aide sous_www_data doit exister');

        // La fonction se termine a la premiere accolade en colonne 0.
        $fin = strpos($contenu, PHP_EOL.'}', $debut);

        return substr($contenu, $debut, $fin - $debut);
    }

    /**
     * Decoupe l'aiguillage par role.
     *
     * @return array<string, string>
     */
    private function rolesDeLEntrypoint(): array
    {
        $contenu = $this->entrypoint();
        $apres = substr($contenu, (int) strpos($contenu, 'case "$role"'));
        $morceaux = preg_split('/^\s{2}(worker|scheduler|web\|\*)\)/m', $apres, -1, PREG_SPLIT_DELIM_CAPTURE);

        $roles = [];
        for ($i = 1; $i < count($morceaux); $i += 2) {
            $nom = $morceaux[$i] === 'web|*' ? 'web' : $morceaux[$i];
            $roles[$nom] = $morceaux[$i + 1];
        }

        return $roles;
    }
}
