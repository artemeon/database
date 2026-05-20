<?php

declare(strict_types=1);

namespace Artemeon\Database\PHPStan;

use Artemeon\Database\ConnectionInterface;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final class GetPRowReturnTypeExtension implements DynamicMethodReturnTypeExtension
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
        return $methodReflection->getName() === 'getPRow';
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

        if ($rowType->getConstantArrays() === []) {
            return $rowType;
        }

        $empty = ConstantArrayTypeBuilder::createEmpty()->getArray();

        return TypeCombinator::union($rowType, $empty);
    }
}
