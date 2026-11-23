<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Signer\Hmac;

use ADBC\Vendor\Lcobucci\JWT\Signer\Hmac;
final class Sha512 extends Hmac
{
    public function algorithmId(): string
    {
        return 'HS512';
    }
    public function algorithm(): string
    {
        return 'sha512';
    }
    public function minimumBitsLengthForKey(): int
    {
        return 512;
    }
}
