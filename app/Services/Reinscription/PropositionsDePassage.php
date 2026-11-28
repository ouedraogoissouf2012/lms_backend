<?php

declare(strict_types=1);

namespace App\Services\Reinscription;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\DecisionDePassage;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use App\Services\Evaluation\Classe\BilanDeClasse;

/**
 * Les propositions de passage d'une classe locale (#1663) : une par élève, sur LE bilan de classe ({@see BilanDeClasse},
 * aucun second calculateur) aux dates de sa période, avec la règle effective ({@see ReglesDePassage}).
 *
 * - `passe` : moyenne générale au moins égale à la moyenne de passage ;
 * - `rattrapage` : au moins la moyenne de rattrapage, et au plus `max_matieres_rattrapage` matières sous le passage ;
 * - `redouble` : redoublement autorisé et moins de `max_redoublements` redoublements déjà décidés ;
 * - `exclu` : sinon ;
 * - `null` : rien à moyenner. L'écran le dit ; il ne devine pas.
 *
 * Le personnel corrige une proposition, avec un motif ({@see DecisionDePassage}, auditée).
 *
 * @phpstan-type Eleve array{id: int, nom: string, moyenne: float|null, moyennes: list<float>}
 */
final class PropositionsDePassage
{
    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly ReglesDePassage $regles,
        private readonly BilanDeClasse $bilan,
    ) {}

    /** @return array<string, mixed> */
    public function de(User $acteur, int $classeId): array
    {
        $classe = ClasseLocale::de($this->porte->etablissementDe($acteur), $classeId);
        [$periode, $lignes] = $this->lignes($classe, $acteur);
        $compteurs = array_fill_keys([...DecisionDePassage::DECISIONS, 'indetermine'], 0);
        foreach ($lignes as $ligne) {
            $compteurs[$ligne['decision'] ?? $ligne['proposition'] ?? 'indetermine']++;
        }

        return [
            'classe' => ['id' => $classe->id, 'libelle' => (string) $classe->libelle],
            'periode' => $periode,
            'regles' => $this->regles->effective($classe),
            'eleves' => $lignes,
            'compteurs' => $compteurs,
        ];
    }

    /**
     * @return array<string, mixed> la ligne de l'élève, décision comprise
     *
     * @throws BusinessException 404 si l'élève n'est pas un élève de la classe
     */
    public function corriger(User $acteur, int $classeId, int $eleveId, string $decision, string $motif): array
    {
        $classe = ClasseLocale::de($this->porte->etablissementDe($acteur), $classeId);
        [, $lignes] = $this->lignes($classe, $acteur);
        $ligne = array_values(array_filter($lignes, static fn (array $l): bool => $l['eleve_id'] === $eleveId))[0] ?? null;
        if ($ligne === null) {
            throw new BusinessException('Élève introuvable dans cette classe.', 404);
        }
        $ecrite = DecisionDePassage::query()->withoutGlobalScopes()->where('classe_id', $classe->id)->where('user_id', $eleveId)->first()
            ?? new DecisionDePassage(['classe_id' => $classe->id, 'user_id' => $eleveId]);
        $ecrite->fill([
            'institution_id' => $classe->institution_id, 'proposition' => $ligne['proposition'],
            'decision' => $decision, 'motif' => $motif, 'decide_par' => $acteur->id,
        ])->save();

        return self::corrigee($ligne, $ecrite);
    }

    /**
     * @return array{0: mixed, 1: list<array{eleve_id: int, nom: string, moyenne_generale: float|null, matieres_sous_le_seuil: int, redoublements_precedents: int, proposition: string|null, decision: string|null, motif: string|null, decide_le: string|null}>}
     */
    private function lignes(Classe $classe, User $acteur): array
    {
        $regle = $this->regles->effective($classe);
        $periode = $classe->trainingSession;
        $bilan = $this->bilan->de($classe, $acteur, $periode?->starts_on, $periode?->ends_on);
        $eleves = self::eleves($bilan['eleves'] ?? []);
        $ids = array_map(static fn (array $e): int => $e['id'], $eleves);
        $decisions = DecisionDePassage::query()->withoutGlobalScopes()->where('classe_id', $classe->id)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $redoublements = $this->redoublements($classe, $ids);

        $lignes = [];
        foreach ($eleves as $eleve) {
            $ligne = self::ligne($eleve, $regle, $redoublements[$eleve['id']] ?? 0);
            $decision = $decisions->get($eleve['id']);
            $lignes[] = $decision instanceof DecisionDePassage ? self::corrigee($ligne, $decision) : $ligne;
        }

        return [$bilan['periode'] ?? null, $lignes];
    }

    /**
     * Les élèves du bilan qui ont un compte local, sous une forme typée.
     *
     * @return list<Eleve>
     */
    private static function eleves(mixed $lignes): array
    {
        $eleves = [];
        foreach (is_array($lignes) ? $lignes : [] as $ligne) {
            if (! is_array($ligne) || ! is_int($ligne['compte_id'] ?? null)) {
                continue;
            }
            $moyennes = [];
            foreach (is_array($ligne['matieres'] ?? null) ? $ligne['matieres'] : [] as $matiere) {
                if (is_array($matiere) && is_numeric($matiere['moyenne'] ?? null)) {
                    $moyennes[] = (float) $matiere['moyenne'];
                }
            }
            $eleves[] = [
                'id' => $ligne['compte_id'], 'nom' => is_string($ligne['nom'] ?? null) ? $ligne['nom'] : '',
                'moyenne' => is_numeric($ligne['moyenne_generale'] ?? null) ? (float) $ligne['moyenne_generale'] : null,
                'moyennes' => $moyennes,
            ];
        }

        return $eleves;
    }

    /**
     * @param  Eleve  $eleve
     * @param  array<string, float|int|bool>  $regle
     * @return array{eleve_id: int, nom: string, moyenne_generale: float|null, matieres_sous_le_seuil: int, redoublements_precedents: int, proposition: string|null, decision: string|null, motif: string|null, decide_le: string|null}
     */
    private static function ligne(array $eleve, array $regle, int $redoublements): array
    {
        $passage = (float) $regle['moyenne_passage'];
        $sous = count(array_filter($eleve['moyennes'], static fn (float $m): bool => $m < $passage));

        return [
            'eleve_id' => $eleve['id'], 'nom' => $eleve['nom'], 'moyenne_generale' => $eleve['moyenne'],
            'matieres_sous_le_seuil' => $sous, 'redoublements_precedents' => $redoublements,
            'proposition' => $eleve['moyenne'] === null ? null : self::proposer($eleve['moyenne'], $sous, $redoublements, $regle),
            'decision' => null, 'motif' => null, 'decide_le' => null,
        ];
    }

    /** @param  array<string, float|int|bool>  $regle */
    private static function proposer(float $moyenne, int $sous, int $redoublements, array $regle): string
    {
        return match (true) {
            $moyenne >= (float) $regle['moyenne_passage'] => DecisionDePassage::PASSE,
            $moyenne >= (float) $regle['moyenne_rattrapage'] && $sous <= (int) $regle['max_matieres_rattrapage'] => DecisionDePassage::RATTRAPAGE,
            // SONDE #1663 : le maximum de redoublements n'est plus lu.
            $regle['redoublement_autorise'] === true => DecisionDePassage::REDOUBLE,
            default => DecisionDePassage::EXCLU,
        };
    }

    /**
     * @param  array{eleve_id: int, nom: string, moyenne_generale: float|null, matieres_sous_le_seuil: int, redoublements_precedents: int, proposition: string|null, decision: string|null, motif: string|null, decide_le: string|null}  $ligne
     * @return array{eleve_id: int, nom: string, moyenne_generale: float|null, matieres_sous_le_seuil: int, redoublements_precedents: int, proposition: string|null, decision: string|null, motif: string|null, decide_le: string|null}
     */
    private static function corrigee(array $ligne, DecisionDePassage $decision): array
    {
        return ['decision' => $decision->decision, 'motif' => $decision->motif, 'decide_le' => $decision->updated_at?->toIso8601String()] + $ligne;
    }

    /**
     * Les redoublements déjà décidés pour ces élèves, dans d'AUTRES classes de l'école.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function redoublements(Classe $classe, array $ids): array
    {
        $totaux = [];
        $lignes = DecisionDePassage::query()->withoutGlobalScopes()
            ->where('institution_id', $classe->institution_id)->where('classe_id', '!=', $classe->id)
            ->whereIn('user_id', $ids)->where('decision', DecisionDePassage::REDOUBLE)->get(['user_id']);
        foreach ($lignes as $ligne) {
            $totaux[$ligne->user_id] = ($totaux[$ligne->user_id] ?? 0) + 1;
        }

        return $totaux;
    }
}
