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
        $this->assertStringContainsString('data-flow-style-scope="' . $style['scopeId'] . '"', $style['html']);
        $this->assertStringContainsString('<style', $style['html']);
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

    public function testCompilerRendersHeadLinkForExternalStyles(): void
    {
        $template = <<<HTML
<div></div>
<style href="/assets/theme.css"></style>
HTML;

        $compiler = new Compiler();
        $compiler->compile($template, new Context());

        $styles = $compiler->getStyles();
        $this->assertCount(1, $styles);
        $style = $styles[0];

        $this->assertSame('/assets/theme.css', $style['attributes']['href']);
        $this->assertStringStartsWith('<link', $style['html']);
        $this->assertStringContainsString('href="/assets/theme.css"', $style['html']);
        $this->assertStringContainsString('rel="stylesheet"', $style['html']);
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

    public function testInputModelTextDirectiveIsGeneratedForNumberInput(): void
    {
        $element = $this->compileSingleElement('<input v-model="x" type="number">');

        $this->assertArrayHasKey('modelText', $element->directives);
        $this->assertArrayNotHasKey('modelValue', $element->props);
        $this->assertArrayHasKey('onUpdate:modelValue', $element->props);
    }

    public function testInputModelRadioDirectiveIsGenerated(): void
    {
        $element = $this->compileSingleElement('<input v-model="x" type="radio" value="A">');

        $this->assertArrayHasKey('modelRadio', $element->directives);
        $this->assertArrayNotHasKey('modelValue', $element->props);
        $this->assertArrayHasKey('onUpdate:modelValue', $element->props);
        $this->assertSame('A', $element->props['value']);
    }

    public function testInputModelCheckboxDirectiveIsGenerated(): void
    {
        $element = $this->compileSingleElement('<input v-model="x" type="checkbox" value="1">');

        $this->assertArrayHasKey('modelCheckbox', $element->directives);
        $this->assertArrayNotHasKey('modelValue', $element->props);
        $this->assertArrayHasKey('onUpdate:modelValue', $element->props);
        $this->assertSame('1', $element->props['value']);
    }

    public function testInputModelDynamicDirectiveIsGeneratedForDynamicType(): void
    {
        $element = $this->compileSingleElement('<input v-model="x" :type="kind">');

        $this->assertArrayHasKey('modelDynamic', $element->directives);
        $this->assertArrayNotHasKey('modelValue', $element->props);
        $this->assertArrayHasKey('onUpdate:modelValue', $element->props);
    }

    public function testTextareaModelTextDirectiveIsGenerated(): void
    {
        $element = $this->compileSingleElement('<textarea v-model="x"></textarea>');

        $this->assertArrayHasKey('modelText', $element->directives);
        $this->assertArrayNotHasKey('modelValue', $element->props);
        $this->assertArrayHasKey('onUpdate:modelValue', $element->props);
    }

    public function testSelectModelSelectDirectiveIsGenerated(): void
    {
        $element = $this->compileSingleElement('<select v-model="x"><option value="1">One</option></select>');

        $this->assertArrayHasKey('modelSelect', $element->directives);
        $this->assertArrayNotHasKey('modelValue', $element->props);
        $this->assertArrayHasKey('onUpdate:modelValue', $element->props);
    }

    public function testComponentModelRemainsModelValueBased(): void
    {
        $element = $this->compileSingleElement('<MyInput v-model="x"></MyInput>');

        $this->assertInstanceOf(ComponentElement::class, $element);
        $this->assertArrayHasKey('modelValue', $element->props);
        $this->assertArrayHasKey('onUpdate:modelValue', $element->props);
        $this->assertArrayNotHasKey('modelText', $element->directives);
        $this->assertArrayNotHasKey('modelSelect', $element->directives);
        $this->assertArrayNotHasKey('modelCheckbox', $element->directives);
        $this->assertArrayNotHasKey('modelRadio', $element->directives);
        $this->assertArrayNotHasKey('modelDynamic', $element->directives);
    }

    public function testVIfWithTernaryConditionIsParenthesizedInRenderOutput(): void
    {
        $template = '<button v-if="editingId !== null ? canEdit : canCreate" type="submit">Save</button>';
        $compiler = new Compiler();
        $fragment = $compiler->compile($template, new Context());

        $rendered = $fragment->render(new Context());

        $this->assertStringContainsString(
            '((this.editingId !== null ? this.canEdit : this.canCreate)?(v.openBlock(),v.createElementVNode("button"',
            str_replace(["\n", " "], '', $rendered)
        );
    }

    private function compileSingleElement(string $template): FlowElement
    {
        $compiler = new Compiler();
        $fragment = $compiler->compile($template, new Context());

        $this->assertInstanceOf(FragmentElement::class, $fragment);
        $this->assertNotEmpty($fragment->children);
        $this->assertInstanceOf(FlowElement::class, $fragment->children[0]);

        return $fragment->children[0];
    }
}
