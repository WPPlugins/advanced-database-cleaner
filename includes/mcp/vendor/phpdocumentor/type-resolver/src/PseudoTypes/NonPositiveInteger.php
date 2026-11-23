<?php

declare (strict_types=1);
/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link      http://phpdoc.org
 */
namespace ADBC\Vendor\phpDocumentor\Reflection\PseudoTypes;

use ADBC\Vendor\phpDocumentor\Reflection\PseudoType;
use ADBC\Vendor\phpDocumentor\Reflection\Type;
use ADBC\Vendor\phpDocumentor\Reflection\Types\Integer;
/**
 * Value Object representing the type 'non-positive-int'.
 *
 * @psalm-immutable
 */
final class NonPositiveInteger extends Integer implements PseudoType
{
    public function underlyingType(): Type
    {
        return new Integer();
    }
    public function __toString(): string
    {
        return 'non-positive-int';
    }
}
