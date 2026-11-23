<?php

declare (strict_types=1);
namespace ADBC\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use ADBC\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use ADBC\Vendor\phpDocumentor\Reflection\Types\Context;
use ADBC\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
interface PHPStanFactory
{
    public function create(PhpDocTagNode $node, Context $context): Tag;
    public function supports(PhpDocTagNode $node, Context $context): bool;
}
