<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\OAuth2\Server\EventEmitting;

use ADBC\Vendor\League\Event\EventDispatcher;
use ADBC\Vendor\League\Event\ListenerPriority;
final class EventEmitter extends EventDispatcher
{
    public function addListener(string $event, callable $listener, int $priority = ListenerPriority::NORMAL): self
    {
        $this->subscribeTo($event, $listener, $priority);
        return $this;
    }
    public function emit(object $event): object
    {
        return $this->dispatch($event);
    }
}
