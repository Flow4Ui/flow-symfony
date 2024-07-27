<?php

namespace Flow\Template;

use Flow\Component\ClassNormalizerExpression;
use Flow\Component\ComponentElement;
use Flow\Component\Context;
use Flow\Component\Element;
use Flow\Component\Expression;
use Flow\Component\ForElement;
use Flow\Component\FragmentElement;
use Flow\Component\IfElement;
use Flow\Component\OnElement;
use Flow\Component\SlotElement;
use Flow\Component\TemplateElement;
use Flow\Component\TextElement;
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
        'section', 'article', 'nav', 'aside', 'audio', 'video', 'hr', 'br'
    ];
    const V_MODEL = 'v-model';

    protected Preprocessor|null $preprocessor = null;

    public function __construct()
    {
    }

    /**
     * @throws FlowException
     */
    public function compile(string $template, ?Context $context)
    {
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
        $isTemplate = $element instanceof TemplateElement;

        $ifExpression = null;
        $forExpression = null;
        $else = false;

        foreach ($domElement->attributes as $prop => $value) {
            $prop = $this->getNameFromId($prop);

            if ($isTemplate && str_starts_with($prop, self::V_SLOT)) {
                $element->name = substr($prop, strlen(self::V_SLOT));
                $element->propsName = $value->value;
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
            } else if (str_starts_with($prop, 'v-')) {
                if ($this->isDirectiveProp($prop)) {
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

        if ($ifExpression !== null) {
            $element = new IfElement($ifExpression, $element);
        }

        if (!empty($forExpression)) {
            $forExpression->do = $element;
            $element = $forExpression;
        }

        if ($else) {
            if ($lastElement instanceof IfElement) {
                $lastElement->else = $element;
                return $lastElement;
            } else {
                throw new FlowException('unexpected v-else');
            }
        }

        return $element;
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
            !($prop === 'v-bind' || str_starts_with($prop, 'v-bind:')) &&
            !($prop === 'v-show' || str_starts_with($prop, 'v-show:'));
    }

    /**
     * @param $prop
     * @return bool
     */
    public function isModel($prop): bool
    {
        return ($prop === self::V_MODEL || str_starts_with($prop, 'v-model:'));
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
