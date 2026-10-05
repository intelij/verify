<?php

namespace App\Services\Extraction;

use App\Services\Extraction\Contracts\ExtractionProvider;
use App\Services\Extraction\Contracts\ProviderUnavailableException;

/**
 * Ordered provider chain. Documents are only sent to providers that are
 * configured AND enabled — fallback never silently leaks identity documents
 * to an unconfigured third party.
 */
class ProviderManager
{
    /** @var ExtractionProvider[] */
    private array $providers = [];

    public function register(ExtractionProvider $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    /** @return ExtractionProvider[] ordered per config */
    public function chain(): array
    {
        $order = config('verification.extraction_providers', ['tesseract']);
        $chain = [];
        foreach ($order as $name) {
            if (isset($this->providers[$name]) && $this->providers[$name]->isAvailable()) {
                $chain[] = $this->providers[$name];
            }
        }
        return $chain;
    }

    /**
     * Try each available provider that supports the file. Returns the first
     * successful result; throws ProviderUnavailableException when none could
     * process it at all.
     */
    public function extract(string $absolutePath, string $mimeType, callable $onAttempt): ExtractionResult
    {
        $lastError = null;
        foreach ($this->chain() as $provider) {
            if (! $provider->supports($mimeType, $absolutePath)) {
                $onAttempt($provider->name(), 'skipped', 'unsupported_mime', null);
                continue;
            }
            $start = hrtime(true);
            try {
                $result = $provider->extract($absolutePath, $mimeType);
                $onAttempt($provider->name(), 'success', null, (int) ((hrtime(true) - $start) / 1e6));
                return $result;
            } catch (ProviderUnavailableException $e) {
                $onAttempt($provider->name(), 'unavailable', $e->errorCode, null);
                $lastError = $e;
            } catch (\Throwable $e) {
                $onAttempt($provider->name(), 'error', 'exception', null);
                $lastError = $e;
            }
        }

        throw new ProviderUnavailableException(
            $lastError instanceof ProviderUnavailableException ? $lastError->providerName : 'chain',
            'all_providers_failed',
            $lastError?->getMessage() ?? 'No extraction provider available',
        );
    }
}
