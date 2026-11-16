"""#961 — décrire les deux refus de `ResolveInstitution` sur CHAQUE opération.

Le middleware est global au groupe `api` : il peut refuser n'importe quelle
requête AVANT sa route. OpenAPI 3 n'a pas de réponse globale, et un client
généré ne lit que les réponses de chaque opération. Ce script les y écrit :

- 403 (requête avec jeton, établissement désactivé ou supprimé), sur chaque
  opération authentifiée ;
- 400 (requête sans jeton, `X-Institution` inconnu ou inactif), sur chaque
  opération : le refus précède le 401 de l'authentification.

Une opération sans ce code reçoit un `$ref` vers la réponse partagée. Une
opération qui a déjà ce code, pour un autre sens, voit sa description
complétée : une opération n'a qu'une entrée par code. Celles qui le disent
déjà restent telles quelles.

Le fichier porte des commentaires et une mise en forme relues à la main : le
re-sérialiser les détruirait. Les blocs sont repérés par leur indentation,
régulière dans ce fichier (chemin 2, méthode 4, `responses:` 6, code 8).

Idempotent : relancé, il ne change rien. La garde est
`tests/Feature/Docs/RefusDeLaResolutionDuTenantTest.php`.

Usage : python scripts/openapi-refus-de-la-resolution.py docs/openapi.yaml
"""
import re
import sys

METHODES = ('get', 'put', 'post', 'delete', 'patch')

REF_403 = "#/components/responses/EtablissementDesactive"
REF_400 = "#/components/responses/EtablissementInconnu"
AJOUT_403 = "Aussi, sans `reason` : etablissement desactive ou supprime (ResolveInstitution, #961)."
AJOUT_400 = ("Aussi, sans jeton : en-tete `X-Institution` d un etablissement inconnu ou inactif "
             "(ResolveInstitution, #961), avant le 401.")
DIT_403 = re.compile(r'[ée]tablissement.{0,3}d[ée]sactiv', re.IGNORECASE)
DIT_400 = re.compile(r'X-Institution')

COMPOSANTS_REPONSES = """\
    # #961 — les deux refus du middleware global `ResolveInstitution`, rendus
    # AVANT toute route : chaque operation les cite (scripts/
    # openapi-refus-de-la-resolution.py), un client genere ne lit que les siennes.
    EtablissementDesactive:
      description: |
        Requete avec jeton : l etablissement du compte est desactive ou
        supprime (#565). Sans `reason`.
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/RefusDeLaResolution'
          example:
            success: false
            message: "Établissement désactivé. Accès suspendu, contactez votre administration."

    EtablissementInconnu:
      description: |
        Requete sans jeton, en-tete `X-Institution` d un etablissement inconnu
        ou inactif. Rendu AVANT le 401 de l authentification. Sans `reason`.
        Le message recopie la valeur recue (#1129).
      content:
        application/json:
          schema:
            $ref: '#/components/schemas/RefusDeLaResolution'
          example:
            success: false
            message: "Institution non trouvée ou inactive: slug-inexistant"

"""

COMPOSANT_SCHEMA = """\
    # #961 — le corps des deux refus de `ResolveInstitution` : ni `error_code`,
    # ni `reason`.
    RefusDeLaResolution:
      type: object
      additionalProperties: false
      required:
        - success
        - message
      properties:
        success:
          type: boolean
          enum:
            - false
        message:
          type: string

"""

ANCRE_REPONSES = "    # #806 — un 401 d'une route adossee a KLASSCI a TROIS sens, que seul\n"
ANCRE_SCHEMA = "    # #906 — un refus qui dit POURQUOI, pour que le front traduise par son\n"


def indentation(ligne):
    return len(ligne) - len(ligne.lstrip(' '))


def fin_de_bloc(lignes, debut, indent):
    i = debut + 1
    while i < len(lignes):
        if lignes[i].strip() != '' and indentation(lignes[i]) <= indent:
            return i
        i += 1
    return i


def operations(lignes):
    """[(chemin, methode, i_methode, i_fin_methode)], dans l'ordre du fichier."""
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
        j = i + 1
        while j < fin_chemin:
            mm = re.match(r'^    (' + '|'.join(METHODES) + r'):\s*$', lignes[j])
            if mm:
                fin_op = fin_de_bloc(lignes, j, 4)
                ops.append((m.group(2), mm.group(1), j, fin_op))
                j = fin_op
            else:
                j += 1
        i = fin_chemin
    return ops


