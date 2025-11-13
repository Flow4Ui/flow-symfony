<?php

namespace Flow\Template;

use Flow\Exception\FlowException;

/**
 * Parses <script> tags from component templates and extracts JavaScript methods
 */
class TemplateScriptParser
{
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
        if (empty($scriptContent)) {
            return '';
        }

        // Replace "export default" with "var export ="
        $transformed = preg_replace('/export\s+default\s+/', 'var _export = ', $scriptContent);

        // Add return statement at the end
        $transformed = rtrim($transformed);
        if (!str_ends_with($transformed, ';')) {
            $transformed .= ';';
        }
        $transformed .= "\nreturn _export||{};";

        return $transformed;
    }
}

