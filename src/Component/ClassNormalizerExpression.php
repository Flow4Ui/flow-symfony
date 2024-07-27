<?php

namespace Flow\Component;

class ClassNormalizerExpression extends Expression
{

    public array $expressions = [];

    public function __construct(Expression|string $expression)
    {
        parent::__construct('');
        $this->addExpression($expression);
    }


    public function addExpression(Expression|string $expression): void
    {
        $this->expressions[] = $expression;
    }

    public function render(?Context $context = null): string
    {
        return sprintf(
            'v.normalizeClass([%s])',
            implode(
                ',',
                array_map(
                    fn(Expression|string $expression) => is_string($expression) ? json_encode($expression) : $expression->render($context),
                    $this->expressions,
                ),
            ),
        );
    }
}