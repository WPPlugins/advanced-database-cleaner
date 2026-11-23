<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\Event;

interface EventDispatcherAware
{
    public function useEventDispatcher(EventDispatcher $dispatcher): void;
    public function eventDispatcher(): EventDispatcher;
}
