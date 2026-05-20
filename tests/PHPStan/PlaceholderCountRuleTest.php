<?php

declare(strict_types=1);

namespace Artemeon\Database\Tests\PHPStan;

use Artemeon\Database\PHPStan\PlaceholderCountRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<PlaceholderCountRule>
 */
final class PlaceholderCountRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new PlaceholderCountRule($this->createReflectionProvider());
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/placeholder-count.php'], [
            [
                'SQL has 2 placeholder(s) but 1 parameter(s) given to getPArray().',
                12,
            ],
            [
                'SQL has 1 placeholder(s) but 0 parameter(s) given to getPRow().',
                13,
            ],
        ]);
    }
}
