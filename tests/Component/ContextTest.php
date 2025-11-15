<?php

namespace App\Tests\Component;

use Flow\Component\Context;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    public function testParseExpressionKeepsObjectLiteralKeysUntouched(): void
    {
        $context = new Context();
        $result = $context->parseExpression("{ width: '3rem' }");

        $this->assertSame("{ width: '3rem' }", $result);
    }

    public function testParseExpressionPrefixesIdentifiers(): void
    {
        $context = new Context();
        $result = $context->parseExpression('style.width');

        $this->assertSame('this.style.width', $result);
    }

    public function testParseExpressionRespectsFunctionParameters(): void
    {
        $context = new Context();
        $result = $context->parseExpression('items.map(item => item.id)');

        $this->assertSame('this.items.map(item => item.id)', $result);
    }
}
