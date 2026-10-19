<?php

declare(strict_types=1);

namespace Preflow\Notify;

use Preflow\Notify\Transport\FailoverTransport;
use Preflow\Notify\Transport\InMemoryTransport;
use Preflow\Notify\Transport\LogTransport;
use Preflow\Notify\Transport\NullTransport;

/**
 * Builds transports by name from the 'transports' section of config/notify.php.
 * Transports are created on first use, so a misconfigured optional provider
 * cannot break boot.
 */
final class TransportFactory
{
    /** Install hints for drivers provided by optional bridges. */
    private const BRIDGE_HINTS = [
        'symfony-mailer' => ' Install symfony/mailer.',
        'symfony-notifier-sms' => ' Install symfony/notifier.',
    ];

    /** @var array<string, \Closure(array, string, self): Transport> */
    private array $drivers = [];

    /** @var array<string, Transport> */
    private array $resolved = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /**
     * @param array<string, array{driver: string}&array<string, mixed>> $transports
     */
    public function __construct(
        private readonly array $transports,
        private readonly string $basePath,
    ) {
        $this->extend('null', fn (array $config, string $name) => new NullTransport($name));
        $this->extend('memory', fn (array $config, string $name) => new InMemoryTransport($name));
        $this->extend('log', fn (array $config, string $name) => new LogTransport(
            (string) ($config['path'] ?? rtrim($this->basePath, '/') . '/storage/logs/messages.log'),
            $name,
        ));
        $this->extend('failover', function (array $config, string $name, self $factory) {
            $pairs = [];
            foreach ((array) ($config['transports'] ?? []) as $inner) {
                $pairs[] = [(string) $inner, $factory->get((string) $inner)];
            }

            return new FailoverTransport($pairs, $name);
        });
    }

    /**
     * Register a driver. The factory receives the transport's config array,
     * its configured name, and this factory (to resolve other transports).
     *
     * @param \Closure(array, string, self): Transport $factory
     */
    public function extend(string $driver, \Closure $factory): void
    {
        $this->drivers[$driver] = $factory;
    }

    public function hasDriver(string $driver): bool
    {
        return isset($this->drivers[$driver]);
    }

    /** @throws TransportException */
    public function get(string $name): Transport
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (!isset($this->transports[$name])) {
            throw new TransportException(sprintf(
                'Unknown transport "%s". Define it under \'transports\' in config/notify.php.',
                $name,
            ));
        }

        if (isset($this->resolving[$name])) {
            throw new TransportException(sprintf('Transport "%s" references itself through failover.', $name));
        }

        $config = $this->transports[$name];
        $driver = (string) ($config['driver'] ?? '');
        if (!isset($this->drivers[$driver])) {
            throw new TransportException(sprintf(
                'Transport "%s" uses driver "%s", which is not available.%s',
                $name,
                $driver,
                self::BRIDGE_HINTS[$driver] ?? '',
            ));
        }

        $this->resolving[$name] = true;
        try {
            return $this->resolved[$name] = ($this->drivers[$driver])($config, $name, $this);
        } finally {
            unset($this->resolving[$name]);
        }
    }
}
