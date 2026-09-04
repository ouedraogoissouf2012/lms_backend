<?php

declare(strict_types=1);

/**
 * Logique PURE du cliquet Open/Closed — aucune E/S, donc testable sans processus.
 *
 * La séparation reprend le patron déjà en place côté frontend :
 * `scripts/lib/fileSizeRatchet.mjs` isole la logique du script qui lit le disque.
 * Elle a aussi une raison plus prosaïque : `PRODUCTION_STANDARDS.md` §1.1 fixe la
 * limite à 300 lignes par fichier, « Exceptions : Aucune ». Une garde qui
 * violerait la règle qu'elle prétend faire respecter n'aurait aucune autorité.
 *
 * @see scripts/check-ocp.php  l'enveloppe qui lit le disque et rend les codes de sortie
 */

/**
 * Motifs interdits hors point de liaison.
 *
 * Le motif « URL/jeton » est volontairement étroit : `ssl_verify`,
 * `memoize_enabled` et `circuit_breaker_enabled` sont des réglages
 * d'INFRASTRUCTURE, pas du mode. Les inclure produirait des faux positifs — et
 * un faux positif dans une garde neuve la fait supprimer avant qu'elle ne serve.
 */
const OCP_MOTIFS = [
    'comparaison de mode' => '/->\s*mode\s*(===|==|!==|!=)/',
    'match/switch sur le mode' => '/\b(match|switch)\s*\(\s*\$?\w*[mM]ode\b/',
    'prédicat de mode' => '/\b(isStandalone|isKlassci|hasKlassci|estAutonome)\s*\(/',
    'littéral de mode comparé' => '/(===|==|!==|!=)\s*[\'"](standalone|klassci)[\'"]/',
    'URL/jeton KLASSCI lu en direct' => '/config\s*\(\s*[\'"]services\.klassci\.(url|token)[\'"]/',
];

/**
 * Retire commentaires et docblocks en préservant la numérotation des lignes.
 *
 * Ce dépôt est très documenté : un grep naïf compte les docblocks. Mesuré sur
 * `app/Services`, 49 fichiers nomment un client KLASSCI en brut contre 40 en
 * code réel.
 */
function ocpCodeSeul(string $source): string
{
    $sortie = '';

    foreach (token_get_all($source) as $t) {
        if (is_array($t)) {
            $sortie .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? str_repeat("\n", substr_count($t[1], "\n"))
                : $t[1];

            continue;
        }

        $sortie .= $t;
    }

    return $sortie;
}

/**
 * Relève les violations d'un source déjà lu.
 *
 * @return list<array{ligne: int, motif: string, extrait: string}>
 */
function ocpViolations(string $source): array
{
    $trouves = [];

    foreach (explode("\n", ocpCodeSeul($source)) as $n => $ligne) {
        foreach (OCP_MOTIFS as $libelle => $regex) {
            if (preg_match($regex, $ligne) === 1) {
                $trouves[] = ['ligne' => $n + 1, 'motif' => $libelle, 'extrait' => trim($ligne)];
            }
        }
    }

    return $trouves;
}

/**
 * Valide la liste d'exemption et la range par type.
 *
 * C'est ici que la règle des trois demandes cesse d'être morale pour devenir
 * mécanique (contrat #697, article 7) : une dérogation sans trois dates
 * DISTINCTES, sans ADR présent sur le disque et sans accord nommé produit une
 * erreur — donc un code de sortie 2, « la garde n'a pas pu travailler ». On ne
 * s'exempte pas en ajoutant une ligne au fichier.
 *
 * @param  array<mixed>  $entrees
 * @param  callable(string): bool  $adrExiste  injecté pour rester sans E/S
 * @return array{liaison: array<string, true>, dettes: array<string, int>, erreurs: list<string>}
 */
function ocpClasseEntrees(array $entrees, callable $adrExiste): array
{
    $liaison = [];
    $dettes = [];
    $erreurs = [];

    foreach ($entrees as $i => $e) {
        $chemin = is_array($e) ? ($e['path'] ?? null) : null;
        $type = is_array($e) ? ($e['type'] ?? null) : null;

        if (! is_string($chemin) || $chemin === '') {
            $erreurs[] = "entrée #{$i} : « path » manquant";

            continue;
        }

        if (! in_array($type, ['point-de-liaison', 'dette', 'derogation'], true)) {
            $erreurs[] = "{$chemin} : « type » doit valoir « point-de-liaison », « dette » ou « derogation »";

            continue;
        }

        if (empty($e['reason'])) {
            $erreurs[] = "{$chemin} : « reason » manquante";
        }

        if ($type === 'dette') {
            if (! isset($e['count']) || ! is_int($e['count']) || $e['count'] < 1) {
                $erreurs[] = "{$chemin} : une dette doit porter « count » (entier ≥ 1)";

                continue;
            }

            $dettes[$chemin] = $e['count'];

            continue;
        }

        if ($type === 'derogation') {
            $demandes = $e['demandes'] ?? [];
            $distinctes = is_array($demandes)
                ? array_unique(array_filter($demandes, static fn ($d) => is_string($d) && $d !== ''))
                : [];

            if (count($distinctes) < 3) {
                $erreurs[] = "{$chemin} : dérogation refusée — " . count($distinctes)
                    . ' demande(s) distincte(s) sur les 3 requises (article 7)';
            }

            $adr = $e['adr'] ?? null;

            if (! is_string($adr) || ! $adrExiste($adr)) {
                $erreurs[] = "{$chemin} : dérogation refusée — ADR introuvable (" . ($adr ?? 'aucun') . ')';
            }

            if (empty($e['accorde_par'])) {
                $erreurs[] = "{$chemin} : dérogation refusée — « accorde_par » manquant";
            }
        }

        $liaison[$chemin] = true;
    }

    return ['liaison' => $liaison, 'dettes' => $dettes, 'erreurs' => $erreurs];
}

/**
 * Confronte les violations relevées au cliquet.
 *
 * Sémantique reprise de `diffAgainstSizeBaseline()` (frontend) : un fichier neuf
 * qui viole échoue ; une dette qui grossit échoue ; **rétrécir ou disparaître est
 * toujours accepté**. Le cliquet ne remonte jamais.
 *
 * @param  array<string, int>  $trouves  chemin => nombre d'occurrences
 * @param  array<string, int>  $dettes   chemin => nombre toléré
 * @return array{neuves: array<string, int>, aggravees: array<string, string>, reduites: array<string, string>}
 */
function ocpVerdict(array $trouves, array $dettes): array
{
    $neuves = [];
    $aggravees = [];
    $reduites = [];

    foreach ($trouves as $chemin => $n) {
        if (! isset($dettes[$chemin])) {
            $neuves[$chemin] = $n;

            continue;
        }

        if ($n > $dettes[$chemin]) {
            $aggravees[$chemin] = "{$dettes[$chemin]} → {$n}";
        }
    }

    foreach ($dettes as $chemin => $tolere) {
        $reel = $trouves[$chemin] ?? 0;

        if ($reel < $tolere) {
            $reduites[$chemin] = "{$tolere} → {$reel}";
        }
    }

    return ['neuves' => $neuves, 'aggravees' => $aggravees, 'reduites' => $reduites];
}
