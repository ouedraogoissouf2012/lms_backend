<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * #824 — déplace les enregistrements déjà importés vers le disque privé.
 *
 * ## Pourquoi une migration de données est indispensable
 *
 * Le changement de disque ne vaut que pour les imports à venir. Les médias
 * déjà copiés restent sous `storage/app/public/recordings/`, que le serveur
 * web sert sans passer par Laravel : sans ce déplacement, le correctif ne
 * protégerait que les enregistrements futurs, et la vidéo existante resterait
 * téléchargeable par quiconque en détient l'adresse.
 *
 * Symétriquement, les colonnes portent une URL absolue (`https://.../storage/
 * recordings/...`) là où le code attend désormais un chemin relatif. Sans
 * réécriture, ces chapitres rendraient 404 — sûr, mais cassé.
 *
 * ## Idempotente et non destructive
 *
 * Chaque ligne est traitée seulement si sa valeur ressemble encore à une URL.
 * Le fichier n'est supprimé de l'ancien disque qu'après copie vérifiée : une
 * interruption laisse au pire un doublon, jamais un média perdu.
 */
return new class extends Migration
{
    private const MARQUEUR = '/storage/';

    public function up(): void
    {
        $this->deplacer('chapters', 'video_url', "video_provider = 'jibri'");
        $this->deplacer('seance_recordings', 'recording_url', "provider = 'jibri'");
    }

    /**
     * Irréversible **par choix** : remettre ces fichiers sur le disque public
     * rouvrirait la faille que cette migration ferme. Un `down()` qui ne fait
     * rien est plus honnête qu'un `down()` qui ré-expose.
     */
    public function down(): void {}

    private function deplacer(string $table, string $colonne, string $condition): void
    {
        $lignes = DB::table($table)
            ->whereRaw($condition)
            ->whereNotNull($colonne)
            ->where($colonne, 'like', '%'.self::MARQUEUR.'recordings/%')
            ->get(['id', $colonne]);

        foreach ($lignes as $ligne) {
            $chemin = $this->cheminRelatif((string) $ligne->{$colonne});

            if ($chemin === null) {
                continue;
            }

            $this->copierVersLePrive($chemin);

            DB::table($table)->where('id', $ligne->id)->update([$colonne => $chemin]);
        }
    }

    /** Extrait `recordings/…` d'une URL absolue, ou `null` si la forme surprend. */
    private function cheminRelatif(string $valeur): ?string
    {
        $position = strpos($valeur, self::MARQUEUR.'recordings/');

        if ($position === false) {
            return null;
        }

        return substr($valeur, $position + strlen(self::MARQUEUR));
    }

    private function copierVersLePrive(string $chemin): void
    {
        $public = Storage::disk('public');
        $prive = Storage::disk('local');

        if (! $public->exists($chemin) || $prive->exists($chemin)) {
            return;
        }

        $flux = $public->readStream($chemin);

        if ($flux === null) {
            return;
        }

        try {
            // Copie d'abord, effacement ensuite : une interruption laisse un
            // doublon, jamais un enregistrement perdu.
            if ($prive->writeStream($chemin, $flux)) {
                $public->delete($chemin);
            }
        } finally {
            if (is_resource($flux)) {
                fclose($flux);
            }
        }
    }
};
