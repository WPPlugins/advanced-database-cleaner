<?php

declare (strict_types=1);
/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link https://phpdoc.org
 */
namespace ADBC\Vendor\phpDocumentor\Reflection\PseudoTypes;

use ADBC\Vendor\phpDocumentor\Reflection\PseudoType;
use ADBC\Vendor\phpDocumentor\Reflection\Type;
use ADBC\Vendor\phpDocumentor\Reflection\Types\Boolean;
/**
 * Value Object representing the PseudoType 'False', which is a Boolean type.
 *
 * @psalm-immutable
 */
final class True_ extends Boolean implements PseudoType
{
    public function underlyingType(): Type
    {
        return new Boolean();
    }
    public function __toString(): string
    {
        return 'true';
    }
}
