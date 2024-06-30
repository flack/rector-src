<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\PHPStan;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\UseUse;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\UnionType;
use Rector\Naming\Naming\UseImportsResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\StaticTypeMapper\ValueObject\Type\AliasedObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\FullyQualifiedObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\NonExistingObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\ShortenedGenericObjectType;
use Rector\StaticTypeMapper\ValueObject\Type\ShortenedObjectType;
use Rector\UseImports\UseImportsScopeResolver;
use Rector\UseImports\ValueObject\UseImportsScope;

final readonly class ObjectTypeSpecifier
{
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private UseImportsResolver $useImportsResolver,
        private UseImportsScopeResolver $useImportsScopeResolver,
    ) {
    }

    public function narrowToFullyQualifiedOrAliasedObjectType(
        Node $node,
        ObjectType $objectType,
        ?Scope $scope = null,
    ): FullyQualifiedObjectType | AliasedObjectType | ShortenedGenericObjectType | ShortenedObjectType | NonExistingObjectType | UnionType | MixedType {
        $filePath = $node->getAttribute(AttributeKey::FILE_PATH);

        // $useImportsScope = $this->useImportsResolver->resolve();
        $useImportScope = $this->useImportsScopeResolver->resolve($filePath);

        $aliasedObjectType = $this->matchAliasedObjectType($objectType, $useImportScope);
        if ($aliasedObjectType instanceof AliasedObjectType) {
            return $aliasedObjectType;
        }

        $shortenedObjectType = $this->matchShortenedObjectType($objectType, $useImportScope);
        if ($shortenedObjectType !== null) {
            return $shortenedObjectType;
        }

        $className = ltrim($objectType->getClassName(), '\\');

        if ($this->reflectionProvider->hasClass($className)) {
            return new FullyQualifiedObjectType($className);
        }

        // invalid type
        return new NonExistingObjectType($className);
    }

    private function matchAliasedObjectType(
        ObjectType $objectType,
        UseImportsScope $useImportsScope
    ): ?AliasedObjectType {
        if ($useImportsScope->getUses() === []) {
            return null;
        }

        $className = $objectType->getClassName();

        foreach ($useImportsScope->getUses() as $use) {
            $prefix = $this->useImportsResolver->resolvePrefix($use);
            foreach ($use->uses as $useUse) {
                if (! $useUse->alias instanceof Identifier) {
                    continue;
                }

                $useName = $prefix . $useUse->name->toString();
                $alias = $useUse->alias->toString();
                $fullyQualifiedName = $prefix . $useUse->name->toString();

                $processAliasedObject = $this->processAliasedObject(
                    $alias,
                    $className,
                    $useName,
                    $fullyQualifiedName
                );

                if ($processAliasedObject instanceof AliasedObjectType) {
                    return $processAliasedObject;
                }
            }
        }

        return null;
    }

    private function processAliasedObject(
        string $alias,
        string $className,
        string $useName,
        string $fullyQualifiedName
    ): ?AliasedObjectType {
        // A. is alias in use statement matching this class alias
        if ($alias === $className) {
            return new AliasedObjectType($alias, $fullyQualifiedName);
        }

        // B. is aliased classes matching the class name
        if ($useName === $className) {
            return new AliasedObjectType($alias, $fullyQualifiedName);
        }

        return null;
    }

    private function matchShortenedObjectType(
        ObjectType $objectType,
        UseImportsScope $useImportsScope
    ): ShortenedObjectType|ShortenedGenericObjectType|null {
        foreach ($useImportsScope->getUses() as $use) {
            $prefix = $use instanceof GroupUse
                ? $use->prefix . '\\'
                : '';
            foreach ($use->uses as $useUse) {
                if ($useUse->alias instanceof Identifier) {
                    continue;
                }

                $partialNamespaceObjectType = $this->matchPartialNamespaceObjectType($prefix, $objectType, $useUse);
                if ($partialNamespaceObjectType instanceof ShortenedObjectType) {
                    return $partialNamespaceObjectType;
                }

                $partialNamespaceObjectType = $this->matchClassWithLastUseImportPart($prefix, $objectType, $useUse);
                if ($partialNamespaceObjectType instanceof FullyQualifiedObjectType) {
                    // keep Generic items
                    if ($objectType instanceof GenericObjectType) {
                        return new ShortenedGenericObjectType(
                            $objectType->getClassName(),
                            $objectType->getTypes(),
                            $partialNamespaceObjectType->getClassName()
                        );
                    }

                    return $partialNamespaceObjectType->getShortNameType();
                }

                if ($partialNamespaceObjectType instanceof ShortenedObjectType) {
                    return $partialNamespaceObjectType;
                }
            }
        }

        return null;
    }

    private function matchPartialNamespaceObjectType(
        string $prefix,
        ObjectType $objectType,
        UseUse $useUse
    ): ?ShortenedObjectType {
        // partial namespace
        if (! \str_starts_with($objectType->getClassName(), $useUse->name->getLast() . '\\')) {
            return null;
        }

        $classNameWithoutLastUsePart = Strings::after($objectType->getClassName(), '\\', 1);

        $connectedClassName = $prefix . $useUse->name->toString() . '\\' . $classNameWithoutLastUsePart;
        if (! $this->reflectionProvider->hasClass($connectedClassName)) {
            return null;
        }

        if ($objectType->getClassName() === $connectedClassName) {
            return null;
        }

        return new ShortenedObjectType($objectType->getClassName(), $connectedClassName);
    }

    /**
     * @return FullyQualifiedObjectType|ShortenedObjectType|null
     */
    private function matchClassWithLastUseImportPart(
        string $prefix,
        ObjectType $objectType,
        UseUse $useUse
    ): ?ObjectType {
        if ($useUse->name->getLast() !== $objectType->getClassName()) {
            return null;
        }

        if (! $this->reflectionProvider->hasClass($prefix . $useUse->name->toString())) {
            return null;
        }

        if ($objectType->getClassName() === $prefix . $useUse->name->toString()) {
            return new FullyQualifiedObjectType($objectType->getClassName());
        }

        return new ShortenedObjectType($objectType->getClassName(), $prefix . $useUse->name->toString());
    }
}
