<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportRowStatus;
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

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array{ok: int, error: int, total: int}}
     */
    public function preview(UploadedFile $file): array
    {
        $raw = $file->get();
        if (! is_string($raw) || $raw === '') {
            return $this->emptyReport();
        }

        $utf8 = mb_check_encoding($raw, 'UTF-8')
            ? $raw
            : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

        try {
            $reader = Reader::fromString($utf8);
        } catch (UnavailableStream) {
            return $this->emptyReport();
        }

        $stats = Info::getDelimiterStats($reader, [';', ',', "\t"], 2);
        arsort($stats);
        $delimiter = array_key_first($stats);
        $reader->setDelimiter(is_string($delimiter) && $delimiter !== '' ? $delimiter : ';');
        $reader->setHeaderOffset(0);

        return $this->scan($reader);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array{ok: int, error: int, total: int}}
     */
    private function scan(Reader $reader): array
    {
        $headers = array_map(
            static fn (string $h): string => strtolower(trim($h)),
            $reader->getHeader(),
        );
        $rows = [];
        $ok = 0;
        $error = 0;
        $seen = [];
        $n = 0;

        foreach ($reader->getRecords($headers) as $line => $record) {
            $n++;
            if ($n > self::MAX_ROWS) {
                $rows[] = $this->row((int) $line + 1, ImportRowStatus::Error, 'too_many_rows', 'Plus de 5000 lignes.');
                $error++;
                break;
            }
            $classified = $this->classify($record, $seen, (int) $line + 1);
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
     * @return array{line: int, status: string, code: string|null, message: string|null}
     */
    private function classify(array $record, array &$seen, int $line): array
    {
        $nom = trim((string) ($record['nom'] ?? ''));
        $prenom = trim((string) ($record['prenom'] ?? ''));
        $email = strtolower(trim((string) ($record['email'] ?? '')));
        $phone = $this->normalizePhone((string) ($record['telephone'] ?? ''));

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
        $seen[$key] = true;

        return $this->row($line, ImportRowStatus::Ok, null, null);
    }

    private function normalizePhone(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * @return array{line: int, status: string, code: string|null, message: string|null}
     */
    private function row(int $line, ImportRowStatus $status, ?string $code, ?string $message): array
    {
        return [
            'line' => $line,
            'status' => $status->value,
            'code' => $code,
            'message' => $message,
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
