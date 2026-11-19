<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActivationToken;
use App\Models\User;
use App\Notifications\LienDeReinitialisationDuMotDePasse;
use App\Services\Activation\ActivationLinkBuilder;
use App\Services\Activation\ActivationTokenService;
use App\Services\Activation\UsageDuJeton;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * #808 — envoie le lien de réinitialisation d'un compte d'école autonome, s'il existe.
 *
 * ## Une tâche pour CHAQUE demande, compte connu ou inconnu
 *
 * La requête ne cherche pas le compte : elle pose cette tâche et répond. Chercher, émettre et
 * envoyer en synchrone coûterait un aller-retour SMTP pour un compte connu et rien pour un
 * inconnu : le temps de réponse dirait quelles adresses sont inscrites. Une panne ou une
 * lenteur de Brevo ne bloque pas non plus la requête.
 *
 * ## Le jeton de #803
 *
 * Le lien est le jeton à usage unique de l'ADR-803-02 (haché, consommé à l'usage), émis pour
 * {@see UsageDuJeton::Reinitialisation} : court. Il mène à `/activation/{jeton}`, la page du
 * front où le titulaire pose son mot de passe.
 *
 * ## Anti-bombardement, par compte
 *
 * Un jeton émis pour ce compte il y a moins de `activation.reinitialisation_intervalle_minutes`
 * suffit : ni nouveau jeton ni courriel. La réponse de la requête n'en dit rien.
 *
 * ## Une seule tentative
 *
 * Rejouée après un échec SMTP, la tâche trouverait le jeton qu'elle vient d'émettre et se
 * tairait, au titre de l'anti-bombardement. Mieux vaut un échec visible dans `failed_jobs`
 * (surveillé par `queue:healthcheck`) ; le titulaire peut redemander après l'intervalle.
 *
 * Aucun tenant n'est posé : la seule lecture d'école est celle du compte, filtrée explicitement.
 */
final class EnvoyerLeLienDeReinitialisation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Rester dans le budget de drain (`queue:work --max-time=55`, #539). */
    public int $timeout = 50;

    public function __construct(
        public readonly string $email,
        public readonly int $institutionId,
    ) {
        $this->onQueue('high');
    }

    public function handle(ActivationTokenService $jetons, ActivationLinkBuilder $liens): void
    {
        $compte = User::query()->withoutGlobalScope('institution')
            ->where('institution_id', $this->institutionId)
            ->where('email', $this->email)
            ->first();

        if (! $compte instanceof User || $this->lienRecent($compte)) {
            return;
        }

        $lien = $liens->pour($jetons->emettre($compte, UsageDuJeton::Reinitialisation));
        $compte->notify(new LienDeReinitialisationDuMotDePasse($lien, UsageDuJeton::minutesDeReinitialisation()));
    }

    private function lienRecent(User $compte): bool
    {
        $intervalle = config('activation.reinitialisation_intervalle_minutes', 2);
        $minutes = is_int($intervalle) && $intervalle > 0 ? $intervalle : 2;

        return ActivationToken::query()
            ->where('user_id', $compte->getKey())
            ->where('created_at', '>', now()->subMinutes($minutes))
            ->exists();
    }
}
