<?php

namespace Flow\Component;

use Flow\Attributes\Component;
use Flow\Service\Registry;
use Peast\Peast;
use Peast\Syntax\Node\AssignmentPattern;
use Peast\Syntax\Node\ArrayPattern;
use Peast\Syntax\Node\ArrowFunctionExpression;
use Peast\Syntax\Node\CatchClause;
use Peast\Syntax\Node\ExportSpecifier;
use Peast\Syntax\Node\FunctionDeclaration;
use Peast\Syntax\Node\FunctionExpression;
use Peast\Syntax\Node\Identifier;
use Peast\Syntax\Node\ImportDefaultSpecifier;
use Peast\Syntax\Node\ImportNamespaceSpecifier;
use Peast\Syntax\Node\ImportSpecifier;
use Peast\Syntax\Node\MemberExpression;
use Peast\Syntax\Node\MetaProperty;
use Peast\Syntax\Node\MethodDefinition;
use Peast\Syntax\Node\Node as SyntaxNode;
use Peast\Syntax\Node\ObjectPattern;
use Peast\Syntax\Node\ParenthesizedExpression;
use Peast\Syntax\Node\Property;
use Peast\Syntax\Node\PropertyDefinition;
use Peast\Syntax\Node\RestElement;
use Peast\Syntax\Node\VariableDeclarator;
use Peast\Syntax\Utils;
use Throwable;

class Context
{
    public Element|null $nextElementKey = null;
    public $keyIndex = 0;

