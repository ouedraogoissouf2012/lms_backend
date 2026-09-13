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

        $utf8 = $raw;
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $utf8 = iconv('Windows-1252', 'UTF-8//IGNORE', $raw) ?: $raw;
        }

        try {
            $reader = Reader::createFromString($utf8);
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
     * @param  Reader<array<string, mixed>>  $reader
     * @return array{rows: list<array<string, mixed>>, counts: array{ok: int, error: int, total: int}}
     */
    private function scan(Reader $reader): array
    {
        $headers = [];
        foreach ($reader->getHeader() as $header) {
            $headers[] = strtolower(trim((string) $header));
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
            $classified = $this->classify($record, $seen, $lineNo);
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
        $nom = trim($this->cell($record, 'nom'));
        $prenom = trim($this->cell($record, 'prenom'));
        $email = strtolower(trim($this->cell($record, 'email')));
        $phone = $this->normalizePhone($this->cell($record, 'telephone'));

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

    /**
     * @param  array<string, mixed>  $record
     */
    private function cell(array $record, string $key): string
    {
        $value = $record[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
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
