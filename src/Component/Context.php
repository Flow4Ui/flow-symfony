<?php

namespace Flow\Component;

use Flow\Attributes\Component;
use Flow\Service\Registry;

class Context
{
    public Element|null $nextElementKey = null;

    public \ArrayObject $componentsElements;
    /**
     * @var true
     */
    public bool $newBlock = false;

    /**
     * @var array<string>[]
     */
    protected array $scopes = [['$event',
        'debugger',
        'new',
        'true',
        'const',
        'let',
        'var',
        'false',
        'await',
        'typeof',
        'instanceof',
        'if',
        'else',
        'while',
        'break',
        'for',
        'this',
        'arguments',
        'return',
        'in',
        'of',
        'console',
        'Date',
        'Regex'
    ]];
    protected array $globals = ['$window', '$document'];
    /**
     * @var callable|null
     */
    protected $identifierHandler = null;

    public function __construct(
        public readonly Registry|null $registry = null,
        protected Component|null      $component = null,
    )
    {
        $this->componentsElements = new \ArrayObject();
    }

    /**
     * Parse Expressions
     * @param $expression
     * @return string
     */
    public function parseExpression(string $expression): string
    {
        // if (str_contains($expression, 'item.')) xdebug_break();
        $pattern = '/(?<!["\'])(\.?[a-zA-Z_$]\w*(?:\.[a-zA-Z_$]\w*)*)(?!["\'])/';

        $callback = fn($matches) => $this->getVarScope($matches[0]);

        // Split expression into parts with and without strings
        $parts = preg_split('/(["\'].+?["\'])/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
        $replacements = [];

        // Perform identifier replacement on non-string parts
        for ($i = 0; $i < count($parts); $i += 2) {
            $nonStringPart = $parts[$i];
            $replacements[] = preg_replace_callback($pattern, $callback, $nonStringPart);
        }

        // Join all the parts back together
        $result = '';
        for ($i = 0; $i < count($replacements); $i++) {
            $result .= $replacements[$i];
            if (isset($parts[$i * 2 + 1])) {
                $result .= $parts[$i * 2 + 1];
            }
        }

        return $result;
    }

    protected function getVarScope(string $identify): string
    {
        if ($identify[0] === '.') {
            return $identify;
        }

        $rootIdentify = explode('.', $identify)[0];

        if ($this->identifierHandler !== null) {
            $rootIdentify = ($this->identifierHandler)($rootIdentify);
        }

        if ($rootIdentify === '$debug') {
            return '(()=>{debugger})()';
        }

        if (in_array($rootIdentify, $this->globals, true)) {
            return substr($identify, 1);
        }

        if (!empty($this->component->props) && in_array($rootIdentify, $this->component->props, true)) {
            return 'this.$props.' . $identify;
        }

        foreach ($this->scopes as $scopes) {
            if (in_array($rootIdentify, $scopes)) {
                return $identify;
            }
        }

        return 'this.' . $identify;
    }

    /**
     * @throws \ReflectionException
     */
    public function resolveComponent($name): string
    {
        $component = $this->registry?->getComponentDefinition($name);
        $componentName = !empty($component) ? $component->name : $name;

        $instance_id = $this->componentsElements[$componentName] ?? null;
        if ($instance_id === null) {
            $instance_id = 'c_' . $this->componentsElements->count();
            $this->componentsElements->offsetSet($componentName, $instance_id);
        }
        return $instance_id;
    }

    public function withForceNewBlock(): self
    {
        $this->newBlock = true;
        return $this;
    }

    public function withNextKey(?Element $key): Context
    {
        $context = clone $this;
        $context->nextElementKey = $key;
        return $context;
    }

    /**
     * @param array<string> $names
     * @return self
     */
    public function addScope(array $names): Context
    {
        $newContext = clone $this;
        $newContext->scopes[] = $names;
        return $newContext;
    }

    public function withIdentifierHandler(callable $identifierHandler): Context
    {
        $context = clone $this;
        $context->identifierHandler = $identifierHandler;
        return $context;

    }

    /**
     * @throws \ReflectionException
     */
    public function getComponent(string $class)
    {
        return $this->registry->getComponentDefinition($class);
    }

}