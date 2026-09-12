<?php

declare(strict_types=1);

namespace App\Services\Retention;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ce qu'un domaine déclare pour être purgeable — et RIEN de plus (#690).
 *
 * ## Le squelette qui était recopié
 *
 * Six commandes de purge coexistaient, 612 lignes, dont trois partageant le même
 * squelette copié à la main : simulation par défaut, délai de grâce, découpage
 * en lots, trace d'audit AVANT destruction. C'est précisément l'endroit où une
 * erreur coûte le plus cher — il s'agit de destruction définitive.
 *
 * Les écarts le prouvaient : `PurgeSoftDeletedInstitutions` ne découpait PAS en
 * lots, `PurgeSeanceRecordings` n'écrivait AUCUNE trace d'audit. Ce ne sont pas
 * des décisions, ce sont des oublis de recopie — et le `graceDays()` des deux
 * commandes soft-delete était identique au caractère près.
 *
 * Pire pour l'exploitant : **trois vocabulaires** pour la même intention
 * destructive. `--force` chez les unes, `--apply` chez les autres, `--dry-run`
 * ailleurs. Sur une commande qui détruit, se tromper de drapeau n'est pas une
 * gêne.
 *
 * ## Ce que la politique décide, ce que le moteur impose
 *
 * La politique répond à quatre questions, toutes propres à son domaine : quoi
 * sélectionner, comment le décrire en simulation, comment le détruire, et quoi
 * faire d'un échec.
 *
 * Ce qu'elle ne décide PAS — et ne peut pas contourner, c'est tout l'intérêt —
 * est garanti par {@see RetentionRunner} : simulation par défaut, destruction
 * seulement sur ordre explicite, découpage en lots, trace d'audit avant
 * destruction, et jamais de planification automatique.
 *
 * Ajouter un domaine purgeable = écrire une classe et l'enregistrer. Le moteur
 * et la commande ne bougent plus.
 *
 * ## Pourquoi une politique dédiée plutôt que l'interface sur les services
 *
 * `ChapterRetentionService` et `SeanceRecordingRetentionService` portent déjà
 * cette forme. Mais leurs méthodes sont typées sur des modèles CONCRETS
 * (`Chapter`, `SeanceRecording`), et PHP interdit d'élargir un paramètre en
 * implémentant une interface : leur faire porter ce contrat casserait leurs
 * appelants. Les politiques les enveloppent donc plutôt que de les remplacer.
 */
interface RetentionPolicy
{
    /** Clé en ligne de commande, ex. `users`. Stable : elle est tapée par un humain. */
    public function key(): string;

    /** Ce que la simulation annonce, au pluriel : « utilisateur(s) supprimé(s) ». */
    public function label(): string;

    /** Délai de grâce par défaut, en jours, quand l'appelant n'en impose pas. */
    public function defaultGraceDays(): int;

    /**
     * Les lignes éligibles à la destruction, en REQUÊTE et non en collection :
     * le moteur doit pouvoir découper en lots, sinon la RAM dépend de l'arriéré.
     *
     * @return Builder<covariant Model>
     */
    public function eligible(CarbonInterface $cutoff): Builder;

    /** Une ligne lisible pour la simulation — c'est ce qu'un humain relira avant de détruire. */
    public function describe(Model $item): string;

    /**
     * Le nom de l'événement d'audit, écrit AVANT destruction : après, la cible
     * n'existe plus et la trace n'aurait plus de sujet.
     *
     * Rend `null` quand une trace PAR ÉLÉMENT n'a pas de sens. Le cas existe et
     * n'est pas théorique : purger le journal d'audit lui-même y écrirait une
     * ligne pour chaque ligne supprimée — un net nul, qui croît indéfiniment et
     * ne dit rien. Le moteur écrit alors UNE entrée de synthèse, qui est
     * l'information qu'un auditeur vient chercher.
     */
    public function auditAction(): ?string;

    /**
     * Cette ligne doit-elle être ÉPARGNÉE malgré son éligibilité ?
     *
     * Rend la raison du refus, ou `null` si la destruction peut avoir lieu. Une
     * institution qui a encore des lignes filles, par exemple : la purger
     * orphelinerait leurs données.
     *
     * Le seuil est transmis parce que la REQUÊTE ne suffit pas toujours. Un
     * enregistrement de visio encore actif n'est jamais éligible quel que soit
     * son âge, et un chapitre restauré entre la lecture du lot et son traitement
     * ne doit pas être détruit. La requête ne voit qu'un instantané ; ce second
     * filtre voit l'état au moment d'agir.
     *
     * Séparé de {@see purge()} pour une raison précise. La trace d'audit doit
     * être écrite AVANT la destruction — après, la cible n'existe plus. Mais si
     * le moteur traçait puis découvrait un refus, le journal affirmerait une
     * destruction qui n'a pas eu lieu. Interroger d'abord, tracer ensuite,
     * détruire enfin : chaque entrée d'audit correspond alors à un fait.
     */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string;

    /**
     * Détruit une ligne dont {@see refuses()} a déjà dit qu'elle pouvait l'être.
     * La trace d'audit est déjà écrite quand cette méthode est appelée.
     */
    public function purge(Model $item, CarbonInterface $cutoff): void;
}
