<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Ai\Data;

/**
 * Provider-agnostic chat completion response modelled after OpenAI's shape.
 */
class AiChatResponse
{
    /**
     * @param  string|null  $message  Null for a tool-call-only turn.
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     * @param  array{tokens_in?: int, tokens_out?: int}  $usage
     */
    public function __construct(
        public readonly ?string $message,
        public readonly array $toolCalls = [],
        public readonly string $finishReason = 'stop',
        public readonly array $usage = [],
        public readonly ?string $model = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
