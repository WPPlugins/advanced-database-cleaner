<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\OAuth2\Server\EventEmitting;

use ADBC\Vendor\League\Event\ListenerRegistry;
use ADBC\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
trait EmitterAwarePolyfill
{
    private EventEmitter $emitter;
    public function getEmitter(): EventEmitter
    {
        return $this->emitter ??= new EventEmitter();
    }
    public function setEmitter(EventEmitter $emitter): self
    {
        $this->emitter = $emitter;
        return $this;
    }
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->getEmitter();
    }
    public function getListenerRegistry(): ListenerRegistry
    {
        return $this->getEmitter();
    }
}
