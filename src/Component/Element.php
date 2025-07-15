<?php

namespace Flow\Component;

use Flow\Contract\ComponentBuilderInterface;

class Element
{

    public int $pathFlags = 0;
    public array $dynamicProperties = [];

    public function __construct(
        readonly public string|ComponentBuilderInterface $tag,
        public array                                     $props = [],
        public array|Element|string                      $children = [],
        public array                                     $directives = [],
        public bool                                      $isRoot = false,
    )
    {
    }


    public function render(Context|null $context = null): string
    {
        $isRoot = $context === null;
        if ($isRoot) {
            $context = new Context();
        }
        $renderElement = $this->renderCall($this->renderProps($context), $this->renderChildren($context), $context);
        if (!empty($this->directives)) {
            $renderElement = $this->renderDirectives($renderElement, $context);
        }
        //todo: think if this is the best solution to identify this the root element
        return $isRoot ? sprintf('return %s;', $renderElement) : $renderElement;

    }

//    protected function renderCall($props, $children, Context|null $context): string
//    {
//
//
//        $tag = json_encode($this->tag);
//        return sprintf('h(%s,%s,[%s])', $tag, $props, implode(',', $children));
//    }

    protected function renderCall($props, $children, Context|null $context): string
    {
        $vNode = $this->renderVNode($props, $children, $context);
        // component render
        if ($context->newBlock) {
            $context->newBlock = false;
            $vNode = sprintf(
                '(v.openBlock(),%s)',
                $vNode
            );
        }

        return $vNode;
    }

    protected function renderVNode($props, $children, Context|null $context): string
    {
        return sprintf(
            'v.createElementVNode("%s",%s,%s)',
            $this->tag,
            $props,
            sprintf('[%s]', implode(',', $children)),
        );
    }

    /**
     * @param Context|null $context
     * @return array
     */
    public function renderProps(Context|null $context = null): string
    {
        $bindings = [];
        $props = '{';


        $processedProps = $this->props;


        $class = [];

        // preprocess v-model
        foreach ($processedProps as $prop => $value) {
//            if ($prop === 'v-model') {
//                $prop = 'update:modelValue';
//                $model = 'modelValue';
//            } elseif (str_starts_with($prop, 'v-model:')) {
//                $model = explode(':', $prop, 2)[1];
//                $prop = 'update:' . $model;
//            } else {
//                $model = null;
//            }
//
//            if (!empty($model)) {
//                $props .= sprintf('%s:%s,', $model, $context->parseExpression($value));
//
//                $newProp = 'on' . ucfirst($prop);
//                $processedProps[$newProp] ??= new OnElement($prop);
//                $processedProps[$newProp]->addHandler(new Expression(sprintf('%s=$event', $value)));
//            }

            if ($prop === 'class' || $prop === ':class') {
                $class[] = $value;
                unset($processedProps[$prop]);
            }
        }


        foreach ($processedProps as $prop => $value) {
            if ($prop === 'v-bind') {
                $bindings[] = $this->renderValue($value, $context);
                continue;
            } elseif (str_starts_with($prop, 'v-bind:')) {
               // $bindings[] = $this->renderValue($value, $context);
                continue;
            } else if (str_contains($prop, 'v-')) {
                $this->directives[substr($prop, 2)] = $value;
                continue;
            }


            $renderedProp = $this->renderProp($prop, $value, $context);
            if (!empty($renderedProp)) {
                [$prop, $value] = $renderedProp;
                $props .= sprintf('%s:%s,', $prop, $value);
            }
        }
        if (!empty($class)) {
            $classValue = null;
            if (count($class) && is_string($class[0])) {
                $classValue = $this->renderValue($class[0], $context);
            } else if (count($class) === 1) {
                $classValue = sprintf('v.normalizeClass(%s)', $this->renderValue($class[0], $context));
            } else if (count($class) > 1) {
                $classValue = sprintf(
                    'v.normalizeClass([%s])',
                    implode(
                        ',',
                        array_map(fn($classItem) => $this->renderValue($classItem, $context), $class),
                    ),
                );
            }
            if ($classValue !== null) {
                $props .= sprintf('class:%s,', $classValue);
            }
        }
        $props .= '}';
        return empty($bindings) ? $props : sprintf('v.mergeProps(%s,%s)', $props, implode(',', $bindings));
    }

    /**
     * @param mixed $value
     * @param Context|null $context
     * @return false|string
     */
    public function renderValue(mixed $value, ?Context $context): string|false
    {
        if ($value instanceof Element) {
            $value = $value->render($context);
        } else {
            $value = json_encode($value);
        }
        return $value;
    }

    protected function renderProp(string|int $prop, mixed $value, Context|null $context): array
    {
        if ($value instanceof OnElement) {
            $on = $value->getOn();
            $on[0] = strtoupper($on[0]);
            $on = $this->getAttributeName($on);
            $prop = json_encode('on' . $on);
            $value = $value->render($context);
        } else {
            $prop = json_encode($this->getAttributeName($prop));
            $value = $this->renderValue($value, $context);
        }


        return [$prop, $value];
    }

    public function getAttributeName(string $inputString): string
    {
        return $inputString;
    }

    /**
     * @param Context|null $context
     * @return array
     */
    public function renderChildren(Context|null $context = null): array
    {
        $children = [];
        if ($this->children instanceof Element) {
            $children[] = $this->children->render($context);
        } else if (is_string($this->children)) {
            $children[] = json_encode($this->children);
        } else {
            foreach ($this->children as $index => $child) {
                if ($child instanceof Element) {
                    $children[$index] = $child->render($context);
                } else {
                    $children[$index] = json_encode($child);
                }
            }
        }
        return $children;
    }

    protected function renderDirectives(string $renderElement, Context $context): string
    {

        $directives = [];
        foreach ($this->directives as $directive => $value) {

            $directiveParts = explode(':', $directive, 2);
            $directiveName = $directiveParts[0];

            $directiveModifiers = count($directiveParts) > 1 ? explode('.', $directiveParts[1]) : explode('.', $directiveName);

            if (count($directiveParts) === 1) {
                $modifier = 'void 0';
                $directiveName = array_shift($directiveModifiers);
            } else {
                $modifier = array_shift($directiveModifiers);
            }

            $directiveModifiersParsed = [];
            foreach ($directiveModifiers as $directiveModifier) {
                $directiveModifiersParsed[$directiveModifier] = true;
            }


            $directives[] = sprintf('[%s,%s,%s,%s]', $context->resolveDirective($directiveName), $this->renderValue($value, $context), $modifier, json_encode($directiveModifiersParsed));
        }

        return sprintf('v.withDirectives(%s,[%s])', $renderElement, implode(',', $directives));
    }

    /**
     * @param int|string $prop
     * @param mixed $value
     * @param Context|null $context
     * @return array
     */
    protected function renderEventProp(int|string $prop, mixed $value, Context|null $context): array
    {
        $prop = explode('.', substr($prop, 1));
        $value = $this->renderValue($value, $context);

        if (count($prop) > 1) {
            $value = sprintf('wm(%s,%s)', $value, json_encode(array_slice($prop, 1)));
        }

        return [$prop[0], $value];
    }
}