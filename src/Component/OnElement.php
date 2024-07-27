<?php

namespace Flow\Component;

class OnElement extends Element
{

    const EventModifiers = [
        'stop',
        'prevent',
        'self',
        'capture',
        'once',
        'passive',
    ];

    protected $handlers = [];


    public function __construct(public string $on, Expression|null $fn = null, array $modifiers = [])
    {
        parent::__construct('On');
        if ($fn !== null) {
            $this->addHandler($fn, $modifiers);
        }
    }

    public function addHandler(Expression $fn, array $modifiers = []): self
    {
        $this->handlers[] = ['fn' => $fn, 'modifiers' => $modifiers];
        return $this;
    }

    function isValidJavaScriptName($name)
    {
        // List of ECMAScript 6 (ES6) keywords
        static $es6Keywords = [
            'abstract', 'arguments', 'await', 'boolean',
            'break', 'byte', 'case', 'catch',
            'char', 'class', 'const', 'continue',
            'debugger', 'default', 'delete', 'do',
            'double', 'else', 'enum', 'eval',
            'export', 'extends', 'false', 'final',
            'finally', 'float', 'for', 'function',
            'goto', 'if', 'implements', 'import',
            'in', 'instanceof', 'int', 'interface',
            'let', 'long', 'native', 'new',
            'null', 'package', 'private', 'protected',
            'public', 'return', 'short', 'static',
            'super', 'switch', 'synchronized', 'this',
            'throw', 'throws', 'transient', 'true',
            'try', 'typeof', 'var', 'void',
            'volatile', 'while', 'with', 'yield'
        ];

        // Check if the name is not empty
        if (empty($name)) {
            return false;
        }

        // Check if the name is not an ES6 keyword
        if (in_array(strtolower($name), $es6Keywords)) {
            return false;
        }

        // Check if the name starts with a letter, underscore, or dollar sign
        if (!preg_match('/^[a-zA-Z_$]/', $name)) {
            return false;
        }

        // Check if the remaining characters are letters, numbers, underscores, or dollar signs
        if (!preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $name)) {
            return false;
        }

        // The name is valid
        return true;
    }

    public function render(?Context $context = null): string
    {
        $handlers = [];
        $context = $context->addScope(['$args']);
        foreach ($this->handlers as $handler) {
            $isAsync = '';
            $context = $context->withIdentifierHandler(function ($identify) use (&$isAsync) {
                if ($identify === 'await') {
                    $isAsync = 'async';
                }
                return $identify;
            });
            $eventFunc = $handler['fn']->render($context);

            if ($handler['fn']->isFnHandler) {
                $jsHandler = sprintf('%s.bind(this)', $eventFunc);
            } else if ($this->isValidJavaScriptName($handler['fn']->expression)) {
                $jsHandler = sprintf('%s && %1$s.bind(this)', $eventFunc);
            } else {
                $jsHandler = sprintf('%s ($event,...$args) => { %s }', $isAsync, $eventFunc);
            }

            $eventModifiers = [];
            $keyModifiers = [];

            foreach ($handler['modifiers'] as $modifier) {
                if (in_array($modifier, self::EventModifiers, true)) {
                    $eventModifiers[] = $modifier;
                } else {
                    $keyModifiers[] = $modifier;
                }
            }

            if (!empty($eventModifiers)) {
                $jsHandler = sprintf('v.withModifiers(%s,%s)', $jsHandler, json_encode($eventModifiers));
            }

            if (!empty($keyModifiers)) {
                $jsHandler = sprintf('v.withKeys(%s,%s)', $jsHandler, json_encode($keyModifiers));
            }
            $handlers[] = $jsHandler;
        }


        return count($handlers) === 1 ? $handlers[0] : sprintf('[%s]', implode(',', $handlers));
    }

    public function getOn(): array|string
    {
        return $this->on;
    }

}