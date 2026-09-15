<?php

namespace App\Application\Exports;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExporter
{
    /** @param list<string> $headers @param iterable<array-key, list<bool|float|int|string|null>> $rows */
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $safeFilename = $this->safeFilename($filename);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }
            $writer = Writer::from($stream);
            $this->configure($writer);
            $writer->insertOne($headers);
            $writer->insertAll($rows);
        }, $safeFilename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param list<string> $headers @param iterable<array-key, list<bool|float|int|string|null>> $rows */
    public function content(array $headers, iterable $rows): string
    {
        $writer = Writer::createFromString('');
        $this->configure($writer);
        $writer->insertOne($headers);
        $writer->insertAll($rows);

        return $writer->toString();
    }

    public function safeFilename(string $filename): string
    {
        $safeFilename = trim((string) preg_replace('/[^a-z0-9._-]+/i', '-', basename($filename)), '.-') ?: 'report.csv';

        return str_ends_with(mb_strtolower($safeFilename), '.csv') ? $safeFilename : $safeFilename.'.csv';
    }

    private function configure(Writer $writer): void
    {
        $writer->setEscape('');
        $writer->setEndOfLine("\r\n");
        $writer->addFormatter((new EscapeFormula)->escapeRecord(...));
    }
}
