<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Demandes d'inscription (#1659)
|--------------------------------------------------------------------------
|
| `plafond_journalier` : au-delà, la porte publique répond le MÊME 202 sans
| enregistrer, et le journal le dit. Une réponse différente confirmerait que
| l'école existe. Le chiffre borne une inondation, pas une rentrée : une
| école reçoit rarement plus de quelques dizaines de demandes par jour.
*/
return [
    'plafond_journalier' => (int) env('ADMISSIONS_PLAFOND_JOURNALIER', 100),
];
