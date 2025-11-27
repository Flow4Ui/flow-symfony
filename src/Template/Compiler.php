<?php

namespace Flow\Template;

use Flow\Component\{ClassNormalizerExpression,
    ComponentElement,
    Context,
    Element,
    Expression,
    ForElement,
    FragmentElement,
    IfElement,
    OnElement,
    SlotElement,
    TemplateElement,
    TextElement
};
use Flow\Exception\FlowException;

class Compiler
{
    const V_ON = 'v-on:';
    const V_SLOT = 'v-slot:';

    const DEFAULT_VALUE = '="____DEF____"';

    const HTML_TAGS = [
        'html', 'head', 'title', 'meta', 'body', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'a', 'link', 'ul', 'li', 'ol', 'img', 'table', 'tr', 'td', 'th',
        'form', 'input', 'button', 'select', 'textarea', 'div', 'span', 'header', 'footer',
        'section', 'article', 'nav', 'aside', 'audio', 'video', 'hr', 'br', 'svg',
        'main', 'label', 'tbody', 'thead', 'tfoot', 'colgroup', 'col', 'caption', 'option',
        // SVG tags
        'circle', 'clipPath', 'defs', 'desc', 'ellipse', 'feBlend', 'feColorMatrix',
        'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting',
        'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA',
        'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feImage', 'feMerge',
        'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight', 'feSpecularLighting',
        'feSpotLight', 'feTile', 'feTurbulence', 'filter', 'foreignObject', 'g',
        'image', 'line', 'linearGradient', 'marker', 'mask', 'metadata', 'path',
        'pattern', 'polygon', 'polyline', 'radialGradient', 'rect', 'stop',
        'switch', 'symbol', 'text', 'textPath', 'tspan', 'use', 'view'
    ];

    const V_MODEL = 'v-model';

    protected Preprocessor|null $preprocessor = null;
    protected ?string $scriptContent = null;
    protected array $rawStyles = [];
    protected array $styles = [];
    protected ?string $styleScopeId = null;

    private const STYLE_SCOPE_ATTRIBUTE = 'data-flow-scope';

    public function __construct()
    {
    }

    /**
     * @throws FlowException
     */
    public function compile(string $template, ?Context $context)
    {
        // Extract script tag before preprocessing
        $scriptParser = new TemplateScriptParser();
        $extracted = $scriptParser->extractScript($template);
        $this->scriptContent = $extracted['script'];
        $this->rawStyles = $extracted['styles'] ?? [];
        $this->prepareStyles();
        $template = $extracted['template'];

        $dom = new \DOMDocument();

        $preprocessed = sprintf('<?xml encoding="utf-8" ?><Fragment>%s</Fragment>', $this->preprocess($template));
        if (!($dom->loadHTML($preprocessed, LIBXML_NOERROR))) {
            throw new FlowException(sprintf('Fail to parse template %c', $template));
        }

        $rootNodes = iterator_to_array($dom->documentElement->childNodes->item(0)->childNodes);
        $rootNodes = array_filter($rootNodes, fn($item) => $item instanceof \DOMElement);
        if (count($rootNodes) > 1) {
            throw new FlowException('Template should have only one root node');
        }

        return $this->compileElement($rootNodes[0], null, $context, true);
    }

    private function preprocess($template)
    {
        $this->preprocessor = new Preprocessor();
        return $this->preprocessor->preprocess($template);
    }

    /**
     * Get the extracted script content from the template
     *
     * @return string|null
     */
    public function getScriptContent(): ?string
    {
        return $this->scriptContent;
    }

    /**
     * @return array<int, array{content: string, scoped: bool, attributes: array<string, string>, scopeId: string|null, hash: string}>
     */
    public function getStyles(): array
    {
        return $this->styles;
    }

