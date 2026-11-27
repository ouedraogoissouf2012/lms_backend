<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Annonce;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Notifications\AnnonceDeClasseParCourriel;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Le courriel d'une annonce à une classe (#1650), envoyé APRÈS l'annonce, hors de la requête.
 *
 * La tâche relit l'annonce et ses destinataires, et ne garde que les comptes de L'ÉCOLE de l'annonce. Une liste
 * altérée ne fait pas écrire hors de l'école. Hors requête, aucun tenant n'est posé : la lecture est bornée
 * explicitement.
 *
 * Mise en file seulement si le canal existe (`CanalDeCourriel`). Un échec reste dans `failed_jobs` ; l'annonce et ses
 * notifications, déjà écrites, n'en dépendent pas.
 */
final class EnvoyerLAnnonceParCourriel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Rester dans le budget de drain (`queue:work --max-time=55`, #539). */
    public int $timeout = 50;

    /**
     * @param  list<int>  $destinataires  les ids LOCAUX des élèves prévenus
     */
    public function __construct(
        public readonly int $annonceId,
        public readonly array $destinataires,
    ) {}

    public function handle(): void
    {
        $annonce = Annonce::query()->withoutGlobalScope('institution')->find($this->annonceId);
        if (! $annonce instanceof Annonce || $this->destinataires === []) {
            return;
        }

        $comptes = User::query()->withoutGlobalScope('institution')
            ->whereKey($this->destinataires)
            ->where('institution_id', $annonce->institution_id)
            ->get();
        if ($comptes->isEmpty()) {
            return;
        }

        Notification::send($comptes, new AnnonceDeClasseParCourriel(
            $annonce->titre,
            $annonce->message,
            $this->nomDeLaClasse($annonce),
            $this->nomDeLEcole($annonce),
        ));
    }

    private function nomDeLaClasse(Annonce $annonce): string
    {
        $libelle = Classe::query()->withoutGlobalScope('institution')->whereKey($annonce->classe_id)->value('libelle');

        // La classe a pu être supprimée depuis : l'annonce reste adressée à « votre classe ».
        return is_string($libelle) && $libelle !== '' ? "la classe {$libelle}" : 'votre classe';
    }

    private function nomDeLEcole(Annonce $annonce): string
    {
        $nom = Institution::query()->whereKey($annonce->institution_id)->value('name');

        return is_string($nom) && $nom !== '' ? $nom : 'Votre établissement';
    }

    /** Le texte de l'annonce n'entre pas au journal : l'id suffit à la retrouver. */
    public function failed(Throwable $exception): void
    {
        app(CaughtExceptionLog::class)->error('Job EnvoyerLAnnonceParCourriel failed', $exception, [
            'annonce_id' => $this->annonceId,
        ]);
    }
}
