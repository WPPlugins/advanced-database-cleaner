<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\Event;

interface ListenerRegistry
{
    public function subscribeTo(string $event, callable $listener, int $priority = ListenerPriority::NORMAL): void;
    public function subscribeOnceTo(string $event, callable $listener, int $priority = ListenerPriority::NORMAL): void;
    public function subscribeListenersFrom(ListenerSubscriber $subscriber): void;
}
