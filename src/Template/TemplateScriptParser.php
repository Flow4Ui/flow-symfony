<?php

namespace Flow\Template;

use DOMDocument;
use Flow\Exception\FlowException;
use Peast\Peast;

/**
 * Parses <script> tags from component templates and extracts JavaScript methods
 */
class TemplateScriptParser
{
    private const SUPPORTED_LIFECYCLE_KEYS = [
        'beforeCreate',
        'created',
        'beforeMount',
        'mounted',
        'beforeUpdate',
        'updated',
        'beforeUnmount',
        'unmounted',
        'activated',
        'deactivated',
        'errorCaptured',
        'renderTracked',
        'renderTriggered',
        'beforeRouteEnter',
        'beforeRouteUpdate',
        'beforeRouteLeave',
    ];

    private const SUPPORTED_EXPORT_KEYS = [
        'methods',
        'computed',
        'watch',
        'data',
        'beforeCreate',
        'created',
        'beforeMount',
        'mounted',
        'beforeUpdate',
        'updated',
        'beforeUnmount',
        'unmounted',
        'activated',
        'deactivated',
        'errorCaptured',
        'renderTracked',
        'renderTriggered',
        'beforeRouteEnter',
        'beforeRouteUpdate',
        'beforeRouteLeave',
    ];

    /**
     * Extract script content from template
     *
     * @param string $template The full template string
     * @return array{template: string, script: string|null} Returns cleaned template and script content
     * @throws FlowException
     */
    public function extractScript(string $template): array
    {
        // Match <script> tag with content
        $pattern = '/<script[^>]*>(.*?)<\/script>/is';

        $matches = [];
        $count = preg_match_all($pattern, $template, $matches);

        if ($count > 1) {
            throw new FlowException('Only one <script> tag is allowed per component template');
        }

        if ($count === 1) {
            $this->assertScriptOnRootLevel($template);

            $scriptContent = $matches[1][0];
            // Remove the script tag from template
            $cleanedTemplate = preg_replace($pattern, '', $template);

            return [
                'template' => trim($cleanedTemplate),
                'script' => trim($scriptContent)
            ];
        }

        return [
            'template' => $template,
            'script' => null
        ];
    }

    /**
     * Transform script content to be compatible with eval on client-side
     * Converts: export default { ... }
     * To: var export = { ... }; return export;
     *
     * @param string $scriptContent JavaScript code from <script> tag
     * @return string Transformed JavaScript code
     */
    public function transformScriptForClient(string $scriptContent): string
    {
        if (trim($scriptContent) === '') {
            return '';
        }

        $this->assertValidClientScript($scriptContent);

        // Replace "export default" with "var _export ="
        $transformed = preg_replace('/export\s+default\s+/', 'var _export = ', $scriptContent);

        // Add return statement at the end
        $transformed = rtrim((string) $transformed);
        if (!str_ends_with($transformed, ';')) {
            $transformed .= ';';
        }
        $transformed .= "\nreturn _export||{};";

        return $transformed;
    }

    private function assertScriptOnRootLevel(string $template): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!DOCTYPE html><html><body>' . $template . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $scripts = $document->getElementsByTagName('script');

        if ($scripts->length === 0) {
            return;
        }

