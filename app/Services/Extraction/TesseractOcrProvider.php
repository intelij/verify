<?php

namespace App\Services\Extraction;

use App\Services\Extraction\Contracts\ExtractionProvider;
use App\Services\Extraction\Contracts\ProviderUnavailableException;
use Illuminate\Support\Facades\Process;

/**
 * Local OCR via the Tesseract binary. No third party receives documents.
 *
 * PDFs are handled two ways:
 *  - text-based PDF: text is pulled directly via smalot/pdfparser (no raster)
 *  - scanned PDF: pages are rasterized (ghostscript/imagick or pdftoppm)
 *    then OCR'd per page
 */
class TesseractOcrProvider implements ExtractionProvider
{
    public function __construct(
        private readonly DocumentClassifier $classifier,
        private readonly FieldExtractor $fieldExtractor,
    ) {}

    public function name(): string
    {
        return 'tesseract';
    }

    public function isAvailable(): bool
    {
        $cfg = config('verification.providers.tesseract');
        return ($cfg['enabled'] ?? false) && is_executable($cfg['binary'] ?? '');
    }

    public function supports(string $mimeType, string $absolutePath): bool
    {
        return in_array($mimeType, [
            'image/jpeg', 'image/png', 'image/webp', 'image/tiff',
            'application/pdf',
        ], true);
    }

    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        if (! $this->isAvailable()) {
            throw new ProviderUnavailableException($this->name(), 'binary_missing', 'Tesseract binary not available');
        }

        $start = hrtime(true);
        $pageCount = 1;
        $signals = [];

        if ($mimeType === 'application/pdf') {
            $raw = $this->extractPdfText($absolutePath, $pageCount, $confidence, $signals);
        } else {
            [$raw, $confidence] = $this->ocrImage($absolutePath);
        }

        if (trim($raw) === '') {
            return ExtractionResult::failure($this->name(), 'no_text_extracted');
        }

        $classification = $this->classifier->classify($raw, $mimeType);
        $extracted = $this->fieldExtractor->extract($raw, $classification);

        return new ExtractionResult(
            provider: $this->name(),
            providerVersion: $this->version(),
            documentType: $classification->documentType,
            country: $classification->country,
            fields: $extracted['fields'],
            fieldConfidences: $extracted['field_confidences'],
            extractionConfidence: $confidence,
            signals: array_merge($classification->signals, $extracted['signals'], $signals),
            rawText: $raw,
            durationMs: (int) ((hrtime(true) - $start) / 1e6),
            pageCount: $pageCount,
        );
    }

    private function version(): ?string
    {
        $out = Process::timeout(10)->run([$this->binary(), '--version']);
        return preg_match('/tesseract\s+([\d.]+)/i', $out->output(), $m) ? $m[1] : null;
    }

    private function binary(): string
    {
        return config('verification.providers.tesseract.binary');
    }

    /**
     * OCR a single image. Returns [rawText, meanWordConfidence].
     * Uses TSV output to get per-word confidence scores.
     * @return array{0:string,1:float}
     */
    private function ocrImage(string $path): array
    {
        $timeout = (int) config('verification.providers.tesseract.timeout', 60);
        $lang = config('verification.providers.tesseract.language', 'eng');

        $result = Process::timeout($timeout)->run([
            $this->binary(), $path, 'stdout',
            '-l', $lang, '--psm', '3', 'tsv',
        ]);

        if (! $result->successful()) {
            return ['', 0.0];
        }

        return $this->parseTsv($result->output());
    }

    /**
     * @return array{0:string,1:float}
     */
    private function parseTsv(string $tsv): array
    {
        $words = [];
        $confidences = [];
        foreach (explode("\n", $tsv) as $i => $line) {
            if ($i === 0 || trim($line) === '') {
                continue;
            }
            $cols = explode("\t", $line);
            // TSV: level page block par line word left top w h conf text
            if (count($cols) < 12 || (int) $cols[0] !== 5) {
                continue;
            }
            $conf = (float) $cols[10];
            $text = trim($cols[11]);
            if ($text !== '' && $conf >= 0) {
                $words[] = $text;
                $confidences[] = $conf;
            }
        }

        $mean = count($confidences) > 0
            ? round(array_sum($confidences) / count($confidences) / 100, 3)
            : 0.0;

        return [$this->reconstructLines($tsv), $mean];
    }

    /**
     * Rebuild line-oriented text from TSV so regex field extraction sees
     * sensible layout (words on the same line joined by spaces).
     */
    private function reconstructLines(string $tsv): string
    {
        $lines = [];
        foreach (explode("\n", $tsv) as $i => $line) {
            if ($i === 0 || trim($line) === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 12 || (int) $cols[0] !== 5) {
                continue;
            }
            $key = $cols[1] . '-' . $cols[2] . '-' . $cols[3] . '-' . $cols[4];
            $text = trim($cols[11]);
            if ($text !== '') {
                $lines[$key][] = $text;
            }
        }

        return collect($lines)
            ->map(fn ($words) => implode(' ', $words))
            ->implode("\n");
    }

    /**
     * @return string raw text (confidence + pageCount + signals by-ref)
     */
    private function extractPdfText(string $path, ?int &$pageCount, ?float &$confidence, array &$signals): string
    {
        $pageCount = null;
        $confidence = 0.85; // text-layer extraction is near-deterministic
        $signals[] = 'pdf';

        // 1. Embedded text layer — preferred, deterministic.
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $pageCount = max(1, count($pdf->getPages()));
            $text = trim($pdf->getText() ?? '');
            if (mb_strlen($text) >= 20) {
                $signals[] = 'pdf_text_layer';
                return $text;
            }
        } catch (\Throwable) {
            // fall through to rasterized OCR
        }

        // 2. Scanned PDF — rasterize pages then OCR.
        $images = $this->rasterizePdf($path, $pageCount);
        if ($images === []) {
            $signals[] = 'pdf_rasterize_unavailable';
            return '';
        }

        $signals[] = 'pdf_scanned';
        $texts = [];
        $confs = [];
        foreach ($images as $img) {
            [$t, $c] = $this->ocrImage($img);
            if (trim($t) !== '') {
                $texts[] = $t;
                $confs[] = $c;
            }
            @unlink($img);
        }
        $confidence = count($confs) ? round(array_sum($confs) / count($confs), 3) : 0.0;

        return implode("\n", $texts);
    }

    /**
     * @return string[] temp image paths
     */
    private function rasterizePdf(string $path, ?int &$pageCount): array
    {
        $pageCount = $pageCount ?? 1;
        $paths = [];

        // pdftoppm (poppler) if installed
        $pdftoppm = trim((string) @shell_exec('command -v pdftoppm 2>/dev/null'));
        if ($pdftoppm !== '') {
            $prefix = tempnam(sys_get_temp_dir(), 'pdfpg');
            Process::timeout(60)->run([$pdftoppm, '-r', '200', '-png', '-f', '1', '-l', '5', $path, $prefix]);
            foreach (glob($prefix . '-*.png') ?: [] as $p) {
                $paths[] = $p;
            }
            if ($paths !== []) {
                return $paths;
            }
        }

        // Imagick + ghostscript fallback
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                $im->setResolution(200, 200);
                $im->readImage($path . '[0-4]');
                foreach ($im as $page) {
                    $tmp = tempnam(sys_get_temp_dir(), 'pdfpg') . '.png';
                    $page->setImageFormat('png');
                    $page->writeImage($tmp);
                    $paths[] = $tmp;
                }
                $im->clear();
            } catch (\Throwable) {
                return [];
            }
        }

        return $paths;
    }
}
