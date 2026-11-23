<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\Chapter;
use App\Models\Seance;
use DateTimeInterface;

/**
 * Le titre du chapitre qui porte l'enregistrement d'une séance (#854).
 *
 * ## Le défaut corrigé
 *
 * Le titre était `Recording - <titre de la séance>`. Or le titre d'une séance est celui de
 * sa MATIÈRE : mesuré en production le 2026-09-18, deux séances de « Marketing digital »
 * donnaient deux chapitres « Recording - Marketing digital ». Sur un semestre, vingt
 * chapitres du même nom, et le cours d'une date donnée introuvable.
 *
 * ## La règle
 *
 * - Le libellé : le titre de la séance, sinon sa matière, sinon « Séance ».
 * - Puis la date ET l'heure (deux séances le même jour) : `date_seance`, sinon le début de
 *   la visio. Sans aucune date, le numéro LOCAL de la séance, qui existe aussi pour une
 *   séance d'école autonome, sans `klassci_seance_id`.
 * - Dans le fuseau de l'application.
 *
 * ## Un renommage de l'enseignant n'est plus écrasé
 *
 * Le titre écrit automatiquement est noté dans `notes_enseignant.titre_auto`. À
 * l'enregistrement suivant de la même séance, le titre n'est remplacé que s'il est encore
 * celui-là, ou l'ancien défaut exact d'avant #854. Sinon l'enseignant l'a renommé, et il
 * reste. Un titre fourni par le fournisseur suit la même règle.
 */
final class TitreDEnregistrement
{
    /** La clé de `notes_enseignant` qui garde le dernier titre écrit automatiquement. */
    public const NOTE = 'titre_auto';

    /** Le titre qu'écrirait l'automatisme : celui du fournisseur s'il en donne un. */
    public function automatique(Seance $seance, ?string $fourni): string
    {
        if ($fourni !== null) {
            return $fourni;
        }

        $libelle = $this->premierRenseigne([$seance->titre]) ?? 'Séance';

        return 'Enregistrement - '.$libelle.' - '.$this->quand($seance);
    }

    /** Le titre que le chapitre doit porter : l'automatique, sauf s'il a été renommé. */
    public function retenu(Chapter $chapitre, Seance $seance, string $automatique): string
    {
        if (! $chapitre->exists || $this->estEncoreAutomatique($chapitre, $seance)) {
            return $automatique;
        }

        return (string) $chapitre->title;
    }

    private function estEncoreAutomatique(Chapter $chapitre, Seance $seance): bool
    {
        $notes = $chapitre->notes_enseignant;
        $note = is_array($notes) ? ($notes[self::NOTE] ?? null) : null;

        if (is_string($note)) {
            return $chapitre->title === $note;
        }

        return $chapitre->title === $this->ancienDefaut($seance);
    }

    /** Le titre d'avant #854, à l'identique : un chapitre qui le porte n'a pas été renommé. */
    private function ancienDefaut(Seance $seance): string
    {
        return 'Recording - '.($seance->titre ?: 'Seance '.$seance->klassci_seance_id);
    }

    private function quand(Seance $seance): string
    {
        foreach ([$seance->date_seance, $seance->visio_started_at] as $date) {
            if ($date instanceof DateTimeInterface) {
                return $date->format('d/m/Y H:i');
            }
        }

        return 'séance n°'.$seance->id;
    }

    /** @param  list<mixed>  $valeurs */
    private function premierRenseigne(array $valeurs): ?string
    {
        foreach ($valeurs as $valeur) {
            if (is_string($valeur) && trim($valeur) !== '') {
                return $valeur;
            }
        }

        return null;
    }
}
