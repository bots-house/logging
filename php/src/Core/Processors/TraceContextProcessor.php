<?php

declare(strict_types=1);

namespace Adheart\Logging\Core\Processors;

use Adheart\Logging\Core\Trace\TraceContextProviderInterface;

final class TraceContextProcessor
{
    /** @var list<TraceContextProviderInterface> */
    private array $providers;

    /**
     * @param iterable<TraceContextProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    /**
     * Compatible with Monolog 2 (array records) and Monolog 3 (LogRecord objects).
     *
     * @param mixed $record
     *
     * @return mixed
     */
    public function __invoke($record)
    {
        if (is_array($record)) {
            $trace = $record['extra']['trace'] ?? [];
            if (!is_array($trace)) {
                $trace = [];
            }

            foreach ($this->providers as $provider) {
                $provided = $provider->provide();

                foreach ($provided as $key => $value) {
                    if (array_key_exists($key, $trace)) {
                        continue;
                    }

                    $trace[$key] = $value;
                }
            }

            $record['extra']['trace'] = $trace;

            return $record;
        }

        if (!is_object($record)) {
            return $record;
        }

        $extra = isset($record->extra) && is_array($record->extra) ? $record->extra : [];
        $trace = $extra['trace'] ?? [];
        if (!is_array($trace)) {
            $trace = [];
        }

        foreach ($this->providers as $provider) {
            $provided = $provider->provide();

            foreach ($provided as $key => $value) {
                if (array_key_exists($key, $trace)) {
                    continue;
                }

                $trace[$key] = $value;
            }
        }

        $extra['trace'] = $trace;

        if (method_exists($record, 'with')) {
            return $record->with(extra: $extra);
        }

        if (property_exists($record, 'extra')) {
            $record->extra = $extra;

            return $record;
        }

        return $record;
    }
}
