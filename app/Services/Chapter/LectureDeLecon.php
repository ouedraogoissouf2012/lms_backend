<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Services\Enrollment\InscriptionNonActive;

/**
 * L'issue de la lecture d'une leçon ou d'un chapitre ({@see ChapterReadGate}) : là où
 * un booléen confondait l'élève étranger et l'élève à l'inscription non active (#1095).
 */
enum LectureDeLecon
{
    case Admise;
    case Introuvable;
    /** Une inscription active l'aurait admis : le refus dit ce motif (D57). */
    case InscriptionNonActive;

    /**
     * Le refus, dans la forme `{status, message}` des portes de lecture, ou `null` si
     * la lecture est permise. `$introuvable` est le message du 404 de l'appelant.
     *
     * @return array{status: int, message: string, reason?: string}|null
     */
    public function refus(string $introuvable): ?array
    {
        return match ($this) {
            self::Admise => null,
            self::Introuvable => ['status' => 404, 'message' => $introuvable],
            self::InscriptionNonActive => InscriptionNonActive::refus(),
        };
    }

    /**
     * Un refus `{status, message, reason?}` dans la forme `{status, payload}` des services
     * de chapitres. Le motif, s'il y en a un, suit le message. La SEULE conversion :
     * `ChapterCrudService` et `ChapterProgressAccess` l'appellent.
     *
     * @param  array{status: int, message: string, reason?: string}  $refus
     * @return array{status: int, payload: array<string, mixed>}
     */
    public static function enReponse(array $refus): array
    {
        $payload = ['success' => false, 'message' => $refus['message']];
        if (isset($refus['reason'])) {
            $payload['reason'] = $refus['reason'];
        }

        return ['status' => $refus['status'], 'payload' => $payload];
    }
}
