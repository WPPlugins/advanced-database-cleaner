<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT;

interface ClaimsFormatter
{
    /**
     * @param array<non-empty-string, mixed> $claims
     *
     * @return array<non-empty-string, mixed>
     */
    public function formatClaims(array $claims): array;
}
