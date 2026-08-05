<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts\Host;

/**
 * Read-only company data required by the AI assistant's twelve built-in queries.
 *
 * Returned values are arrays of scalar data only. Hosts must not expose ORM
 * models, collections, or framework-specific value objects across this boundary.
 */
interface CompanyDataReader
{
    /** @return array<string, mixed> */
    public function companyStats(int $companyId, string $startDate, string $endDate): array;

    /** @return array<string, mixed>|null */
    public function findCustomer(int $companyId, int $customerId): ?array;

    /** @return array<string, mixed> */
    public function searchCustomers(int $companyId, ?string $query, int $limit): array;

    /** @return array<string, mixed> */
    public function rankCustomers(int $companyId, string $metric, ?string $startDate, ?string $endDate, int $limit): array;

    /** @return array<string, mixed>|null */
    public function findInvoice(int $companyId, string $invoiceNumber): ?array;

    /** @return array<string, mixed> */
    public function searchInvoices(
        int $companyId,
        ?string $query,
        ?string $status,
        ?int $customerId,
        int $limit,
    ): array;

    /** @return array<string, mixed> */
    public function overdueInvoices(int $companyId, int $limit): array;

    /** @return array<string, mixed> */
    public function recentPayments(int $companyId, string $startDate, int $limit): array;

    /** @return array<string, mixed> */
    public function expenseCategories(int $companyId): array;

    /** @return array<string, mixed> */
    public function rankExpenseCategories(int $companyId, ?string $startDate, ?string $endDate, int $limit): array;

    /** @return array<string, mixed> */
    public function searchItems(int $companyId, ?string $query, int $limit): array;

    /** @return array<string, mixed> */
    public function rankItems(int $companyId, string $metric, ?string $startDate, ?string $endDate, int $limit): array;
}
