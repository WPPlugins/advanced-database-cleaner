<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\Event;

use LogicException;
use ADBC\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
use ADBC\Vendor\Psr\EventDispatcher\ListenerProviderInterface;
class UnableToSubscribeListener extends LogicException
{
    public static function becauseTheListenerProviderDoesNotAcceptListeners(ListenerProviderInterface $configuredListenerProvider): UnableToSubscribeListener
    {
        $providerClass = get_class($configuredListenerProvider);
        return new UnableToSubscribeListener("Unable to add listener because the configured listener provider {$providerClass} is not an instance of " . ListenerRegistry::class);
    }
    public static function becauseTheEventDispatcherDoesNotAcceptListeners(EventDispatcherInterface $configuredListenerProvider): UnableToSubscribeListener
    {
        $providerClass = get_class($configuredListenerProvider);
        return new UnableToSubscribeListener("Unable to add listener because the internal dispatcher {$providerClass} is not an instance of " . ListenerRegistry::class);
    }
}
