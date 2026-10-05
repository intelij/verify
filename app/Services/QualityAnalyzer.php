<?php

namespace App\Services;

/**
 * Pre-extraction document quality assessment. Produces a 0..1 score plus
 * specific problems so the API can return actionable reasons
 * (e.g. "image too blurry") instead of a bare failure.
 */
class QualityAnalyzer
{
    public function analyze(string $absolutePath, string $mimeType): array
    {
        if ($mimeType === 'application/pdf') {
            return $this->analyzePdf($absolutePath);
        }
        return $this->analyzeImage($absolutePath, $mimeType);
    }

    private function analyzePdf(string $path): array
    {
        $problems = [];
        $size = filesize($path) ?: 0;
        $score = 0.8;

        $head = file_get_contents($path, false, null, 0, 8);
        if (! str_starts_with((string) $head, '%PDF-')) {
            $problems[] = 'file is not a valid PDF document';
            $score = 0.0;
        }
        if ($size < 1024) {
            $problems[] = 'file is too small to contain a document';
            $score = min($score, 0.1);
        }

        return [
            'score' => $score,
            'problems' => $problems,
            'metrics' => ['bytes' => $size],
            'readable' => $problems === [],
        ];
    }

    private function analyzeImage(string $path, string $mimeType): array
    {
        $problems = [];
        $metrics = [];

        $info = @getimagesize($path);
        if ($info === false) {
            return [
                'score' => 0.0,
                'problems' => ['file is not a readable image'],
                'metrics' => [],
                'readable' => false,
            ];
        }

        [$w, $h] = $info;
        $metrics['width'] = $w;
        $metrics['height'] = $h;
        $metrics['megapixels'] = round($w * $h / 1e6, 2);

        $score = 1.0;

        // Resolution: a readable ID document needs a reasonable pixel count.
        if ($w * $h < 150_000) {
            $problems[] = 'image resolution is too low to read the document';
            $score -= 0.5;
        } elseif ($w * $h < 400_000) {
            $problems[] = 'image resolution is low; text may be hard to read';
            $score -= 0.2;
        }

        // Blur via Laplacian variance (Imagick) when available.
        $blur = $this->blurScore($path);
        if ($blur !== null) {
            $metrics['laplacian_variance'] = round($blur, 1);
            if ($blur < 30) {
                $problems[] = 'the document image is too blurry to verify';
                $score -= 0.45;
            } elseif ($blur < 80) {
                $problems[] = 'the document image is somewhat blurry';
                $score -= 0.15;
            }
        }

        // Contrast.
        $contrast = $this->contrastScore($path);
        if ($contrast !== null) {
            $metrics['contrast_stdev'] = round($contrast, 1);
            if ($contrast < 20) {
                $problems[] = 'the image has very low contrast';
                $score -= 0.25;
            }
        }

        $score = max(0.0, min(1.0, $score));

        return [
            'score' => round($score, 3),
            'problems' => $problems,
            'metrics' => $metrics,
            'readable' => $score >= (float) config('verification.policies_min_quality', 0.3),
        ];
    }

    private function blurScore(string $path): ?float
    {
        if (! extension_loaded('imagick')) {
            return null;
        }
        try {
            $im = new \Imagick($path);
            $im->setImageColorspace(\Imagick::COLORSPACE_GRAY);
            // Laplacian kernel.
            $im->convolveImage([0, 1, 0, 1, -4, 1, 0, 1, 0]);
            $stats = $im->getImageChannelStatistics();
            $im->destroy();
            // stdev of convolved image ~ blur variance proxy.
            $stdev = $stats[\Imagick::CHANNEL_GRAY]['standardDeviation'] ?? null;
            return $stdev !== null ? (float) $stdev / 10 : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function contrastScore(string $path): ?float
    {
        if (! extension_loaded('imagick')) {
            return null;
        }
        try {
            $im = new \Imagick($path);
            $im->setImageColorspace(\Imagick::COLORSPACE_GRAY);
            $stats = $im->getImageChannelStatistics();
            $im->destroy();
            $stdev = $stats[\Imagick::CHANNEL_GRAY]['standardDeviation'] ?? null;
            return $stdev !== null ? (float) $stdev : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
