<?php

namespace App\Tests\Template;

use Flow\Component\ComponentElement;
use Flow\Component\Context;
use Flow\Component\Element as FlowElement;
use Flow\Component\Expression;
use Flow\Component\FragmentElement;
use Flow\Component\TemplateElement;
use Flow\Exception\FlowException;
use Flow\Template\Compiler;
use Flow\Template\PathFlags;
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

    public function testCompilerTransformsVHtmlIntoInnerHtmlProp(): void
    {
        $template = '<div v-html="htmlContent"></div>';

        $compiler = new Compiler();
        $fragment = $compiler->compile($template, new Context());

        $this->assertInstanceOf(FragmentElement::class, $fragment);
        $this->assertNotEmpty($fragment->children);

        $element = $fragment->children[0];

        $this->assertArrayHasKey('innerHTML', $element->props);
        $this->assertInstanceOf(Expression::class, $element->props['innerHTML']);
        $this->assertSame('htmlContent', $element->props['innerHTML']->expression);
        $this->assertContains('innerHTML', $element->dynamicProperties);
        $this->assertSame(PathFlags::PROPS, $element->pathFlags & PathFlags::PROPS);
        $this->assertEmpty($element->directives);

        $rendered = $element->render(new Context());
        $this->assertStringContainsString('{"innerHTML":this.htmlContent', $rendered);
    }

    public function testTemplateSupportsDefaultVSlotShorthand(): void
    {
        $template = '<MyComponent><template v-slot="{ user }"><span>{{ user.name }}</span></template></MyComponent>';

        $compiler = new Compiler();
        $fragment = $compiler->compile($template, new Context());

        $this->assertInstanceOf(FragmentElement::class, $fragment);
        $this->assertNotEmpty($fragment->children);
        $component = $fragment->children[0];
        $this->assertInstanceOf(ComponentElement::class, $component);
        $this->assertNotEmpty($component->children);
        $slotTemplate = $component->children[0];
        $this->assertInstanceOf(TemplateElement::class, $slotTemplate);
        $this->assertSame('default', $slotTemplate->name);
        $this->assertSame('{ user }', $slotTemplate->propsName);
    }

    public function testComponentSlotShorthandAssignsPropsName(): void
    {
        $template = '<Popover v-slot="{ open }"><div v-if="open"></div></Popover>';

        $compiler = new Compiler();
        $fragment = $compiler->compile($template, new Context());

        $this->assertInstanceOf(FragmentElement::class, $fragment);
        $this->assertNotEmpty($fragment->children);
        $component = $fragment->children[0];
        $this->assertInstanceOf(ComponentElement::class, $component);
        $this->assertSame('{ open }', $component->slotPropsName);

        $component->renderChildren(new Context());
        $this->assertArrayHasKey('default', $component->children);
        $defaultSlot = $component->children['default'];
        $this->assertInstanceOf(TemplateElement::class, $defaultSlot);
        $this->assertSame('{ open }', $defaultSlot->propsName);
    }
}
