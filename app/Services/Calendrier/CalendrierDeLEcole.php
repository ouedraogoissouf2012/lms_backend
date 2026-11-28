<?php

declare(strict_types=1);

namespace App\Services\Calendrier;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\EvenementCalendrier;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Le calendrier de l'année d'une école (#1671, ARRÊT 1 validé le 28/11).
 *
 * - Les DEUX volets : KLASSCI n'expose aucun calendrier, le calendrier est une donnée du LMS. Aucune porte du catalogue
 *   local ne le garde (écart assumé, décidé à l'ARRÊT 1).
 * - La portée limite la LECTURE (Q3) : la gestion lit tout ; un enseignant lit l'école entière et les classes où il
 *   enseigne ; tout autre membre (la portée d'un apprenant) l'école entière et ses classes ACTIVES. Les classes d'un
 *   compte viennent de {@see EnrollmentSource}, la règle qui sert déjà l'admission et l'audience des séances.
 * - Toute lecture et toute écriture sont bornées EXPLICITEMENT à l'école de l'acteur.
 */
final class CalendrierDeLEcole
{
    public const CLASSE_HORS_ECOLE = 'classe_hors_ecole';

    public function __construct(
        private readonly EnrollmentSource $inscriptions,
        private readonly ConnectionInterface $db,
    ) {}

    /** @return list<array<string, mixed>> les événements qui touchent [du, au], que l'acteur peut lire */
    public function lire(User $acteur, string $du, string $au): array
    {
        $evenements = $this->deLEcole($this->ecoleDe($acteur))
            ->whereDate('debut', '>=', $du)->whereDate('fin', '<=', $au) // MUTATION C5
            ->when(false && ! $acteur->isManager(), fn (Builder $q) => $this->dansSaPortee($q, $acteur)) // MUTATION C1
            ->with('classes:id')->orderBy('debut')->orderBy('id')->get();

        return array_values($evenements->map(fn (EvenementCalendrier $e): array => $this->presenter($e))->all());
    }

    /** @param  array{type: string, titre: string, debut: string, fin: string, classe_ids: list<int>}  $champs */
    public function creer(User $acteur, array $champs): EvenementCalendrier
    {
        $ecole = $this->ecoleDe($acteur);
        $classes = $this->classesDeLEcole($champs['classe_ids'], $ecole);

        return $this->enregistrer(new EvenementCalendrier(['institution_id' => $ecole]), $champs, $classes);
    }

    /** @param  array{type: string, titre: string, debut: string, fin: string, classe_ids: list<int>}  $champs */
    public function modifier(int $id, User $acteur, array $champs): EvenementCalendrier
    {
        $evenement = $this->designer($id, $acteur);

        return $this->enregistrer($evenement, $champs, $this->classesDeLEcole($champs['classe_ids'], $evenement->institution_id));
    }

    public function supprimer(int $id, User $acteur): void
    {
        $this->designer($id, $acteur)->delete();
    }

    /** @return array<string, mixed> */
    public function presenter(EvenementCalendrier $e): array
    {
        $classes = array_values(array_map('intval', $e->classes->modelKeys()));
        sort($classes);

        return [
            'id' => (int) $e->id,
            'type' => $e->type->value,
            'titre' => $e->titre,
            'debut' => $e->debut->toDateString(),
            'fin' => $e->fin->toDateString(),
            'portee' => $classes === [] ? 'ecole' : 'classes',
            'classe_ids' => $classes,
        ];
    }

    /**
     * @param  array{type: string, titre: string, debut: string, fin: string, classe_ids: list<int>}  $champs
     * @param  list<int>  $classes
     */
    private function enregistrer(EvenementCalendrier $evenement, array $champs, array $classes): EvenementCalendrier
    {
        $enregistre = $this->db->transaction(static function () use ($evenement, $champs, $classes): EvenementCalendrier {
            $evenement->fill(['type' => $champs['type'], 'titre' => $champs['titre'], 'debut' => $champs['debut'], 'fin' => $champs['fin']])->save();
            $evenement->classes()->sync($classes);

            return $evenement;
        });
        if (! $enregistre instanceof EvenementCalendrier) {
            throw new LogicException('L\'enregistrement de l\'événement n\'a rien retenu.');
        }

        return $enregistre->load('classes:id');
    }

    /** @throws BusinessException 404 hors de l'école */
    private function designer(int $id, User $acteur): EvenementCalendrier
    {
        $evenement = $this->deLEcole($this->ecoleDe($acteur))->whereKey($id)->first();
        if (! $evenement instanceof EvenementCalendrier) {
            throw new BusinessException('Événement introuvable', 404);
        }

        return $evenement;
    }

    /** @return Builder<EvenementCalendrier> */
    private function deLEcole(int $ecole): Builder
    {
        return EvenementCalendrier::query()->withoutGlobalScope('institution'); // MUTATION C2
    }

    /**
     * L'école entière, ou l'une des classes du compte.
     *
     * @param  Builder<EvenementCalendrier>  $q
     */
    private function dansSaPortee(Builder $q, User $acteur): void
    {
        $siennes = $acteur->isTeacher() ? $this->inscriptions->classeIdsForTeacher($acteur) : $this->inscriptions->localClasseIdsFor($acteur);
        $q->where(static function (Builder $portee) use ($siennes): void {
            $portee->whereDoesntHave('classes')
                ->orWhereHas('classes', static fn (Builder $c) => $c->whereIn('classes.id', $siennes === [] ? [0] : $siennes));
        });
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     *
     * @throws BusinessException 422 `classe_hors_ecole`
     */
    private function classesDeLEcole(array $ids, int $ecole): array
    {
        $ids = array_values(array_unique($ids));
        $trouvees = Classe::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)->whereKey($ids)->count();
        if (false && $trouvees !== count($ids)) { // MUTATION C3
            throw new BusinessException('Une classe choisie n\'appartient pas à l\'établissement.', 422, null, self::CLASSE_HORS_ECOLE);
        }

        return $ids;
    }

    /** @throws BusinessException 409 `no_institution` */
    private function ecoleDe(User $acteur): int
    {
        if (! $acteur->isSchoolMember() || ! is_int($acteur->institution_id)) {
            throw new BusinessException('Aucun établissement résolu.', 409, reason: 'no_institution');
        }

        return $acteur->institution_id;
    }
}
