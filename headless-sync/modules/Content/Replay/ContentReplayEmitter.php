<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Replay;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\ReplayEmitterInterface;
use HSP\Modules\Content\WpContentLoader;

/**
 * Content-module implementation of the core ReplayEmitterInterface (DECISION T).
 *
 * For a page/post/category aggregate, reads CURRENT WordPress state through the
 * WpContentLoader (ADR-044/ADR-045 — WordPress wins) and emits ONE synthetic event
 * through the outbox via the content-scoped EventProviderInterface (the same
 * EventProvider/OutboxWriter path organic edits use — no new capture path, Rule 3).
 *
 * Public-set decision (OPEN-10 public set = {publish}):
 *   page/post: exists AND post_status === 'publish'  → content.{type}.updated
 *              missing OR non-publish                 → content.{type}.deleted
 *   category:  term exists                            → content.category.updated
 *              term missing                           → content.category.deleted
 *   media:     attachment exists                      → content.media.updated
 *              attachment missing                     → content.media.deleted
 *   (Categories have no post_status, and attachments carry 'inherit' — which is outside
 *    the public set — so for both, existence is the only membership signal.)
 *
 * The emitted event takes a fresh aggregate_version from wp_hsp_aggregate_counters
 * (DECISION 2) inside the OutboxWriter, so it is strictly greater than the aggregate's
 * latest_processed_version and passes the DECISION J stale guard naturally (DECISION T
 * point 1). The worker reloads current WP state again at process time (DECISION H); the
 * .updated/.deleted decision here selects the correct handler path (upsert vs tombstone).
 *
 * Module isolation (Rule 5): implements a core-owned contract; core never imports this class.
 */
final class ContentReplayEmitter implements ReplayEmitterInterface
{
    private const AGGREGATE_TYPES = ['page', 'post', 'category', 'media', 'tag'];

    public function __construct(
        private readonly EventProviderInterface $eventProvider,
        private readonly WpContentLoader        $wpContentLoader,
    ) {}

    /** @return string[] */
    public function getSupportedAggregateTypes(): array
    {
        return self::AGGREGATE_TYPES;
    }

    public function emitForAggregate(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): EventInterface {
        $action = $this->resolveAction($aggregateType, $aggregateId);

        $eventType = "content.{$aggregateType}.{$action}";

        return $this->eventProvider->provide(
            $eventType,
            $aggregateId,
            [
                'correlation_id'    => $correlationId,
                'causation_id'      => $causationId,
                'source_updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'payload'           => [
                    'replay'         => true,
                    'aggregate_type' => $aggregateType,
                    'aggregate_id'   => $aggregateId,
                ],
            ],
        );
    }

    /**
     * Decide 'updated' (current WP state is public) vs 'deleted' (missing / non-public)
     * per DECISION T point 2.
     */
    private function resolveAction(string $aggregateType, string $aggregateId): string
    {
        return $this->isPublic($aggregateType, $aggregateId) ? 'updated' : 'deleted';
    }

    private function isPublic(string $aggregateType, string $aggregateId): bool
    {
        switch ($aggregateType) {
            case 'page':
            case 'post':
                $post = $this->wpContentLoader->loadPost((int) $aggregateId);
                if ($post === null) {
                    return false;
                }
                $status = (string) ($post['post_status'] ?? '');
                return $status === 'publish';

            case 'category':
            case 'tag':
                // Terms have no post_status; existence is the membership signal. Categories and
                // tags share this path — the loader looks a term up by id across taxonomies.
                return $this->wpContentLoader->loadTerm((int) $aggregateId) !== null;

            case 'media':
                // Attachments carry post_status='inherit', which is outside the {publish}
                // public set, so status cannot be the signal — existence is (as for terms).
                return $this->wpContentLoader->loadAttachment((int) $aggregateId) !== null;

            default:
                throw new \InvalidArgumentException(
                    "ContentReplayEmitter cannot replay aggregate type '{$aggregateType}'."
                );
        }
    }
}
