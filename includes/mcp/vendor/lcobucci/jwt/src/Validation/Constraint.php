<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Validation;

use ADBC\Vendor\Lcobucci\JWT\Token;
interface Constraint
{
    /** @throws ConstraintViolation */
    public function assert(Token $token): void;
}
