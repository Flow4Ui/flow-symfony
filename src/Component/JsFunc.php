<?php

namespace Flow\Component;

class JsFunc
{

    /**
     * @param array<string> $params
     * @param string $body
     */
    public function __construct(protected array $params, protected string $body)
    {
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

}