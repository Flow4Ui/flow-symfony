<?php

namespace Flow\Template;

class Preprocessor
{
    const V_ON = Compiler::V_ON;
    const V_SLOT = Compiler::V_SLOT;
    const DEFAULT_VALUE = Compiler::DEFAULT_VALUE;

    protected array $idToName = [];
    protected array $nameToId = [];
    private ?PreprocessorState $currentState = null;
    private ?PreprocessorState $nextState = null;
    private ?PreprocessorState $previousState = null;
    private int $numOfBytes = 0;
    private ?string $previousByte = null;
    private ?string $nextStateByte = null;
    private ?int $pinnedPosition = null;
    private ?string $currentByte = null;
    private int $position = 0;
    private string $bytes = '';
    private bool $isWhitespace = false;
    private array $pinnedPositions = [];
    private ?string $lastIdentifier = null;

    public function preprocess(string $template): string
    {
        $this->numOfBytes = strlen($template);
        $this->bytes = $template;

        for ($this->position = 0; $this->position < $this->numOfBytes; $this->position++) {
            $this->currentByte = $this->bytes[$this->position];
            $this->isWhitespace = $this->currentByte === ' ' || $this->currentByte === "\n";

            $this->processCurrentState();

            $this->previousByte = $this->currentByte;
        }

        return $this->bytes;
    }

    private function processCurrentState(): void
    {
        switch ($this->currentState) {
            case PreprocessorState::Text:
            case null:
                $this->processTextState();
                break;
            case PreprocessorState::Expression:
                $this->processExpressionState();
                break;
            case PreprocessorState::Whitespace:
                $this->processWhitespaceState();
                break;
            case PreprocessorState::TagName:
            case PreprocessorState::TagNameClosing:
                $this->processTagNameState();
                break;
            case PreprocessorState::AttributeName:
                $this->processAttributeNameState();
                break;
            case PreprocessorState::Equal:
                $this->processEqualState();
                break;
            case PreprocessorState::AttributeValue:
                $this->processAttributeValueState();
                break;
            case PreprocessorState::Comment:
                $this->processCommentState();
                break;
        }
    }

    private function processTextState(): void
    {
        if ($this->currentByte === '<') {
            if ($this->lookAhead('<!--')) {
                $this->setCurrentState(PreprocessorState::Comment);
            } else {
                $this->setCurrentState(PreprocessorState::TagName);
                $this->pinPosition(1);
            }
        } elseif ($this->currentByte === '{' && $this->previousByte === '{') {
            $this->setCurrentState(PreprocessorState::Expression);
            $this->pinPosition(-1);
        }
    }

    public function lookAhead(string $text, ?int $length = null): bool
    {
        $length = $length ?? strlen($text);
        return ($this->position + $length <= strlen($this->bytes)) &&
               (substr_compare($this->bytes, $text, $this->position, $length) === 0);
    }

    public function setCurrentState(PreprocessorState $newState, ?PreprocessorState $nextState = null): void
    {
        if ($this->currentState !== PreprocessorState::Whitespace && $this->currentState !== PreprocessorState::Comment) {
            $this->previousState = $this->currentState;
        }
        $this->currentState = $newState;
        $this->nextState = $nextState;
    }

    private function pinPosition(int $nexts = 0): void
    {
        if ($this->pinnedPosition !== null) {
            $this->pinnedPositions[] = $this->pinnedPosition;
        }
        $this->pinnedPosition = $this->position + $nexts;
    }

    private function processExpressionState(): void
    {
        if ($this->currentByte === '\'' || $this->currentByte === '"') {
            $this->processStringMode();
        } elseif ($this->currentByte === '}' && $this->previousByte === '}') {
            $this->setCurrentState(PreprocessorState::Text);
            $expressionCode = $this->getPinnedBytes();
            $codeElement = sprintf('<flow-expression>%s</flow-expression>', base64_encode(substr($expressionCode, 2, -1)));
            $this->position++;
            $this->replacePinnedBytes($codeElement);
            $this->position--;
        }
    }

    private function processStringMode(): void
    {
        if ($this->nextStateByte === null) {
            $this->nextStateByte = $this->currentByte;
        } elseif ($this->currentByte === $this->nextStateByte && $this->previousByte !== '\\') {
            $this->nextStateByte = null;
        }
    }

    private function getPinnedBytes(int $skips = 0, int $from = 0): string
    {
        return substr($this->bytes, $this->pinnedPosition + $from, ($this->position - $this->pinnedPosition) - $skips);
    }

    private function replacePinnedBytes(string $replacement, int $skips = 0, int $from = 0): void
    {
        $this->bytes = substr_replace($this->bytes, $replacement, $this->pinnedPosition + $from, ($this->position - $this->pinnedPosition) - $skips);
        $this->numOfBytes += strlen($replacement) - ($this->position - $this->pinnedPosition);
        $this->position = $this->pinnedPosition + strlen($replacement);
        $this->currentByte = $this->bytes[$this->position];
    }

    private function processWhitespaceState(): void
    {
        if (!$this->isWhitespace) {
            $this->setCurrentState($this->nextState);
            $this->pinPosition();
            $this->rewind();
        }
    }

    public function rewind(int $number = 1): int
    {
        $this->position -= $number;
        $this->currentByte = $this->bytes[$this->position];
        return $this->position;
    }

