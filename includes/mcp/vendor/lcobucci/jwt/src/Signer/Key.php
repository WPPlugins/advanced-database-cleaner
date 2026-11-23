<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Signer;

interface Key
{
    /** @return non-empty-string */
    public function contents(): string;
    public function passphrase(): string;
}
