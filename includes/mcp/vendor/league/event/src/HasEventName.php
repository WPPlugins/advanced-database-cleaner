<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\Event;

interface HasEventName
{
    public function eventName(): string;
}
