<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Validation\Constraint;

use ADBC\Vendor\Lcobucci\JWT\Token;
use ADBC\Vendor\Lcobucci\JWT\UnencryptedToken;
use ADBC\Vendor\Lcobucci\JWT\Validation\Constraint;
use ADBC\Vendor\Lcobucci\JWT\Validation\ConstraintViolation;
use function in_array;
final class HasClaim implements Constraint
{
    /** @param non-empty-string $claim */
    public function __construct(private readonly string $claim)
    {
        if (in_array($claim, Token\RegisteredClaims::ALL, \true)) {
            throw CannotValidateARegisteredClaim::create($claim);
        }
    }
    public function assert(Token $token): void
    {
        if (!$token instanceof UnencryptedToken) {
            throw ConstraintViolation::error('You should pass a plain token', $this);
        }
        $claims = $token->claims();
        if (!$claims->has($this->claim)) {
            throw ConstraintViolation::error('The token does not have the claim "' . $this->claim . '"', $this);
        }
    }
}
