<?php

namespace App\Tests\Template;

use Flow\Exception\FlowException;
use Flow\Template\TemplateScriptParser;
use PHPUnit\Framework\TestCase;

class TemplateScriptParserTest extends TestCase
{
    private TemplateScriptParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TemplateScriptParser();
    }

    public function testExtractScriptFromTemplate(): void
    {
        $template = <<<HTML
<div class="modal">
    <h1>{{ title }}</h1>
</div>

<script>
export default {
    methods: {
        getSizeClass() {
            return 'large';
        }
    }
}
</script>
HTML;

        $result = $this->parser->extractScript($template);

        $this->assertArrayHasKey('template', $result);
        $this->assertArrayHasKey('script', $result);
        $this->assertStringNotContainsString('<script>', $result['template']);
        $this->assertStringContainsString('export default', $result['script']);
    }

    public function testExtractScriptWithNoScript(): void
    {
        $template = '<div class="modal"><h1>{{ title }}</h1></div>';

        $result = $this->parser->extractScript($template);

        $this->assertEquals($template, $result['template']);
        $this->assertNull($result['script']);
    }

    public function testMultipleScriptTagsThrowsException(): void
    {
        $template = <<<HTML
<div>Content</div>
<script>export default { methods: {} }</script>
<script>export default { methods: {} }</script>
HTML;

        $this->expectException(FlowException::class);
        $this->expectExceptionMessage('Only one <script> tag is allowed per component template');

        $this->parser->extractScript($template);
    }

    public function testTransformScriptForClient(): void
    {
        $script = <<<JS
export default {
    methods: {
        getSizeClass() {
            const sizes = {
                'sm': 'sm:max-w-sm',
                'md': 'sm:max-w-md'
            };
            return sizes[this.size] || sizes.md;
        }
    }
}
JS;

        $transformed = $this->parser->transformScriptForClient($script);

        $this->assertStringContainsString('var export =', $transformed);
        $this->assertStringNotContainsString('export default', $transformed);
        $this->assertStringContainsString('return export;', $transformed);
    }

    public function testTransformEmptyScript(): void
    {
        $transformed = $this->parser->transformScriptForClient('');

        $this->assertEquals('', $transformed);
    }

    public function testTransformScriptAddsReturnStatement(): void
    {
        $script = 'export default { methods: { test() { return true; } } }';

        $transformed = $this->parser->transformScriptForClient($script);

        $this->assertStringEndsWith('return export;', trim($transformed));
    }
}