    /**
     * @throws FlowException
     */
    private function compileElement(\DOMElement|\DOMText $domElement, Element|null $lastElement = null, Context|null $context = null, $root = false): Element
    {
        if ($domElement instanceof \DOMText) {
            return new TextElement($domElement->nodeValue);
        } elseif ($domElement->tagName === '___code') {
            return new Expression(base64_decode($domElement->nodeValue));
        }

        $nodeName = $domElement->nodeName === 'fragment' ? 'fragment' : $this->getNameFromId($domElement->nodeName);
        $element = match ($nodeName) {
            'fragment' => new FragmentElement('fragment'),
            'template' => new TemplateElement(),
            'slot' => new SlotElement(),
            default => in_array($nodeName, self::HTML_TAGS, true) ? new Element($nodeName) : new ComponentElement(component: $nodeName)
        };
        $element->isRoot = $root;
        if ($root && $this->hasScopedStyles()) {
            if ($element instanceof ComponentElement || $element instanceof TemplateElement) {
                throw new FlowException('Scoped styles require the template root element to be a single HTML element.');
            }

            if (!($element instanceof FragmentElement)) {
                $element->props[self::STYLE_SCOPE_ATTRIBUTE] = $this->getStyleScopeId();
            }
        }
        $isTemplate = $element instanceof TemplateElement;

        $ifExpression = null;
        $forExpression = null;
        $else = false;

        foreach ($domElement->attributes as $prop => $value) {
            $prop = $this->getNameFromId($prop);

            $slotName = $this->resolveSlotDirective($prop);
            if ($slotName !== null) {
                if ($isTemplate) {
                    $this->assignProp($element, 'name', $slotName);
                    $element->name = $slotName;
                    $element->propsName = $value->value;
                    continue;
                }

                if ($element instanceof ComponentElement) {
                    if ($slotName !== 'default') {
                        throw new FlowException('Named slots are not supported when using v-slot on component elements.');
                    }

                    $element->slotPropsName = $value->value;
                    continue;
                }

                throw new FlowException('v-slot can only be used on <template> or component elements.');
            }

            if ($prop === 'name' && $isTemplate) {
                $this->assignProp($element, 'name', $value->value);
                $element->name = $value->value;
                continue;
            }

            if ($prop[0] === ':') {
                $prop = substr($prop, 1);
                $element->pathFlags |= PathFlags::PROPS;
                $element->dynamicProperties[] = $prop;
                $this->assignProp($element, $prop, $value->value === '____DEF____' ? new Expression("true") : new Expression($value->value));
            } else if (str_starts_with($prop, self::V_ON)) {
                $modifiers = explode('.', $prop);
                $eventName = array_shift($modifiers);
                $eventName = substr($eventName, strlen(self::V_ON));
                $prop = 'on' . ucfirst($eventName);

                $compilerModifiers = [];
                $clientModifiers = [];
                foreach ($modifiers as $modifier) {
                    if (
                        str_starts_with($modifier, 'invoke:')
                        || str_starts_with($modifier, 'throttle:')
                        || str_starts_with($modifier, 'debounce:')
                    ) {
                        $compilerModifiers[] = $modifier;
                    } else {
                        $clientModifiers[] = $modifier;
                    }
                }

                $fnExpression = new Expression($value->value);
                if (empty($element->props[$prop])) {
                    $element->props[$prop] = new OnElement($eventName, $fnExpression, $clientModifiers);
                    $element->dynamicProperties[] = $prop;
                } else {
                    $element->props[$prop]->addHandler($fnExpression, $clientModifiers);
                }

                foreach ($compilerModifiers as $modifier) {
                    $modifierModifier = explode(':', $modifier);
                    if (str_starts_with($modifier, 'invoke:')) {
                        $callback = $modifierModifier[1];
                        $arguments = array_slice($modifierModifier, 2);
                        $arguments = $this->compileInvokeModifierArguments($arguments);
                        assert(!empty($callback), 'make sure that action name in v-model invoke modifier is set');
                        $fnExpression->expression = sprintf(
                            '%s;await invoke(\'%s\',[%s]);',
                            $fnExpression->expression,
                            $callback,
                            implode(',', $arguments),
                        );
                    } else if (str_starts_with($modifier, 'debounce:')) {
                        $amount = $modifierModifier[1];
                        $arguments = array_slice($modifierModifier, 2);
                        $fnExpression->isFnHandler = true;
                        $fnExpression->expression = sprintf('$flow.debounce(($event)=>{%s},%d)', $fnExpression->expression, $amount);
                    } else if (str_starts_with($modifier, 'throttle:')) {
                        $amount = $modifierModifier[1];
                        $arguments = array_slice($modifierModifier, 2);
                        $fnExpression->isFnHandler = true;
                        $fnExpression->expression = sprintf('$flow.throttle(($event)=>{%s},%d)', $fnExpression->expression, $amount);
                    }
                }

            } else if ($prop === 'v-if') {
                $ifExpression = new Expression($value->value);
            } else if ($prop === 'v-elseif' || $prop === 'v-else-if') {
                $ifExpression = new Expression($value->value);
                $else = true;
            } else if ($prop === 'v-else') {
                $else = true;
            } else if ($prop === 'v-for') {
                [$for, $in] = explode(' in ', $value->value, 2);
                $forExpression = new ForElement(for: new Expression($for), in: new Expression($in), do: $element);
            } else if ($prop === 'v-html') {
                $element->pathFlags |= PathFlags::PROPS;
                $element->dynamicProperties[] = 'innerHTML';
                $this->assignProp($element, 'innerHTML', new Expression($value->value));
            } else if (str_starts_with($prop, 'v-')) {
                if (!$this->isModel($prop) && $this->isDirectiveProp($prop)) {
                    $element->directives[substr($prop, 2)] = new Expression($value->value);
                } else {
                    if ($this->isModel($prop)) {
                        $modifiers = explode('.', $prop);
                        $vModelProp = array_shift($modifiers);
                        $vModelValue = explode(':', $vModelProp, 2)[1] ?? 'modelValue';

                        $element->dynamicProperties[] = $vModelValue;
                        $this->assignProp($element, $vModelValue, new Expression($value->value));

                        $eventName = 'update:' . $vModelValue;
                        $prop = 'on' . ucfirst($eventName);
                        $vModelExpression = new Expression(sprintf('%s=$event;', $value->value));

                        $compilerModifiers = [];
                        $clientModifiers = [];
                        foreach ($modifiers as $modifier) {
                            if (str_starts_with($modifier, 'invoke:')) {
                                $compilerModifiers[] = $modifier;
                            } else {
                                $clientModifiers[] = $modifier;
                            }
                        }

                        if (empty($element->props[$prop])) {
                            $element->props[$prop] = new OnElement($eventName, $vModelExpression, $clientModifiers);
                            $element->dynamicProperties[] = $prop;
                        } else {
                            $element->props[$prop]->addHandler($vModelExpression, $clientModifiers);
                        }

                        foreach ($compilerModifiers as $modifier) {
                            if (str_starts_with($modifier, 'invoke:')) {
                                $modifierModifier = explode(':', $modifier);
                                $callback = $modifierModifier[1];
                                $arguments = array_slice($modifierModifier, 2);
                                $arguments = $this->compileInvokeModifierArguments($arguments);
                                assert(!empty($callback), 'make sure that action name in v-model invoke modifier is set');
                                $element->props[$prop]->addHandler(new Expression(sprintf('await invoke(\'%s\',[%s]);', $callback, implode(',', $arguments))), $clientModifiers);
                            }
                        }

                    } else {
                        $this->assignProp($element, $prop, new Expression($value->value));
                    }
                }
            } else {
                if ($value->value === '____DEF____') {
                    if (in_array($nodeName, self::HTML_TAGS, true)) {
                        $this->assignProp($element, $prop, '');
                    } else {
                        $this->assignProp($element, $prop, new Expression('true'));
                    }
                } else {
                    $this->assignProp($element, $prop, $value->value);
                }
            }

            if ($forExpression !== null) {
                if ($prop === 'key') {
                    $forExpression->key = $element->props[$prop];
                    $forExpression->pathFlags |= PathFlags::KEYED_FRAGMENT;
                } else {
                    $forExpression->pathFlags |= PathFlags::UNKEYED_FRAGMENT;
                }
            }
        }


        if ($element instanceof TemplateElement && empty($element->name)) {
            $newElement = new FragmentElement('fragment');
            $newElement->pathFlags = $element->pathFlags;
            $newElement->directives = $element->directives;
            $newElement->props = $element->props;
            $newElement->children = $element->children;
            $newElement->dynamicProperties = $element->dynamicProperties;
            $element = $newElement;
        }

        $lastChildElement = null;
        foreach ($domElement->childNodes as $childNode) {
            if (
                ($childNode instanceof \DOMText && !trim($childNode->nodeValue)) ||
                $childNode instanceof \DOMComment
            ) {
                continue;
            }
            $newElement = $this->compileElement($childNode, $lastChildElement, $context);
            if ($newElement instanceof Expression) {
                $element->pathFlags |= PathFlags::DYNAMIC_SLOTS;
                $newElement->isTextExpression = true;
            } elseif ($newElement instanceof SlotElement && (
                    $forExpression !== null ||
                    $ifExpression !== null
                )) {
                $element->pathFlags |= PathFlags::DYNAMIC_SLOTS;
            }
            if ($newElement !== $lastChildElement) {
                $element->children[] = $newElement;
                $lastChildElement = $newElement;
            }
        }

        if ($root && $this->hasScopedStyles() && $element instanceof FragmentElement) {
            $targetElement = null;

            foreach ($element->children as $child) {
                if ($child instanceof Element) {
                    if ($targetElement !== null) {
                        throw new FlowException('Scoped styles require the template root element to be a single HTML element.');
                    }

                    $targetElement = $child;
                }
            }

            if ($targetElement === null || $targetElement instanceof ComponentElement || $targetElement instanceof FragmentElement || $targetElement instanceof TemplateElement) {
                throw new FlowException('Scoped styles require the template root element to be a single HTML element.');
            }

            $targetElement->props[self::STYLE_SCOPE_ATTRIBUTE] = $this->getStyleScopeId();
        }

        if ($ifExpression !== null) {
            $element = new IfElement($ifExpression, $element);
        }

        if (!empty($forExpression)) {
            $forExpression->do = $element;
            $element = $forExpression;
        }

        if ($else) {
            if ($lastElement instanceof IfElement) {
                $currentElement = $lastElement;
                //todo check here if possibility of infinite loop
                while ($currentElement->else !== null) {
                    $currentElement = $currentElement->else;
                }
                $currentElement->else = $element;
                return $lastElement;
            } else {
                throw new FlowException('unexpected v-else');
            }
        }

        return $element;
    }

