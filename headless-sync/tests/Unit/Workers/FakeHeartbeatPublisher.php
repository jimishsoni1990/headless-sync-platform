<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Workers\HeartbeatPublisherInterface;
use HSP\Core\Workers\HeartbeatRecord;

final class FakeHeartbeatPublisher implements HeartbeatPublisherInterface
{
    /** @var HeartbeatRecord[] */
    public array $published = [];

    public function publish(HeartbeatRecord $record): void
    {
        $this->published[] = $record;
    }
}
