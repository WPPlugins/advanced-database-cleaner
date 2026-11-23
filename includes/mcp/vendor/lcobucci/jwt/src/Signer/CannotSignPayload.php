<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Signer;

use InvalidArgumentException;
use ADBC\Vendor\Lcobucci\JWT\Exception;
final class CannotSignPayload extends InvalidArgumentException implements Exception
{
    public static function errorHappened(string $error): self
    {
        return new self('There was an error while creating the signature:' . $error);
    }
}
