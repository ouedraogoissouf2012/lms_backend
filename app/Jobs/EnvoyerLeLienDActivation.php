<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Institution;
use App\Models\User;
use App\Notifications\LienDActivationDuCompte;
use App\Services\Activation\UsageDuJeton;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * #1620 — envoie au titulaire d'une école qu'on vient d'ouvrir son lien d'activation.
 *
 * ## UN jeton, UN lien
 *
 * `ActivationTokenService::emettre()` invalide les jetons précédents du compte : émettre ici un
 * second jeton, comme le fait `EnvoyerLeLienDeReinitialisation`, tuerait le lien que le supradmin
 * a reçu en secours. La tâche transporte donc le lien déjà émis, et rien n'est réémis.
 *
 * ## Une charge CHIFFRÉE
 *
 * La file est en base (`QUEUE_CONNECTION=database`) : sans `ShouldBeEncrypted`, le jeton en clair
 * dormirait dans `jobs.payload`. Chiffrée par la clé de l'application, la charge ne le montre pas.
 *
 * ## Trois tentatives, l'inverse de la réinitialisation
 *
 * `EnvoyerLeLienDeReinitialisation` n'a qu'une tentative : rejouée, elle émettrait un nouveau
 * jeton, et son anti-bombardement la ferait taire. Ici, rien n'est émis : une tentative rejouée
 * après une panne SMTP renvoie le MÊME lien. Trois essais espacés couvrent une panne passagère de
 * Brevo ; au-delà, la tâche reste dans `failed_jobs`, et le supradmin garde le lien de secours.
 *
 * Le titulaire se retrouve par SA CLÉ et son école, filtrées explicitement, sans tenant (#880).
 */
final class EnvoyerLeLienDActivation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Rester dans le budget de drain (`queue:work --max-time=55`, #539). */
    public int $timeout = 50;

    public function __construct(
        public readonly int $titulaireId,
        public readonly int $institutionId,
        public readonly string $lien,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $titulaire = User::query()->withoutGlobalScope('institution')
            ->whereKey($this->titulaireId)
            ->where('institution_id', $this->institutionId)
            ->first();
        $ecole = Institution::query()->whereKey($this->institutionId)->value('name');

        if (! $titulaire instanceof User || ! is_string($ecole)) {
            return;
        }

        $titulaire->notify(new LienDActivationDuCompte($this->lien.'-autre', $ecole, UsageDuJeton::joursDActivation()));
    }

    /** Ni le lien ni l'adresse n'entrent au journal : l'école suffit à situer la panne (SMTP, Brevo). */
    public function failed(Throwable $exception): void
    {
        app(CaughtExceptionLog::class)->error('Job EnvoyerLeLienDActivation failed', $exception, [
            'institution_id' => $this->institutionId,
        ]);
    }
}
