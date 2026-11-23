<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\Event;

interface ListenerSubscriber
{
    public function subscribeListeners(ListenerRegistry $acceptor): void;
}
