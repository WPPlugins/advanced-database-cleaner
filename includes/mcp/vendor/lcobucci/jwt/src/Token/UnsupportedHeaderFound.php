<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Token;

use InvalidArgumentException;
use ADBC\Vendor\Lcobucci\JWT\Exception;
final class UnsupportedHeaderFound extends InvalidArgumentException implements Exception
{
    public static function encryption(): self
    {
        return new self('Encryption is not supported yet');
    }
}
