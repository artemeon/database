<?php

declare(strict_types=1);

namespace Artemeon\Database\PHPStan;

use Artemeon\Database\ConnectionInterface;
use Generator;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

final class GetGeneratorReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(private readonly SqlReturnShapeAnalyser $analyser)
    {
    }

    public function getClass(): string
    {
        return ConnectionInterface::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'getGenerator';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $args = $methodCall->getArgs();
        if ($args === []) {
            return null;
        }

        $queryType = $scope->getType($args[0]->value);
        $constantStrings = $queryType->getConstantStrings();
        if (count($constantStrings) !== 1) {
            return null;
        }

        $rowType = $this->analyser->analyseRow($constantStrings[0]->getValue());
        if ($rowType === null) {
            return null;
        }

        return new GenericObjectType(Generator::class, [
            new IntegerType(),
            $rowType,
        ]);
    }
}
