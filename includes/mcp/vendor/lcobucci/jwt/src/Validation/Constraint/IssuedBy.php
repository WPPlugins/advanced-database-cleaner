<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Validation\Constraint;

use ADBC\Vendor\Lcobucci\JWT\Token;
use ADBC\Vendor\Lcobucci\JWT\Validation\Constraint;
use ADBC\Vendor\Lcobucci\JWT\Validation\ConstraintViolation;
final class IssuedBy implements Constraint
{
    /** @var non-empty-string[] */
    private readonly array $issuers;
    /** @param non-empty-string ...$issuers */
    public function __construct(string ...$issuers)
    {
        $this->issuers = $issuers;
    }
    public function assert(Token $token): void
    {
        if (!$token->hasBeenIssuedBy(...$this->issuers)) {
            throw ConstraintViolation::error('The token was not issued by the given issuers', $this);
        }
    }
}