    private function prepareStyles(): void
    {
        $this->styles = [];

        if (empty($this->rawStyles)) {
            $this->styleScopeId = null;
            return;
        }

        $this->ensureStyleScopeId();

        foreach ($this->rawStyles as $style) {
            $attributes = $style['attributes'] ?? [];
            $scoped = array_key_exists('scoped', $attributes);

            if ($scoped) {
                unset($attributes['scoped']);
            }

            $content = $style['content'] ?? '';
            if ($scoped) {
                $content = $this->scopeCss($content, $this->getStyleScopeSelector());
            }

            $hash = substr(sha1($content), 0, 12);

            $this->styles[] = [
                'content' => $content,
                'scoped' => $scoped,
                'attributes' => $attributes,
                'scopeId' => $scoped ? $this->getStyleScopeId() : null,
                'hash' => $hash,
            ];
        }
    }

    private function hasScopedStyles(): bool
    {
        foreach ($this->styles as $style) {
            if (!empty($style['scoped'])) {
                return true;
            }
        }

        return false;
    }

    private function ensureStyleScopeId(): void
    {
        if ($this->styleScopeId !== null) {
            return;
        }

        $scopedStyles = array_filter($this->rawStyles, static function (array $style): bool {
            $attributes = $style['attributes'] ?? [];

            return array_key_exists('scoped', $attributes);
        });

        if (empty($scopedStyles)) {
            return;
        }

        $seed = implode('|', array_map(static function (array $style): string {
            return $style['content'] ?? '';
        }, $scopedStyles));

        $hash = substr(sha1($seed), 0, 8);
        $this->styleScopeId = 's' . $hash;
    }

