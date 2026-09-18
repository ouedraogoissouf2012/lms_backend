<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * Qui écrit le catalogue pédagogique d'un établissement (#848).
 *
 * Nommée par la préoccupation, jamais par le mode : l'appelant demande un
 * DROIT et ignore qu'un mode existe. C'est l'article 1 de l'épique #697, et la
 * forme déjà tenue par `RosterAuthority`, dans `app/Services/Roster/`.
 *
 * Citée en PROSE et non par `{@see}` : Pint applique `fully_qualified_strict_types`
 * et transformerait le renvoi en `use` réel, créant une dépendance de ce dossier
 * vers `Roster/` pour une simple note de documentation.
 *
 * ## Pourquoi une interface distincte de RosterAuthority
 *
 * Celle-ci répond « qui écrit la LISTE DES APPRENANTS ». Écrire le catalogue —
 * classes, matières, rattachement d'un formateur — est une autre question : un
 * établissement pourrait tenir sa liste d'apprenants sans tenir son catalogue.
 * Y greffer une seconde méthode aurait violé le principe de nommage que
 * `RosterAuthority` énonce dans son propre docblock.
 *
 * ## Pourquoi la même fabrique, malgré ce nom
 *
 * `.ocp-allowlist.json` exempte `RosterAuthorityFactory.php` PAR FICHIER et
 * prévient qu'une entrée de plus « revient à déplacer la frontière
 * d'architecture ». Une seconde fabrique créerait un second endroit où le mode
 * se résout — précisément ce que la première évitait. Le nom de la fabrique est
 * donc devenu trop étroit : dette assumée et tracée, pas un oubli.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
interface CatalogueAuthority
{
    /**
     * L'établissement courant peut-il créer classes et matières depuis le LMS ?
     */
    public function allowsLocalCatalogue(): bool;
}
