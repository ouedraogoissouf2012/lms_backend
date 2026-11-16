"""#1134 — décrire le 429 des lectures authentifiées sur CHAQUE opération GET.

Depuis #947, le limiteur `lecture` (posé sur le groupe `api`) borne toute
lecture authentifiée : chacune peut rendre 429. Un client généré ne lit que les
réponses de chaque opération. Ce script les y écrit :

- une GET authentifiée sans 429 reçoit un `$ref` vers `TooManyRequests` ;
- une GET qui a déjà un 429 d'un autre sens (le 429 relayé de KLASSCI) voit sa
  description complétée : une opération n'a qu'une entrée par code ;
- celles qui le disent déjà restent telles quelles.

Le YAML n'est pas re-sérialisé : ses commentaires et sa mise en forme sont
relus à la main. Les blocs sont repérés par leur indentation, régulière dans ce
fichier (chemin 2, méthode 4, `responses:` 6, code 8). Même procédé que
`scripts/openapi-refus-de-la-resolution.py` (#961).

Idempotent : relancé, il ne change rien. La garde est
`tests/Feature/Docs/TropDeLecturesDecritTest.php`.

Usage : python scripts/openapi-429-des-lectures.py docs/openapi.yaml
"""
import re
import sys

REF = "#/components/responses/TooManyRequests"
AJOUT = "Aussi : limite de débit des lectures, par famille et par compte (#947)."
DIT = 'limite de débit des lectures'


def indentation(ligne):
    return len(ligne) - len(ligne.lstrip(' '))


def fin_de_bloc(lignes, debut, indent):
    i = debut + 1
    while i < len(lignes):
        if lignes[i].strip() != '' and indentation(lignes[i]) <= indent:
            return i
        i += 1
    return i


def lectures(lignes):
    """[(chemin, i_get, i_fin_get)] des opérations GET, dans l'ordre du fichier."""
    i_paths = lignes.index('paths:')
    fin_paths = fin_de_bloc(lignes, i_paths, 0)
    ops = []
    i = i_paths + 1
    while i < fin_paths:
        m = re.match(r"^  (['\"]?)(/[^'\"]*)\1:\s*$", lignes[i])
        if not m:
            i += 1
            continue
        fin_chemin = fin_de_bloc(lignes, i, 2)
        for j in range(i + 1, fin_chemin):
            if lignes[j] == '    get:':
                ops.append((m.group(2), j, fin_de_bloc(lignes, j, 4)))
        i = fin_chemin
    return ops


def completer(lignes, debut, fin):
    """Écrit le 429 dans l'opération GET [debut, fin). Rend 'ref', 'description' ou None."""
    if any(lignes[i] == '      security: []' for i in range(debut + 1, fin)):
        return None  # publique : le limiteur ne la compte pas
    k = next((i for i in range(debut + 1, fin) if lignes[i] == '      responses:'), None)
    if k is None:
        sys.exit(f'opération sans responses à la ligne {debut + 1}')
    fin_rep = fin_de_bloc(lignes, k, 6)
    codes = {}
    for i in range(k + 1, fin_rep):
        m = re.match(r"^        (['\"]?)(\d{3}|default)\1:\s*$", lignes[i])
        if m:
            codes[m.group(2)] = (i, fin_de_bloc(lignes, i, 8))

    if '429' not in codes:
        suivants = [i for c, (i, _) in codes.items() if c.isdigit() and int(c) > 429]
        ou = min(suivants) if suivants else fin_rep
        # En fin de bloc : avant la ligne vide qui sépare deux opérations.
        while ou > k + 1 and lignes[ou - 1].strip() == '':
            ou -= 1
        lignes[ou:ou] = ["        '429':", f"          $ref: '{REF}'"]
        return 'ref'

    i, fin_code = codes['429']
    corps = lignes[i + 1:fin_code]
    if any(l.strip() == f"$ref: '{REF}'" for l in corps):
        return None
    d = next((x for x in range(i + 1, fin_code) if lignes[x].startswith('          description:')), None)
    if d is None:
        sys.exit(f'429 sans description à la ligne {i + 1}')
    valeur = lignes[d][len('          description:'):].strip()
    if valeur == '|':
        fin_desc = d + 1
        while fin_desc < fin_code and (lignes[fin_desc].strip() == '' or indentation(lignes[fin_desc]) >= 12):
            fin_desc += 1
        while lignes[fin_desc - 1].strip() == '':
            fin_desc -= 1
        # Espaces normalisés : un bloc coupe ses phrases par un saut de ligne indenté.
        if DIT in re.sub(r'\s+', ' ', ' '.join(lignes[d + 1:fin_desc])):
            return None
        lignes[fin_desc:fin_desc] = ['            ' + AJOUT]
        return 'description'
    if valeur.startswith('"') and valeur.endswith('"') and len(valeur) > 1:
        if DIT in valeur:
            return None
        phrase = valeur[1:-1].rstrip()
        separateur = ' ' if phrase.endswith('.') else '. '
        lignes[d] = '          description: "' + phrase + separateur + AJOUT + '"'
        return 'description'
    sys.exit(f'forme de description non prise en charge à la ligne {d + 1} : {valeur[:40]!r}')


def main(chemin):
    lignes = open(chemin, encoding='utf-8').read().split('\n')
    bilan = {}
    # De la fin vers le début : une insertion ne décale pas les opérations restantes.
    for _, debut, fin in reversed(lectures(lignes)):
        geste = completer(lignes, debut, fin)
        if geste:
            bilan[geste] = bilan.get(geste, 0) + 1
    open(chemin, 'w', encoding='utf-8', newline='\n').write('\n'.join(lignes))
    print(f'{len(lectures(lignes))} lectures ; ' + ', '.join(f'{k} : {v}' for k, v in sorted(bilan.items())))


if __name__ == '__main__':
    main(sys.argv[1])
