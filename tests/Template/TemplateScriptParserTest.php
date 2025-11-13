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

        $this->assertStringContainsString('var _export =', $transformed);
        $this->assertStringNotContainsString('export default', $transformed);
        $this->assertStringContainsString('return _export', $transformed);
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

        $this->assertStringEndsWith('return _export||{};', trim($transformed));
    }

    public function testTransformScriptAllowsComputedWatchAndLifecycle(): void
    {
        $script = <<<JS
export default {
    methods: {
        reset() {
            this.state.counter.reset();
        }
    },
    computed: {
        doubled() {
            return this.state.counter.count * 2;
        }
    },
    watch: {
        'state.counter.count'(value) {
            if (value > 10) {
                this.reset();
            }
        }
    },
    created() {
        this.reset();
    }
};
JS;

        $transformed = $this->parser->transformScriptForClient($script);

        $this->assertStringContainsString('var _export =', $transformed);
        $this->assertStringContainsString('return _export', $transformed);
    }

    public function testScriptMustExportDefaultObject(): void
    {
        $this->expectException(FlowException::class);
        $this->expectExceptionMessage('Client script must export a default object');

        $script = 'const helpers = { mounted() {} };';
        $this->parser->transformScriptForClient($script);
    }

    public function testScriptDefaultExportMustBeObject(): void
    {
        $this->expectException(FlowException::class);
        $this->expectExceptionMessage('Client script default export must be an object literal');

        $script = 'export default function() { return {}; }';
        $this->parser->transformScriptForClient($script);
    }

    public function testScriptWithUnsupportedKeyThrowsException(): void
    {
        $this->expectException(FlowException::class);
        $this->expectExceptionMessage('Unsupported key "data" in client script export');

        $script = 'export default { data() { return {}; } };';
        $this->parser->transformScriptForClient($script);
    }

    public function testScriptMustBeOnRootLevel(): void
    {
        $template = <<<HTML
<template>
    <div>
        <script>export default { methods: {} };</script>
    </div>
</template>
HTML;

        $this->expectException(FlowException::class);
        $this->expectExceptionMessage('The <script> tag must be placed at the root level of the template');

        $this->parser->extractScript($template);
    }
}