    private function processTagNameState(): void
    {
        if ($this->isWhitespace) {
            $this->setCurrentState(PreprocessorState::Whitespace,
                $this->currentState === PreprocessorState::TagName ? PreprocessorState::AttributeName : PreprocessorState::Text);
        } elseif ($this->currentByte === '>') {
            $this->setCurrentState(PreprocessorState::Text);
        } elseif ($this->previousByte === '<' && $this->currentByte === '/') {
            $this->clearPinnedPosition();
            $this->pinPosition(1);
            $this->setCurrentState(PreprocessorState::TagNameClosing);
        } elseif ($this->currentByte === '/') {
            $this->setCurrentState(PreprocessorState::Text);
        }

        if ($this->currentState !== PreprocessorState::TagName && $this->currentState !== PreprocessorState::TagNameClosing) {
            $name = $this->processNameToId($this->getPinnedBytes());
            $this->replacePinnedBytes($name);
            $this->clearPinnedPosition();
            $this->rewind();
        }
    }

    private function clearPinnedPosition(): void
    {
        $this->pinnedPosition = null;
    }

    private function processNameToId(string $name): ?string
    {
        $this->lastIdentifier = $name;
        if (isset($this->nameToId[$name])) {
            return $this->nameToId[$name];
        }
        $id = 'i' . count($this->idToName);
        $this->idToName[$id] = $name;
        $this->nameToId[$name] = $id;
        return $id;
    }

    private function processAttributeNameState(): void
    {
        if ($this->currentByte === '#') {
            $this->processAttributeSpecialCharacter(self::V_SLOT);
        } elseif ($this->currentByte === '@' && $this->pinSize() === 0) {
            $this->processAttributeSpecialCharacter(self::V_ON);
        } elseif ($this->currentByte === '=' || $this->currentByte === '>' || $this->currentByte === '/') {
            $this->setCurrentState(PreprocessorState::Equal);
        } elseif ($this->isWhitespace) {
            $this->setCurrentState(PreprocessorState::Whitespace, PreprocessorState::Equal);
        }
        if ($this->currentState !== PreprocessorState::AttributeName) {
            if ($this->pinSize() > 0) {
                $name = $this->processNameToId($this->getPinnedBytes());
                $this->replacePinnedBytes($name);
            } else {
                $this->lastIdentifier = null;
            }
            $this->clearPinnedPosition();
            $this->rewind();
        }
    }

    private function processAttributeSpecialCharacter(string $attribute): void
    {
        $this->updateBytes($attribute, 1);
    }

    private function updateBytes(string $text, int $numReplacements = 0, int $rewind = 0): void
    {
        $this->bytes = substr_replace($this->bytes, $text, $this->position - $rewind, $numReplacements);
        $this->numOfBytes += strlen($text) - $numReplacements;
    }

    public function pinSize(): int
    {
        return $this->pinnedPosition === null ? 0 : $this->position - $this->pinnedPosition;
    }

    private function processEqualState(): void
    {
        if ($this->currentByte !== '=') {
            if ($this->processDefaultAttributeValue()) {
                $this->rewind();
            } elseif ($this->currentByte === '>' || $this->currentByte === '/') {
                $this->setCurrentState(PreprocessorState::Text);
            } else {
                $this->setCurrentState(PreprocessorState::AttributeName);
            }
        } else {
            $this->setCurrentState(PreprocessorState::Whitespace, PreprocessorState::AttributeValue);
            $this->nextStateByte = null;
        }
    }

    private function processDefaultAttributeValue(): bool
    {
        if ($this->previousState === PreprocessorState::AttributeName && !empty($this->lastIdentifier) && !str_starts_with($this->lastIdentifier, 'v-')) {
            $this->updateBytes(self::DEFAULT_VALUE);
            return true;
        }
        return false;
    }

    private function processAttributeValueState(): void
    {
        if ($this->nextStateByte === null && ($this->currentByte === '\'' || $this->currentByte === '"') && ($this->previousByte === ' ' || $this->previousByte === "\n" || $this->previousByte === '=')) {
            $this->nextStateByte = $this->currentByte;
        } elseif ($this->nextStateByte === null) {
            if ($this->isWhitespace) {
                $this->setCurrentState(PreprocessorState::Whitespace, PreprocessorState::AttributeName);
                $this->rewind();
            }
        } elseif ($this->currentByte === $this->nextStateByte) {
            $this->setCurrentState(PreprocessorState::Whitespace, PreprocessorState::AttributeName);
            $this->nextStateByte = null;
        }
    }

    private function processCommentState(): void
    {
        if ($this->currentByte === '>' && $this->lookBehind('-->')) {
            $this->setCurrentState(PreprocessorState::Text);
        }
    }

    public function lookBehind(string $text): bool
    {
        $strlen = strlen($text);
        return ($this->position >= $strlen) &&
               (substr_compare($this->bytes, $text, $this->position - $strlen + 1, $strlen) === 0);
    }

    public function getNameFromId(string $name): ?string
    {
        return $this->idToName[$name] ?? null;
    }

    private function unpinPosition(): void
    {
        if (!empty($this->pinnedPositions)) {
            $this->pinnedPosition = array_pop($this->pinnedPositions);
            $this->position = $this->pinnedPosition;
        } else {
            $this->pinnedPosition = null;
        }
    }
}