        $script = $scripts->item(0);
        if ($script !== null && $script->parentNode !== null && strtolower($script->parentNode->nodeName) !== 'body') {
            throw new FlowException('The <script> tag must be placed at the root level of the template');
        }
    }

    private function assertValidClientScript(string $scriptContent): void
    {
        try {
            $program = Peast::latest($scriptContent, [
                'sourceType' => 'module',
            ])->parse();
        } catch (\Throwable $exception) {
            throw new FlowException('Unable to parse client script: ' . $exception->getMessage(), 0, $exception);
        }

        $exportDeclaration = null;
        foreach ($program->getBody() as $node) {
            if ($node->getType() === 'ExportDefaultDeclaration') {
                if ($exportDeclaration !== null) {
                    throw new FlowException('Client script must contain exactly one default export');
                }

                $exportDeclaration = $node;
            }
        }

        if ($exportDeclaration === null) {
            throw new FlowException('Client script must export a default object');
        }

        $declaration = $exportDeclaration->getDeclaration();
        if ($declaration === null || $declaration->getType() !== 'ObjectExpression') {
            throw new FlowException('Client script default export must be an object literal');
        }

        foreach ($declaration->getProperties() as $property) {
            if (!method_exists($property, 'getKey') || !method_exists($property, 'getValue')) {
                continue;
            }

            if (method_exists($property, 'getType') && $property->getType() !== 'Property') {
                continue;
            }

            $keyNode = $property->getKey();
            $key = $this->extractPropertyKey($keyNode);

            if (!in_array($key, self::SUPPORTED_EXPORT_KEYS, true)) {
                throw new FlowException(sprintf('Unsupported key "%s" in client script export', $key));
            }

            $valueNode = $property->getValue();

            if (in_array($key, ['methods', 'computed', 'watch'], true) && ($valueNode === null || $valueNode->getType() !== 'ObjectExpression')) {
                throw new FlowException(sprintf('Client script "%s" section must be an object literal', $key));
            }

            if ($key === 'data') {
                $this->assertValidDataSection($valueNode);
            }

            if ($this->isLifecycleKey($key)) {
                $this->assertLifecycleHookValue($key, $valueNode);
            }
        }
    }

    private function assertValidDataSection($valueNode): void
    {
        if ($valueNode === null || !method_exists($valueNode, 'getType')) {
            throw new FlowException('Client script "data" section must be a function');
        }

        $type = $valueNode->getType();
        $isFunction = in_array($type, ['FunctionExpression', 'ArrowFunctionExpression'], true);

        if (!$isFunction) {
            throw new FlowException('Client script "data" section must be a function');
        }

        if (!$this->functionReturnsObjectLiteral($valueNode)) {
            throw new FlowException('Client script "data" function must return an object literal');
        }
    }

    private function functionReturnsObjectLiteral($functionNode): bool
    {
        if (!method_exists($functionNode, 'getType')) {
            return false;
        }

        $type = $functionNode->getType();

        if ($type === 'ArrowFunctionExpression') {
            $body = $functionNode->getBody();

            if ($body === null || !method_exists($body, 'getType')) {
                return false;
            }

            if ($body->getType() !== 'BlockStatement') {
                return $body->getType() === 'ObjectExpression';
            }

            if (!method_exists($body, 'getBody')) {
                return false;
            }

            foreach ($body->getBody() as $statement) {
                if (!method_exists($statement, 'getType') || $statement->getType() !== 'ReturnStatement') {
                    continue;
                }

                $argument = $statement->getArgument();

                return $argument !== null && method_exists($argument, 'getType') && $argument->getType() === 'ObjectExpression';
            }

            return false;
        }

        if ($type === 'FunctionExpression') {
            $body = $functionNode->getBody();

            if ($body === null || !method_exists($body, 'getType') || $body->getType() !== 'BlockStatement' || !method_exists($body, 'getBody')) {
                return false;
            }

            foreach ($body->getBody() as $statement) {
                if (!method_exists($statement, 'getType') || $statement->getType() !== 'ReturnStatement') {
                    continue;
                }

                $argument = $statement->getArgument();

                return $argument !== null && method_exists($argument, 'getType') && $argument->getType() === 'ObjectExpression';
            }
        }

        return false;
    }

    private function extractPropertyKey(object $keyNode): string
    {
        $type = method_exists($keyNode, 'getType') ? $keyNode->getType() : null;

        if ($type === 'Identifier' && method_exists($keyNode, 'getName')) {
            return (string) $keyNode->getName();
        }

        if ($type === 'Literal' && method_exists($keyNode, 'getValue')) {
            $value = $keyNode->getValue();

            return is_string($value) ? $value : (string) $value;
        }

        throw new FlowException('Client script export uses an unsupported property name');
    }

    private function isLifecycleKey(string $key): bool
    {
        return in_array($key, self::SUPPORTED_LIFECYCLE_KEYS, true);
    }

    private function assertLifecycleHookValue(string $key, ?object $valueNode): void
    {
        if ($valueNode === null) {
            throw new FlowException(sprintf('Lifecycle hook "%s" must be a function', $key));
        }

        $type = method_exists($valueNode, 'getType') ? $valueNode->getType() : null;

        if (!in_array($type, ['FunctionExpression', 'ArrowFunctionExpression'], true)) {
            throw new FlowException(sprintf('Lifecycle hook "%s" must be a function', $key));
        }
    }
}