def est_publique(lignes, debut, fin):
    return any(lignes[i] == '      security: []' for i in range(debut + 1, fin))


def bloc_reponses(lignes, debut, fin):
    k = next((i for i in range(debut + 1, fin) if lignes[i] == '      responses:'), None)
    if k is None:
        sys.exit(f'opération sans responses à la ligne {debut + 1}')
    return k, fin_de_bloc(lignes, k, 6)


def codes(lignes, debut, fin):
    rendu = {}
    for i in range(debut + 1, fin):
        m = re.match(r"^        (['\"]?)(\d{3}|default)\1:\s*$", lignes[i])
        if m:
            rendu[m.group(2)] = (i, fin_de_bloc(lignes, i, 8))
    return rendu


def completer(lignes, debut, fin, code, ref, ajout, dit):
    """Écrit le refus dans un bloc responses. Rend 'ref', 'description' ou None."""
    presents = codes(lignes, debut, fin)
    if code not in presents:
        suivants = [i for c, (i, _) in presents.items() if c.isdigit() and int(c) > int(code)]
        ou = min(suivants) if suivants else fin
        lignes[ou:ou] = [f"        '{code}':", f"          $ref: '{ref}'"]
        return 'ref'

    i, fin_code = presents[code]
    corps = lignes[i + 1:fin_code]
    if any(l.strip() == f"$ref: '{ref}'" for l in corps):
        return None
    k = next((x for x in range(i + 1, fin_code) if lignes[x].startswith('          description:')), None)
    if k is None:
        sys.exit(f'{code} sans description à la ligne {i + 1}')
    valeur = lignes[k][len('          description:'):].strip()
    if valeur == '|':
        fin_desc = k + 1
        while fin_desc < fin_code and (lignes[fin_desc].strip() == '' or indentation(lignes[fin_desc]) >= 12):
            fin_desc += 1
        while lignes[fin_desc - 1].strip() == '':
            fin_desc -= 1
        # Espaces normalisés : un bloc coupe ses phrases par un saut de ligne indenté.
        if dit.search(re.sub(r'\s+', ' ', ' '.join(lignes[k + 1:fin_desc]))):
            return None
        lignes[fin_desc:fin_desc] = ['            ' + ajout]
        return 'description'
    if valeur.startswith('"') and valeur.endswith('"') and len(valeur) > 1:
        if dit.search(valeur):
            return None
        phrase = valeur[1:-1].rstrip()
        separateur = ' ' if phrase.endswith('.') else '. '
        lignes[k] = '          description: "' + phrase + separateur + ajout + '"'
        return 'description'
    sys.exit(f'forme de description non prise en charge à la ligne {k + 1} : {valeur[:40]!r}')


def composants(texte):
    if 'EtablissementDesactive:' in texte:
        return texte
    for ancre in (ANCRE_REPONSES, ANCRE_SCHEMA):
        if texte.count(ancre) != 1:
            sys.exit(f'ancre introuvable ou multiple : {ancre.strip()[:50]!r}')
    texte = texte.replace(ANCRE_REPONSES, COMPOSANTS_REPONSES + ANCRE_REPONSES)
    return texte.replace(ANCRE_SCHEMA, COMPOSANT_SCHEMA + ANCRE_SCHEMA)


def main(chemin):
    texte = composants(open(chemin, encoding='utf-8').read())
    lignes = texte.split('\n')
    bilan = {}
    # De la fin vers le début : une insertion ne décale pas les opérations restantes.
    for nom, methode, debut, fin in reversed(operations(lignes)):
        publique = est_publique(lignes, debut, fin)
        k, fin_rep = bloc_reponses(lignes, debut, fin)
        gestes = []
        if not publique:
            geste = completer(lignes, k, fin_rep, '403', REF_403, AJOUT_403, DIT_403)
            gestes.append(f'403 {geste}' if geste else None)
            k, fin_rep = bloc_reponses(lignes, debut, fin_de_bloc(lignes, debut, 4))
        geste = completer(lignes, k, fin_rep, '400', REF_400, AJOUT_400, DIT_400)
        gestes.append(f'400 {geste}' if geste else None)
        for g in filter(None, gestes):
            bilan[g] = bilan.get(g, 0) + 1
    open(chemin, 'w', encoding='utf-8', newline='\n').write('\n'.join(lignes))
    print(f'{len(operations(lignes))} opérations ; ' + ', '.join(f'{k} : {v}' for k, v in sorted(bilan.items())))


if __name__ == '__main__':
    main(sys.argv[1])
