<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Validation;

use ADBC\Vendor\Lcobucci\JWT\Exception;
use RuntimeException;
final class ConstraintViolation extends RuntimeException implements Exception
{
    /** @param class-string<Constraint>|null $constraint */
    public function __construct(string $message = '', public readonly ?string $constraint = null)
    {
        parent::__construct($message);
    }
    /** @param non-empty-string $message */
    public static function error(string $message, Constraint $constraint): self
    {
        return new self(message: $message, constraint: $constraint::class);
    }
}
