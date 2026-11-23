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
use ADBC\Vendor\phpDocumentor\Reflection\Types\AggregatedType;
use ADBC\Vendor\phpDocumentor\Reflection\Types\Compound;
use ADBC\Vendor\phpDocumentor\Reflection\Types\Integer;
use ADBC\Vendor\phpDocumentor\Reflection\Types\String_;
/**
 * Value Object representing the type `array-key`.
 *
 * @psalm-immutable
 */
class ArrayKey extends AggregatedType implements PseudoType
{
    public function __construct()
    {
        parent::__construct([new String_(), new Integer()], '|');
    }
    public function underlyingType(): Type
    {
        return new Compound([new String_(), new Integer()]);
    }
    public function __toString(): string
    {
        return 'array-key';
    }
}
