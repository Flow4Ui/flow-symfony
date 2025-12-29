<?php

namespace Flow\Service;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SsrRenderer
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $nodeBinary = 'node',
        private readonly bool   $enabled = false,
    )
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array|string $flowOptions Either a Flow options array or the raw JavaScript literal produced by Manager::compileJsFlowOptions
     * @throws RuntimeException
     */
    public function render(array|string $flowOptions, ?string $rootComponent = null): string
    {
        if (!$this->enabled) {
            return '';
        }

        $flowOptionsLiteral = is_array($flowOptions)
            ? json_encode($flowOptions, JSON_UNESCAPED_SLASHES)
            : $flowOptions;

        $script = <<<'NODE'
import path from 'path';
import {pathToFileURL} from 'url';
import {createSSRApp, h} from 'vue';
import {renderToString} from '@vue/server-renderer';

async function readPayload() {
    const chunks = [];
    for await (const chunk of process.stdin) {
        chunks.push(chunk);
    }

    return Buffer.concat(chunks).toString('utf-8');
}

globalThis.window = globalThis.window || {};
globalThis.window.FlowOptions = globalThis.window.FlowOptions || {};
if (typeof globalThis.document === 'undefined') {
    globalThis.document = undefined;
}

const payloadRaw = await readPayload();
let payload = {};

if (payloadRaw) {
    try {
        payload = JSON.parse(payloadRaw);
    } catch (error) {
        throw new Error(`Unable to parse SSR payload: ${error.message}`);
    }
}

const {flowOptionsLiteral, rootComponent} = payload;

let flowOptions;
try {
    flowOptions = flowOptionsLiteral
        ? Function('return (' + flowOptionsLiteral + ');')()
        : {};
} catch (error) {
    throw new Error(`Unable to parse Flow options literal: ${error.message}`);
}

const rootComponentName = rootComponent
    || flowOptions.mainComponent
    || (flowOptions.definitions?.components ? Object.keys(flowOptions.definitions.components)[0] : null);

if (!rootComponentName) {
    throw new Error('No component available for SSR rendering');
}

const flowModulePath = pathToFileURL(path.join(process.cwd(), 'assets/flow/index.js')).href;
const {createFlow} = await import(flowModulePath);
let resolvedRoot = rootComponentName;

const app = createSSRApp({
    render() {
        return h(resolvedRoot);
    },
});

app.use(createFlow({
    ...flowOptions,
    autoloadComponents: null,
    whenReady: null,
}));

resolvedRoot = app._context?.components?.[rootComponentName] || rootComponentName;

const html = await renderToString(app);
console.log(html);
NODE;

        $process = new Process([
            $this->nodeBinary,
            '--input-type=module',
            '--experimental-specifier-resolution=node',
            '-e',
            $script,
        ], $this->projectDir);
        $payload = json_encode([
            'flowOptionsLiteral' => $flowOptionsLiteral,
            'rootComponent' => $rootComponent,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode SSR payload');
        }

        $process->setInput($payload);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException(
                sprintf('SSR rendering failed: %s', $exception->getProcess()->getErrorOutput()),
                previous: $exception
            );
        }

        return trim($process->getOutput());
    }
}
