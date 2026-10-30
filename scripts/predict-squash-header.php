<?php

declare(strict_types=1);

/**
 * Prédit l'en-tête que GitHub donnera au commit d'une fusion par squash (#946).
 *
 * Sur une PR, commitlint ne lit que les commits de la PR. L'en-tête du commit de
 * squash, lui, n'est fabriqué qu'à la fusion : le titre du commit ou de la PR,
 * plus « (#N) ». Sans prédiction, une règle de `.commitlintrc.json` (la longueur
 * maximale de 70, par exemple) ne tombait qu'après coup, sur `lms`.
 *
 * Ce script ne porte AUCUNE règle : il imprime l'en-tête prédit, que la CI passe
 * à commitlint avec la configuration du dépôt.
 *
 * Réglage `squash_merge_commit_title` du dépôt (API GitHub) :
 *   - `COMMIT_OR_PR_TITLE` : l'en-tête du commit si la PR n'en compte qu'un, sinon
 *     le titre de la PR ;
 *   - `PR_TITLE` : le titre de la PR.
 * Le suffixe « (#N) » s'ajoute dans les deux cas. Sa longueur dépend de N : à
 * partir de #1000, il fait 8 caractères, et non 7.
 *
 * ## Codes de sortie
 *
 *   0  en-tête prédit, imprimé sur la sortie standard
 *   2  la prédiction est impossible : appel invalide, ou réglage inconnu. Le
 *      script ne devine jamais un réglage qu'il ne connaît pas.
 *
 * Usage :
 *   php scripts/predict-squash-header.php <réglage> <numéro> <nb de commits> <titre de la PR> <en-tête du commit>
 *
 * Le dernier argument n'est lu que pour une PR d'un seul commit ; il peut être vide sinon.
 *
 * @see .github/workflows/squash-header.yml
 */
function refuser(string $raison): never
{
    fwrite(STDERR, "predict-squash-header : {$raison}\n");
    exit(2);
}

if ($argc !== 6) {
    refuser('5 arguments attendus : réglage, numéro, nombre de commits, titre de la PR, en-tête du commit.');
}

[, $reglage, $numero, $commits, $titre, $enTeteDuCommit] = $argv;

if (preg_match('/^[1-9]\d*$/', $numero) !== 1) {
    refuser("numéro de PR invalide : « {$numero} ».");
}
if (preg_match('/^[1-9]\d*$/', $commits) !== 1) {
    refuser("nombre de commits invalide : « {$commits} ».");
}
if (trim($titre) === '') {
    refuser('titre de la PR vide.');
}

$base = match ($reglage) {
    'PR_TITLE' => $titre,
    'COMMIT_OR_PR_TITLE' => $commits === '1' ? $enTeteDuCommit : $titre,
    default => refuser("réglage squash_merge_commit_title inconnu : « {$reglage} »."),
};

if (trim($base) === '') {
    refuser("en-tête du commit vide, alors que la PR n'en compte qu'un.");
}

echo "{$base} (#{$numero})";
