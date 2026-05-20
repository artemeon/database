<?php

declare(strict_types=1);

namespace Artemeon\Database\PHPStan;

use Artemeon\Database\ConnectionInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<MethodCall>
 */
final class PlaceholderCountRule implements Rule
{
    private const array METHODS = ['getPArray' => true, 'getPRow' => true, 'getGenerator' => true, '_pQuery' => true];

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        if (!isset(self::METHODS[$methodName])) {
            return [];
        }

        $callerType = $scope->getType($node->var);
        if (!$this->reflectionProvider->hasClass(ConnectionInterface::class)) {
            return [];
        }

        $interface = $this->reflectionProvider->getClass(ConnectionInterface::class);
        $isConnection = false;
        foreach ($callerType->getObjectClassReflections() as $classReflection) {
            if ($classReflection->getName() === $interface->getName() || $classReflection->is($interface->getName())) {
                $isConnection = true;

                break;
            }
        }

        if (!$isConnection) {
            return [];
        }

        $args = $node->getArgs();
        if (count($args) < 2) {
            return [];
        }

        $queryType = $scope->getType($args[0]->value);
        $constantStrings = $queryType->getConstantStrings();
        if (count($constantStrings) !== 1) {
            return [];
        }

        $paramsType = $scope->getType($args[1]->value);
        $arrays = $paramsType->getConstantArrays();
        if (count($arrays) !== 1) {
            return [];
        }

        $paramsCount = count($arrays[0]->getValueTypes());
        $placeholderCount = $this->countPlaceholders($constantStrings[0]->getValue());

        if ($paramsCount === $placeholderCount) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'SQL has %d placeholder(s) but %d parameter(s) given to %s().',
                $placeholderCount,
                $paramsCount,
                $methodName,
            ))->identifier('artemeon.database.placeholderCount')->build(),
        ];
    }

    private function countPlaceholders(string $query): int
    {
        $count = 0;
        $length = strlen($query);
        $inSingle = false;
        $inDouble = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $query[$i];
            $next = $i + 1 < $length ? $query[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }

                continue;
            }

            if ($inSingle) {
                if ($char === '\\' && $next !== '') {
                    $i++;

                    continue;
                }
                if ($char === "'") {
                    if ($next === "'") {
                        $i++;

                        continue;
                    }
                    $inSingle = false;
                }

                continue;
            }

            if ($inDouble) {
                if ($char === '\\' && $next !== '') {
                    $i++;

                    continue;
                }
                if ($char === '"') {
                    if ($next === '"') {
                        $i++;

                        continue;
                    }
                    $inDouble = false;
                }

                continue;
            }

            if ($char === "'") {
                $inSingle = true;

                continue;
            }

            if ($char === '"') {
                $inDouble = true;

                continue;
            }

            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;

                continue;
            }

            if ($char === '?') {
                $count++;
            }
        }

        return $count;
    }
}
