<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT;

use ADBC\Vendor\Lcobucci\JWT\Validation\Constraint;
use ADBC\Vendor\Lcobucci\JWT\Validation\NoConstraintsGiven;
use ADBC\Vendor\Lcobucci\JWT\Validation\RequiredConstraintsViolated;
interface Validator
{
    /**
     * @throws RequiredConstraintsViolated
     * @throws NoConstraintsGiven
     */
    public function assert(Token $token, Constraint ...$constraints): void;
    /** @throws NoConstraintsGiven */
    public function validate(Token $token, Constraint ...$constraints): bool;
}
