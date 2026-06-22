<?php

declare(strict_types=1);

namespace HSP\Core\Container;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

/**
 * PSR-11 compatible DI container.
 *
 * Constructor injection only — ADR-012. Callers outside the composition root
 * must never call get() directly; they must receive dependencies through their
 * constructors.
 *
 * This container intentionally has no auto-wiring: every binding must be
 * registered explicitly via bind() or singleton(). Explicit registration is
 * required by Doc 2 §9 and is consistent with the event/adapter registry
 * policy (no reflection-based discovery).
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable> Factory definitions */
    private array $bindings = [];

    /** @var array<string, object> Resolved singleton instances */
    private array $resolved = [];

    /** @var array<string, true> Bindings marked as singletons */
    private array $singletons = [];

    /**
     * Register a factory binding.
     *
     * @param string   $abstract The service identifier (interface or class name).
     * @param callable $factory  Receives this Container; returns the instance.
     */
    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->resolved[$abstract]);
    }

    /**
     * Register a singleton binding — resolved once, shared thereafter.
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract]  = $factory;
        $this->singletons[$abstract] = true;
        unset($this->resolved[$abstract]);
    }

    /**
     * Register a pre-built instance as a singleton.
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->resolved[$abstract]   = $instance;
        $this->singletons[$abstract] = true;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function get(string $id): mixed
    {
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        if (! isset($this->bindings[$id])) {
            throw new NotFoundException("No binding registered for '{$id}'.");
        }

        $instance = ($this->bindings[$id])($this);

        if (isset($this->singletons[$id])) {
            $this->resolved[$id] = $instance;
        }

        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->resolved[$id]);
    }
}