    public \ArrayObject $componentsElements;
    public \ArrayObject $directives;
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
        $this->directives = new \ArrayObject();
    }

    /**
     * Parse Expressions
     * @param $expression
     * @return string
     */
    public function parseExpression(string $expression): string
    {
        if (trim($expression) === '') {
            return $expression;
        }

        try {
            return $this->parseExpressionWithPeast($expression);
        } catch (Throwable) {
            return $this->legacyParseExpression($expression);
        }
    }

    /**
     * @return array{0: SyntaxNode|null, 1: int}
     */
    private function createExpressionAst(string $expression): array
    {
        $wrapped = sprintf('(%s)', $expression);
        $program = Peast::latest($wrapped, [
            'sourceType' => Peast::SOURCE_TYPE_MODULE,
        ])->parse();

        $body = $program->getBody();
        if (empty($body)) {
            return [null, 0];
        }

        $statement = $body[0];
        if (!method_exists($statement, 'getExpression')) {
            return [null, 0];
        }

        $node = $statement->getExpression();
        if ($node instanceof ParenthesizedExpression) {
            $node = $node->getExpression();
        }

        return [$node, 1];
    }

    private function parseExpressionWithPeast(string $expression): string
    {
        [$ast, $offset] = $this->createExpressionAst($expression);

        if ($ast === null) {
            return $expression;
        }

        $this->expandShorthandProperties($ast);

        $replacements = [];
        $this->collectReplacements($ast, null, [], $replacements, $offset, $expression);

        if (empty($replacements)) {
            return $expression;
        }

        usort($replacements, static fn(array $a, array $b) => $b['start'] <=> $a['start']);

        return $this->applyReplacements($expression, $replacements);
    }

    /**
     * @param array<int, array{start: int, end: int, replacement: string}> $replacements
     * @param array<int, array<int, string>> $scopeStack
     */
    private function collectReplacements(
        SyntaxNode $node,
        ?SyntaxNode $parent,
        array $scopeStack,
        array &$replacements,
        int $offset,
        string $originalExpression
    ): void {
        $scopeStack = $this->updateScopeStack($node, $scopeStack);

        if ($node instanceof Identifier && $this->shouldReplaceIdentifier($node, $parent, $scopeStack)) {
            $replacement = $this->getVarScope($node->getName());
            if ($replacement !== $node->getName()) {
                $location = $node->getLocation();
                $start = $location->getStart()->getIndex() - $offset;
                $end = $location->getEnd()->getIndex() - $offset;

                if ($start >= 0 && $end >= $start && $end <= strlen($originalExpression)) {
                    $replacements[] = [
                        'start' => $start,
                        'end' => $end,
                        'replacement' => $replacement,
                    ];
                }
            }
        }

        foreach (Utils::getNodeProperties($node, true) as $prop) {
            $child = $node->{$prop['getter']}();
            if ($child === null) {
                continue;
            }

            if (is_array($child)) {
                foreach ($child as $childNode) {
                    if ($childNode instanceof SyntaxNode) {
                        $this->collectReplacements($childNode, $node, $scopeStack, $replacements, $offset, $originalExpression);
                    }
                }
            } elseif ($child instanceof SyntaxNode) {
                $this->collectReplacements($child, $node, $scopeStack, $replacements, $offset, $originalExpression);
            }
        }
    }

    /**
     * @param array<int, array<int, string>> $scopeStack
     */
    private function shouldReplaceIdentifier(Identifier $node, ?SyntaxNode $parent, array $scopeStack): bool
    {
        if ($this->isIdentifierInLocalScope($node->getName(), $scopeStack)) {
            return false;
        }

        if ($this->shouldSkipIdentifier($node, $parent)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<int, string>> $scopeStack
     */
    private function isIdentifierInLocalScope(string $name, array $scopeStack): bool
    {
        foreach (array_reverse($scopeStack) as $scope) {
            if (in_array($name, $scope, true)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkipIdentifier(Identifier $node, ?SyntaxNode $parent): bool
    {
        if ($parent === null) {
            return false;
        }

        if ($parent instanceof Property) {
            if ($parent->getKey() === $node && !$parent->getComputed()) {
                return true;
            }

            if ($parent->getKind() !== Property::KIND_INIT) {
                return true;
            }
        }

        if ($parent instanceof MemberExpression && !$parent->getComputed() && $parent->getProperty() === $node) {
            return true;
        }

        if ($parent instanceof PropertyDefinition && $parent->getKey() === $node && !$parent->getComputed()) {
            return true;
        }

        if ($parent instanceof MethodDefinition && $parent->getKey() === $node && !$parent->getComputed()) {
            return true;
        }

        if ($parent instanceof ImportSpecifier || $parent instanceof ImportDefaultSpecifier || $parent instanceof ImportNamespaceSpecifier) {
            return true;
        }

        if ($parent instanceof ExportSpecifier) {
            return true;
        }

        if ($parent instanceof MetaProperty) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, array<int, string>> $scopeStack
     * @return array<int, array<int, string>>
     */
    private function updateScopeStack(SyntaxNode $node, array $scopeStack): array
    {
        $additional = [];

        if ($node instanceof ArrowFunctionExpression || $node instanceof FunctionExpression || $node instanceof FunctionDeclaration) {
            $additional = $this->extractBindingNames($node->getParams());

            $id = $node instanceof FunctionDeclaration || $node instanceof FunctionExpression ? $node->getId() : null;
            if ($id instanceof Identifier) {
                $additional[] = $id->getName();
            }
        } elseif ($node instanceof CatchClause) {
            $param = $node->getParam();
            if ($param !== null) {
                $additional = $this->extractBindingNames([$param]);
            }
        } elseif ($node instanceof VariableDeclarator) {
            $additional = $this->extractBindingNames([$node->getId()]);
        }

        if (!empty($additional)) {
            $scopeStack[] = $additional;
        }

        return $scopeStack;
    }

    /**
     * @param array<int, SyntaxNode|null> $patterns
     * @return array<int, string>
     */
    private function extractBindingNames(array $patterns): array
    {
        $names = [];
        foreach ($patterns as $pattern) {
            $names = array_merge($names, $this->extractBindingNamesFromPattern($pattern));
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    private function extractBindingNamesFromPattern(?SyntaxNode $pattern): array
    {
        if ($pattern === null) {
            return [];
        }

        if ($pattern instanceof Identifier) {
            return [$pattern->getName()];
        }

        if ($pattern instanceof AssignmentPattern) {
            return $this->extractBindingNamesFromPattern($pattern->getLeft());
        }

        if ($pattern instanceof ArrayPattern) {
            return $this->extractBindingNames($pattern->getElements());
        }

        if ($pattern instanceof ObjectPattern) {
            $names = [];
            foreach ($pattern->getProperties() as $property) {
                if ($property instanceof Property) {
                    $names = array_merge($names, $this->extractBindingNamesFromPattern($property->getValue()));
                } elseif ($property instanceof RestElement) {
                    $names = array_merge($names, $this->extractBindingNamesFromPattern($property->getArgument()));
                }
            }

            return $names;
        }

        if ($pattern instanceof RestElement) {
            return $this->extractBindingNamesFromPattern($pattern->getArgument());
        }

        return [];
    }

    /**
     * @param array<int, array{start: int, end: int, replacement: string}> $replacements
     */
    private function applyReplacements(string $expression, array $replacements): string
    {
        $result = $expression;
        foreach ($replacements as $replacement) {
            $length = $replacement['end'] - $replacement['start'];
            $result = $this->substrReplaceMultibyte($result, $replacement['replacement'], $replacement['start'], $length);
        }

        return $result;
    }

    private function substrReplaceMultibyte(string $string, string $replacement, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            $before = mb_substr($string, 0, $start, 'UTF-8');
            $after = mb_substr($string, $start + $length, null, 'UTF-8');

            if ($before !== false && $after !== false) {
                return $before . $replacement . $after;
            }
        }

        return substr_replace($string, $replacement, $start, $length);
    }

    private function expandShorthandProperties(SyntaxNode $node): void
    {
        if ($node instanceof Property && $node->getShorthand() && !$node->getComputed()) {
            $key = $node->getKey();
            if ($key instanceof Identifier) {
                $newKey = new Identifier();
                $newKey->setName($key->getName());
                $node->setKey($newKey);
                $node->setShorthand(false);
                $node->setValue($key);
            }
        }

        foreach (Utils::getNodeProperties($node, true) as $prop) {
            $child = $node->{$prop['getter']}();
            if ($child === null) {
                continue;
            }

            if (is_array($child)) {
                foreach ($child as $childNode) {
                    if ($childNode instanceof SyntaxNode) {
                        $this->expandShorthandProperties($childNode);
                    }
                }
            } elseif ($child instanceof SyntaxNode) {
                $this->expandShorthandProperties($child);
            }
        }
    }

    private function legacyParseExpression(string $expression): string
    {
        $pattern = '/(?<!["\'])(\.?[a-zA-Z_$]\w*(?:\.[a-zA-Z_$]\w*)*)(?!["\'])/';
        $callback = fn($matches) => $this->getVarScope($matches[0]);

        $parts = preg_split('/(["\'].+?["\'])/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $expression;
        }

        $replacements = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $nonStringPart = $parts[$i];
            $replacements[] = preg_replace_callback($pattern, $callback, $nonStringPart);
        }

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


    /**
     */
    public function resolveDirective($name): string
    {
        $instance_id = $this->directives[$name] ?? null;
        if ($instance_id === null) {
            $instance_id = 'w_' . $this->directives->count();
            $this->directives->offsetSet($name, $instance_id);
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
