<?php

declare (strict_types=1);
namespace ADBC\Vendor\Lcobucci\JWT\Encoding;

use DateTimeImmutable;
use ADBC\Vendor\Lcobucci\JWT\ClaimsFormatter;
use ADBC\Vendor\Lcobucci\JWT\Token\RegisteredClaims;
use function array_key_exists;
final class MicrosecondBasedDateConversion implements ClaimsFormatter
{
    /** @inheritdoc */
    public function formatClaims(array $claims): array
    {
        foreach (RegisteredClaims::DATE_CLAIMS as $claim) {
            if (!array_key_exists($claim, $claims)) {
                continue;
            }
            $claims[$claim] = $this->convertDate($claims[$claim]);
        }
        return $claims;
    }
    private function convertDate(DateTimeImmutable $date): int|float
    {
        if ($date->format('u') === '000000') {
            return (int) $date->format('U');
        }
        return (float) $date->format('U.u');
    }
}