    private function getStyleScopeId(): string
    {
        $this->ensureStyleScopeId();

        if ($this->styleScopeId === null) {
            throw new FlowException('Unable to determine scoped style identifier.');
        }

        return $this->styleScopeId;
    }

    private function getStyleScopeSelector(): string
    {
        return '[' . self::STYLE_SCOPE_ATTRIBUTE . '="' . $this->getStyleScopeId() . '"]';
    }

    private function scopeCss(string $css, string $scopeSelector): string
    {
        $pattern = '/(^|})\s*([^}{@][^{}]*)\s*{/m';

        $callback = function (array $matches) use ($scopeSelector) {
            $prefix = $matches[1];
            $selectorList = trim($matches[2]);

            if ($selectorList === '') {
                return $matches[0];
            }

            $selectors = array_map('trim', explode(',', $selectorList));
            $processedSelectors = [];

            foreach ($selectors as $selector) {
                if ($selector === '') {
                    continue;
                }

                if (preg_match('/^(from|to|[0-9.]+%)/i', $selector)) {
                    $processedSelectors[] = $selector;
                    continue;
                }

                if (str_starts_with($selector, $scopeSelector)) {
                    $processedSelectors[] = $selector;
                    continue;
                }

                $processedSelectors[] = $scopeSelector . ' ' . $selector;
            }

            if (empty($processedSelectors)) {
                return $matches[0];
            }

            return $prefix . ' ' . implode(', ', $processedSelectors) . ' {';
        };

        return preg_replace_callback($pattern, $callback, $css) ?? $css;
    }

