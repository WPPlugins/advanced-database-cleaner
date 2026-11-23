<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Signer\Rsa;

use ADBC\Vendor\Lcobucci\JWT\Signer\Rsa;
use const OPENSSL_ALGO_SHA384;
final class Sha384 extends Rsa
{
    public function algorithmId(): string
    {
        return 'RS384';
    }
    public function algorithm(): int
    {
        return OPENSSL_ALGO_SHA384;
    }
}
