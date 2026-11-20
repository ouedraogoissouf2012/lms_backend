<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Contracts\Config\Repository;

/**
 * Le canal de courriel peut-il réellement délivrer un lien (#808) ? Lu en UN seul endroit.
 *
 * Disponible si, et seulement si :
 * - le mailer envoie vraiment (ni `log`, le défaut de la prod, ni `array`, celui des tests) ;
 * - un expéditeur est posé, autre que le défaut de Laravel (`hello@example.com`) ;
 * - pour le SMTP (Brevo), l'hôte et l'identifiant sont posés ;
 * - l'URL du front est posée : sans elle, le lien serait mort (`ActivationLinkBuilder`).
 *
 * Sinon « mot de passe oublié » n'affirme aucun envoi. Les valeurs viennent de l'environnement,
 * posées par le user dans Dokploy (docs/ENV_VARIABLES.md) ; aucune n'est dans le dépôt.
 */
final class CanalDeCourriel
{
    private const MAILERS_MUETS = ['log', 'array'];

    private const EXPEDITEUR_PAR_DEFAUT = 'hello@example.com';

    public function __construct(private readonly Repository $config) {}

    public function estDisponible(): bool
    {
        $mailer = $this->texte('mail.default');

        return $mailer !== '' && ! in_array($mailer, self::MAILERS_MUETS, true)
            && ! in_array($this->texte('mail.from.address'), ['', self::EXPEDITEUR_PAR_DEFAUT], true)
            && ($mailer !== 'smtp' || ($this->texte('mail.mailers.smtp.host') !== '' && $this->texte('mail.mailers.smtp.username') !== ''))
            && $this->texte('activation.url_front') !== '';
    }

    private function texte(string $cle): string
    {
        $valeur = $this->config->get($cle);

        return is_string($valeur) ? trim($valeur) : '';
    }
}
