<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvoiceShelf\Modules\Ai\Contracts\AiDriver;
use InvoiceShelf\Modules\Ai\Data\AiChatResponse;
use InvoiceShelf\Modules\Ai\Exceptions\AiException;
use InvoiceShelf\Modules\Contracts\Host\CompanyDataReader;
use InvoiceShelf\Modules\Contracts\Host\ModuleAuthorization;
use InvoiceShelf\Modules\Contracts\Host\SettingsStore;
use ReflectionMethod;
use RuntimeException;

class AiContractsTest extends TestCase
{
    public function test_chat_response_preserves_its_provider_neutral_shape(): void
    {
        $response = new AiChatResponse(
            message: null,
            toolCalls: [['id' => 'call_1', 'name' => 'search_invoices', 'arguments' => ['query' => 'overdue']]],
            finishReason: 'tool_calls',
            usage: ['tokens_in' => 10, 'tokens_out' => 20],
            model: 'provider/model',
        );

        $this->assertTrue($response->hasToolCalls());
        $this->assertNull($response->message);
        $this->assertSame('tool_calls', $response->finishReason);
        $this->assertSame(['tokens_in' => 10, 'tokens_out' => 20], $response->usage);
        $this->assertSame('provider/model', $response->model);
        $this->assertFalse((new AiChatResponse('Done'))->hasToolCalls());
    }

    public function test_ai_exception_exposes_a_stable_error_key_and_previous_exception(): void
    {
        $previous = new RuntimeException('network failure');
        $exception = new AiException('Unable to reach provider', 'invalid_api_key', 401, $previous);

        $this->assertSame('invalid_api_key', $exception->errorKey);
        $this->assertSame(401, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('server_error', (new AiException('failed'))->errorKey);
    }

    public function test_ai_driver_constructor_and_default_model_list_are_available_to_concrete_drivers(): void
    {
        $driver = new ContractTestAiDriver('secret', ['base_url' => 'https://provider.test']);

        $this->assertSame('secret', $driver->key());
        $this->assertSame(['base_url' => 'https://provider.test'], $driver->driverConfig());
        $this->assertSame([], $driver->listModels());
        $this->assertSame('response', $driver->textCompletion('prompt', 'model'));
        $this->assertSame('response', $driver->chatCompletion([], 'model')->message);
        $this->assertSame(['connected' => true], $driver->validateConnection());
    }

    public function test_host_contracts_expose_the_exact_framework_neutral_ai_boundary(): void
    {
        $this->assertSame(
            ['getGlobal', 'putGlobal', 'deleteGlobal', 'getCompany', 'putCompany', 'deleteCompany', 'deleteCompanyForAll'],
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(SettingsStore::class))->getMethods()),
        );
        $this->assertSame('allows', (new \ReflectionClass(ModuleAuthorization::class))->getMethod('allows')->getName());
        $this->assertSame(
            [
                'companyStats', 'findCustomer', 'searchCustomers', 'rankCustomers', 'findInvoice', 'searchInvoices',
                'overdueInvoices', 'recentPayments', 'expenseCategories', 'rankExpenseCategories', 'searchItems', 'rankItems',
            ],
            array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new \ReflectionClass(CompanyDataReader::class))->getMethods()),
        );

        $authorization = new ReflectionMethod(ModuleAuthorization::class, 'allows');
        $this->assertSame('int', $authorization->getParameters()[0]->getType()?->getName());
        $this->assertSame('int', $authorization->getParameters()[1]->getType()?->getName());
        $this->assertSame('string', $authorization->getParameters()[2]->getType()?->getName());
        $this->assertTrue($authorization->getParameters()[3]->getType()?->allowsNull());

        foreach ((new \ReflectionClass(CompanyDataReader::class))->getMethods() as $method) {
            $this->assertSame('array', $method->getReturnType()?->getName());
        }
        $this->assertTrue((new ReflectionMethod(CompanyDataReader::class, 'findCustomer'))->getReturnType()?->allowsNull());
        $this->assertTrue((new ReflectionMethod(CompanyDataReader::class, 'findInvoice'))->getReturnType()?->allowsNull());
    }
}

class ContractTestAiDriver extends AiDriver
{
    public function key(): string
    {
        return $this->apiKey;
    }

    /** @return array<string, mixed> */
    public function driverConfig(): array
    {
        return $this->config;
    }

    public function chatCompletion(array $messages, string $model, array $tools = [], array $options = []): AiChatResponse
    {
        return new AiChatResponse('response');
    }

    public function textCompletion(string $prompt, string $model, array $options = []): string
    {
        return 'response';
    }

    public function validateConnection(): array
    {
        return ['connected' => true];
    }
}
