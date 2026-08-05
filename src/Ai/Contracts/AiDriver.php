<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Ai\Contracts;

use InvoiceShelf\Modules\Ai\Data\AiChatResponse;
use InvoiceShelf\Modules\Ai\Exceptions\AiException;

/**
 * Provider-neutral contract for AI providers contributed by modules.
 */
abstract class AiDriver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected string $apiKey,
        protected array $config = [],
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages  OpenAI chat-message format.
     * @param  array<int, array<string, mixed>>  $tools  OpenAI tools schema array.
     * @param  array<string, mixed>  $options  Provider-specific options.
     *
     * @throws AiException
     */
    abstract public function chatCompletion(
        array $messages,
        string $model,
        array $tools = [],
        array $options = [],
    ): AiChatResponse;

    /**
     * @param  array<string, mixed>  $options  Provider-specific options.
     *
     * @throws AiException
     */
    abstract public function textCompletion(
        string $prompt,
        string $model,
        array $options = [],
    ): string;

    /**
     * @return array<string, mixed> Provider information suitable for the configuration UI.
     *
     * @throws AiException
     */
    abstract public function validateConnection(): array;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function listModels(): array
    {
        return [];
    }
}
