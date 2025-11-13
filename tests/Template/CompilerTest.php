<?php

namespace App\Tests\Template;

use Flow\Component\Context;
use Flow\Component\Element as FlowElement;
use Flow\Component\FragmentElement;
use Flow\Exception\FlowException;
use Flow\Template\Compiler;
use PHPUnit\Framework\TestCase;

class CompilerTest extends TestCase
{
    public function testCompilerStoresScopedStylesAndAddsRootAttribute(): void
    {
        $template = <<<HTML
<div class="wrapper">Hello</div>
<style scoped>
.wrapper { color: red; }
</style>
HTML;

        $compiler = new Compiler();
        $element = $compiler->compile($template, new Context());
        $styles = $compiler->getStyles();

        $this->assertCount(1, $styles);
        $style = $styles[0];
        $this->assertTrue($style['scoped']);
        $this->assertNotEmpty($style['scopeId']);
        $this->assertStringContainsString('[data-flow-scope="' . $style['scopeId'] . '"] .wrapper', $style['content']);
        $this->assertInstanceOf(FragmentElement::class, $element);

        $target = null;
        foreach ($element->children as $child) {
            if ($child instanceof FlowElement) {
                $target = $child;
                break;
            }
        }

        $this->assertNotNull($target);
        $this->assertArrayHasKey('data-flow-scope', $target->props);
        $this->assertSame($style['scopeId'], $target->props['data-flow-scope']);
        $this->assertArrayNotHasKey('scoped', $style['attributes']);
    }

    public function testScopedStylesRequireHtmlRoot(): void
    {
        $template = <<<HTML
<MyComponent></MyComponent>
<style scoped>
.root { color: red; }
</style>
HTML;

        $compiler = new Compiler();

        $this->expectException(FlowException::class);
        $this->expectExceptionMessage('Scoped styles require the template root element to be a single HTML element.');

        $compiler->compile($template, new Context());
    }
}
