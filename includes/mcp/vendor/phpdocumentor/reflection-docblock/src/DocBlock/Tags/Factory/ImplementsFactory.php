<?php

declare (strict_types=1);
namespace ADBC\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use ADBC\Vendor\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use ADBC\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use ADBC\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Implements_;
use ADBC\Vendor\phpDocumentor\Reflection\TypeResolver;
use ADBC\Vendor\phpDocumentor\Reflection\Types\Context;
use ADBC\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\ImplementsTagValueNode;
use ADBC\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use ADBC\Vendor\Webmozart\Assert\Assert;
use function is_string;
/**
 * @internal This class is not part of the BC promise of this library.
 */
final class ImplementsFactory implements PHPStanFactory
{
    private DescriptionFactory $descriptionFactory;
    private TypeResolver $typeResolver;
    public function __construct(TypeResolver $typeResolver, DescriptionFactory $descriptionFactory)
    {
        $this->descriptionFactory = $descriptionFactory;
        $this->typeResolver = $typeResolver;
    }
    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof ImplementsTagValueNode && $node->name === '@implements';
    }
    public function create(PhpDocTagNode $node, Context $context): Tag
    {
        $tagValue = $node->value;
        Assert::isInstanceOf($tagValue, ImplementsTagValueNode::class);
        $description = $tagValue->getAttribute('description');
        if (is_string($description) === \false) {
            $description = $tagValue->description;
        }
        return new Implements_($this->typeResolver->createType($tagValue->type, $context), $this->descriptionFactory->create($description, $context));
    }
}
