<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Enums\TrainingSessionStatus;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Création et lecture des Programmes et des Périodes (#827).
 *
 * #800 a livré les tables ; rien ne permettait de s'en servir. Ce service est
 * le premier chemin d'écriture de l'entité racine du monde autonome.
 *
 * ## L'établissement vient de l'acteur, jamais de la charge utile
 *
 * `BelongsToInstitution` poserait `institution_id` depuis le tenant résolu —
 * mais il est fail-open : sans tenant, il journalise et laisse passer, ce qui
 * insérerait une ligne orpheline sur des tables dont la colonne est NOT NULL.
 * On l'écrit donc explicitement depuis l'acteur, dont l'identité est certaine.
 *
 * ## Le statut n'est pas un champ de formulaire
 *
 * Une Période naît **brouillon**. Publier engage des inscriptions : c'est une
 * décision distincte, qui aura sa propre opération et sa propre trace.
 */
final class TrainingSessionCrudService
{
    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    public function creerProgramme(User $acteur, string $titre, ?string $description): Program
    {
        $programme = new Program;
        $programme->institution_id = $this->etablissementDe($acteur);
        $programme->titre = $titre;
        $programme->description = $description;
        $programme->version = 1;
        $programme->save();

        return $programme;
    }

    /**
     * @param  array<string, mixed>  $valide  Charge déjà validée par le FormRequest.
     */
    public function creerPeriode(User $acteur, array $valide): TrainingSession
    {
        $periode = new TrainingSession;
        $periode->fill($valide);

        // Écrits APRÈS le fill : ni l'un ni l'autre n'est dictable par le client.
        $periode->institution_id = $this->etablissementDe($acteur);
        $periode->status = TrainingSessionStatus::Brouillon;

        $periode->save();

        return $periode->load(self::avecSonProgramme());
    }

    /**
     * Les Programmes de l'établissement, pour choisir celui d'une Période (#1206).
     * Ordre stable : `titre`, puis `id` à titre égal.
     *
     * @return LengthAwarePaginator<int, Program>
     */
    public function listerLesProgrammes(User $acteur, int $parPage): LengthAwarePaginator
    {
        return Program::query()
            ->where('institution_id', (int) $acteur->institution_id)
            ->select(['id', 'titre', 'version'])
            ->orderBy('titre')
            ->orderBy('id')
            ->paginate($parPage);
    }

    /**
     * Le Programme d'une Période, chargé en UNE requête pour toute la page (#1206).
     * Supprimé en douceur compris : son titre reste l'histoire de la Période.
     *
     * @return array<string, \Closure>
     */
    private static function avecSonProgramme(): array
    {
        return ['program' => static fn ($programme) => $programme->withTrashed()->select(['id', 'titre'])];
    }

    /**
     * Les archivées n'y sont que sur demande, les purgées jamais (#1208).
     *
     * @return LengthAwarePaginator<int, TrainingSession>
     */
    public function listerLesPeriodes(User $acteur, int $parPage, bool $avecLesArchivees = false): LengthAwarePaginator
    {
        $ecartes = array_map(
            static fn (TrainingSessionStatus $statut): string => $statut->value,
            TrainingSessionStatus::horsDeLaListe($avecLesArchivees),
        );

        return TrainingSession::query()
            ->where('institution_id', $this->etablissementDe($acteur))
            ->whereNotIn('status', $ecartes)
            ->with(self::avecSonProgramme())
            ->orderByDesc('starts_on')
            ->paginate($parPage);
    }

    /**
     * L'acteur DOIT porter un établissement : ces tables l'exigent en base.
     * Un compte plateforme n'a rien à créer ici — il valide des demandes, il
     * n'anime pas de formation.
     */
    private function etablissementDe(User $acteur): int
    {
        // #1184 : une session de formation est l'offre pédagogique de l'école,
        // tenue par le catalogue local ; une école KLASSCI la tient dans KLASSCI.
        // L'établissement est celui de l'ACTEUR : le service borne même sans tenant.
        return $this->porte->etablissementDe($acteur);
    }
}
