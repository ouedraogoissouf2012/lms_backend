<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportRowStatus;
use App\Enums\Role;
use App\Services\Import\Fields\DateInscriptionField;
use App\Services\Import\Fields\FieldOutcome;
use App\Services\Import\Fields\RoleField;
use App\Services\Import\Fields\StatutField;
use Illuminate\Http\UploadedFile;
use League\Csv\Info;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

/**
 * Analyse à blanc (#718). N'écrit aucune ligne métier.
 */
final class ImportPreviewService
{
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly RoleField $roles,
        private readonly StatutField $statuts,
        private readonly DateInscriptionField $dates,
    ) {}

    /**
     * @param  ColumnMap  $map  déclaration « quelle colonne porte quel champ »
     * @param  Role  $ceiling  rôle de celui qui importe — plafond des comptes
     *                         créés. Il vient de l'appelant et jamais du
     *                         fichier : c'est ce qui empêche la colonne `role`
     *                         d'être une élévation de privilège.
     * @param  string|null  $delimiter  séparateur avec lequel le client a montré
     *                                  les colonnes à l'utilisateur ; laisser
     *                                  null pour le détecter.
     * @return array{rows: list<array<string, mixed>>, counts: array{ok: int, error: int, total: int}}
     *
     * @throws ImportPreviewFailed si aucune ligne n'est lisible
     */
    public function preview(UploadedFile $file, ColumnMap $map, Role $ceiling, ?string $delimiter = null): array
    {
        $raw = $file->get();
        if (! is_string($raw) || $raw === '') {
            return $this->emptyReport();
        }

        $utf8 = $raw;
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $utf8 = iconv('Windows-1252', 'UTF-8//IGNORE', $raw) ?: $raw;
        }

        try {
            $reader = Reader::createFromString($utf8);
        } catch (UnavailableStream) {
            return $this->emptyReport();
        }

        $reader->setDelimiter($delimiter ?? $this->detectDelimiter($reader));
        $reader->setHeaderOffset(0);

        return $this->scan($reader, $map, $ceiling);
    }

    /**
     * @param  Reader<array<string, mixed>>  $reader
     */
    private function detectDelimiter(Reader $reader): string
    {
        $stats = Info::getDelimiterStats($reader, [';', ',', "\t"], 2);
        arsort($stats);
        $best = array_key_first($stats);

        return is_string($best) && $best !== '' ? $best : ';';
    }

    /**
     * @param  Reader<array<string, mixed>>  $reader
     * @return array{rows: list<array<string, mixed>>, counts: array{ok: int, error: int, total: int}}
     */
    private function scan(Reader $reader, ColumnMap $map, Role $ceiling): array
    {
        $headers = [];
        foreach ($reader->getHeader() as $header) {
            $headers[] = ColumnMap::normalizeHeader((string) $header);
        }

        // `Reader::computeHeader()` lèverait une SyntaxError sur des en-têtes
        // dupliqués. Le cas était inatteignable tant que le client réécrivait
        // le fichier ; il ne l'est plus, et une 500 n'apprend rien à qui doit
        // corriger son tableur.
        if (count($headers) !== count(array_unique($headers))) {
            throw ImportPreviewFailed::duplicateHeader();
        }

        $rows = [];
        $ok = 0;
        $error = 0;
        $seen = [];
        $n = 0;

        foreach ($reader->getRecords($headers) as $line => $record) {
            $n++;
            $lineNo = is_numeric($line) ? (int) $line + 1 : $n;
            if ($n > self::MAX_ROWS) {
                $rows[] = $this->row($lineNo, ImportRowStatus::Error, 'too_many_rows', 'Plus de 5000 lignes.');
                $error++;
                break;
            }
            $classified = $this->classify($record, $map, $ceiling, $seen, $lineNo);
            $rows[] = $classified;
            if ($classified['status'] === ImportRowStatus::Ok->value) {
                $ok++;
            } else {
                $error++;
            }
        }

        return [
            'rows' => $rows,
            'counts' => ['ok' => $ok, 'error' => $error, 'total' => $ok + $error],
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, true>  $seen
     * @return array{line: int, status: string, code: string|null, message: string|null, payload: array<string, string>|null}
     */
    private function classify(array $record, ColumnMap $map, Role $ceiling, array &$seen, int $line): array
    {
        $nom = trim($map->value($record, 'nom'));
        $prenom = trim($map->value($record, 'prenom'));
        $email = mb_strtolower(trim($map->value($record, 'email')));
        $phone = $this->normalizePhone($map->value($record, 'telephone'));

        if ($nom === '' || $prenom === '') {
            return $this->row($line, ImportRowStatus::Error, 'missing_name', 'Nom et prénom requis.');
        }
        if ($email === '' && $phone === '') {
            return $this->row($line, ImportRowStatus::Error, 'missing_id', 'Email ou téléphone requis.');
        }

        $key = $email !== '' ? 'e:'.$email : 'p:'.$phone;
        if (isset($seen[$key])) {
            return $this->row($line, ImportRowStatus::Error, 'duplicate', 'Doublon dans le fichier.');
        }

        // Les trois colonnes que l'écran proposait sans que rien ne les lise.
        // Chacune peut faire échouer SA ligne en disant pourquoi : c'est le
        // service que rend une analyse à blanc, et l'inverse exact du silence
        // d'avant, où une valeur incomprise était comptée « acceptée ».
        $declared = [
            'role' => $this->roles->resolve($map->value($record, 'role'), $ceiling),
            'date_inscription' => $this->dates->resolve($map->value($record, 'date_inscription')),
            'statut' => $this->statuts->resolve($map->value($record, 'statut')),
        ];

        foreach ($declared as $outcome) {
            if ($outcome->isRejected()) {
                // Le doublon n'est marqué qu'APRÈS : une ligne refusée ici n'a
                // rien consommé, et la même identité corrigée plus bas dans le
                // fichier doit encore pouvoir passer.
                return $this->row($line, ImportRowStatus::Error, $outcome->code, $outcome->message);
            }
        }

        $seen[$key] = true;

        return $this->row($line, ImportRowStatus::Ok, null, null, [
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => $phone,
            'code_classe' => trim($map->value($record, 'code_classe')),
            ...array_map(static fn (FieldOutcome $outcome): string => $outcome->value, $declared),
        ]);
    }

    private function normalizePhone(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * @param  array<string, string>|null  $payload
     * @return array{line: int, status: string, code: string|null, message: string|null, payload: array<string, string>|null}
     */
    private function row(int $line, ImportRowStatus $status, ?string $code, ?string $message, ?array $payload = null): array
    {
        return [
            'line' => $line,
            'status' => $status->value,
            'code' => $code,
            'message' => $message,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array{ok: int, error: int, total: int}}
     */
    private function emptyReport(): array
    {
        return ['rows' => [], 'counts' => ['ok' => 0, 'error' => 0, 'total' => 0]];
    }
}
