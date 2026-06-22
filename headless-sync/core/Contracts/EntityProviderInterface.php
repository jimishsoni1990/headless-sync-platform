<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Extracts a source model from WordPress for a given entity identifier.
 *
 * Workers are stateless (ADR-044): they call this interface on each event
 * to reload the current WordPress state. The source model is then passed
 * to a TransformerInterface — never persisted directly.
 */
interface EntityProviderInterface
{
    /**
     * Load the current WordPress state for the entity.
     *
     * @param  string $aggregateType e.g. 'post', 'page', 'category'
     * @param  string $aggregateId   WordPress entity identifier
     * @return object Source model (aggregate-type-specific; passed to TransformerInterface)
     * @throws \RuntimeException if the entity cannot be found or loaded
     */
    public function provide(string $aggregateType, string $aggregateId): object;

    /**
     * Returns the aggregate type(s) this provider handles.
     *
     * @return string[] e.g. ['post', 'page']
     */
    public function getSupportedAggregateTypes(): array;
}