    private function resolveSlotDirective(?string $prop): ?string
    {
        if ($prop === null) {
            return null;
        }

        if ($prop === 'v-slot') {
            return 'default';
        }

        if (str_starts_with($prop, self::V_SLOT)) {
            $slotName = substr($prop, strlen(self::V_SLOT));

            return $slotName === '' ? 'default' : $slotName;
        }

        return null;
    }

    /**
     * @param Element $element
     * @param string $prop
     * @param $value
     * @return void
     */
    private function assignProp(Element $element, string $prop, $value): void
    {
        if ($prop === 'class' && isset($element->props[$prop])) {
            if (!($element->props[$prop] instanceof ClassNormalizerExpression)) {
                $element->props[$prop] = new ClassNormalizerExpression($element->props[$prop]);
            }
            $element->props[$prop]->addExpression($value);
        } else {
            $element->props[$prop] = $value;
        }
    }

    public function getNameFromId($name): string|null
    {
        return $this->preprocessor->getNameFromId($name);
    }

    private function isDirectiveProp($prop)
    {
        return
            !$this->isModel($prop) &&
            $prop !== 'v-html' &&
            !($prop === 'v-bind' || str_starts_with($prop, 'v-bind:')) &&
            !($prop === 'v-show' || str_starts_with($prop, 'v-show:'));
    }

    /**
     * @param $prop
     * @return bool
     */
    public function isModel($prop): bool
    {
        return ($prop === self::V_MODEL || str_starts_with($prop, 'v-model:') || str_starts_with($prop, 'v-model.'));
    }

    protected function compileInvokeModifierArguments(array $arguments): array
    {
        foreach ($arguments as &$argument) {
            $argument = trim($argument);
            if ($argument[0] !== '\'' && $argument[0] !== '"' && str_contains($argument, '@')) $argument = str_replace('@', '.', $argument);
        }
        return $arguments;
    }
}
