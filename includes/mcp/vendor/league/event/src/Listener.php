<?php

declare (strict_types=1);
namespace ADBC\Vendor\League\Event;

interface Listener
{
    public function __invoke(object $event): void;
}
