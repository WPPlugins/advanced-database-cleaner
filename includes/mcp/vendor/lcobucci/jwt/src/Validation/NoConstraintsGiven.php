<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Validation;

use ADBC\Vendor\Lcobucci\JWT\Exception;
use RuntimeException;
final class NoConstraintsGiven extends RuntimeException implements Exception
{
}
