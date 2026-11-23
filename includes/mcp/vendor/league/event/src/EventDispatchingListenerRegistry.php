<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\Event;

use ADBC\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
interface EventDispatchingListenerRegistry extends ListenerRegistry, EventDispatcherInterface
{
}
