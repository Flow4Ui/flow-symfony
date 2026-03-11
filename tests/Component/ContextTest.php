<?php

namespace App\Tests\Component;

use Flow\Attributes\Component;
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

    public function testParseExpressionHandlesUnicodeLiterals(): void
    {
        $context = new Context();
        $result = $context->parseExpression("foo ? '—' : bar");

        $this->assertSame("this.foo ? '—' : this.bar", $result);

        $result = $context->parseExpression("unvalued ? '—' : formatMoney(lineTotal(entry))");

        $this->assertSame("this.unvalued ? '—' : this.formatMoney(this.lineTotal(this.entry))", $result);
    }

    public function testParseExpressionHandlesMultiStatementHandlerBodies(): void
    {
        $context = new Context(null, new Component(props: ['locale']));
        $expression = '$router.push({ name: \'admin_home\', params: { locale: locale || \'pt\' } }); if ($window.innerWidth < 1024) { $emit(\'toggle\'); }';
        $result = $context->parseExpression($expression);

        $this->assertSame('this.$router.push({ name: \'admin_home\', params: { locale: this.$props.locale || \'pt\' } }); if (window.innerWidth < 1024) { this.$emit(\'toggle\'); }', $result);
    }

    public function testParseExpressionScopesWindowLikeVueTemplateExpressions(): void
    {
        $context = new Context();
        $result = $context->parseExpression('window.innerWidth < 1024');

        $this->assertSame('this.window.innerWidth < 1024', $result);
    }

    public function testParseExpressionTreatsDollarWindowAsGlobal(): void
    {
        $context = new Context();
        $result = $context->parseExpression('$window.innerWidth < 1024');

        $this->assertSame('window.innerWidth < 1024', $result);
    }

    public function testParseExpressionExpandsShorthandObjectPropertiesForScopedValues(): void
    {
        $context = new Context(null, new Component(props: ['locale']));
        $expression = '$router.push({ name: \'forgot_password\', params: { locale: locale }, query: { ...(tenantLookup ? { tenant_lookup: tenantLookup } : {}), ...(email ? { email } : {}) } })';
        $result = $context->parseExpression($expression);

        $this->assertSame('this.$router.push({ name: \'forgot_password\', params: { locale: this.$props.locale }, query: { ...(this.tenantLookup ? { tenant_lookup: this.tenantLookup } : {}), ...(this.email ? { email: this.email } : {}) } })', $result);
    }

    public function testParseExpressionKeepsLocalShorthandObjectProperties(): void
    {
        $context = new Context();
        $result = $context->parseExpression('items.map(email => ({ email }))');

        $this->assertSame('this.items.map(email => ({ email }))', $result);
    }
}
