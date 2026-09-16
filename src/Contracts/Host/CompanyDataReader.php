<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Contracts\Host;

/**
 * Read-only, company-scoped queries the host exposes to official modules.
 *
 * Introduced for the AI assistant's built-in queries, the reader now also
 * serves other official modules that need to read company data without
 * touching host Eloquent models.
 *
 * Returned values are arrays of scalar data only. Hosts must not expose ORM
 * models, collections, or framework-specific value objects across this boundary.
 */
interface CompanyDataReader
{
    /** @return array<string, mixed> */
    public function companyStats(int $companyId, string $startDate, string $endDate): array;

    /**
     * The row carries the customer's `currency_id` and a `currency` sub-array
     * shaped {id, code, symbol, precision}, or null when none is set.
     *
     * @return array<string, mixed>|null
     */
    public function findCustomer(int $companyId, int $customerId): ?array;

    /**
     * Rows carry the customer's `currency_id`.
     *
     * @return array<string, mixed>
     */
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

    /** @return list<array{id: int, name: string, email: string, avatar: string|null}> members of the company, ordered by name then id */
    public function companyMembers(int $companyId): array;

    /**
     * @param  list<int>  $invoiceIds
     * @return list<int> the subset of $invoiceIds that exist in the company
     */
    public function existingInvoiceIds(int $companyId, array $invoiceIds): array;
}
