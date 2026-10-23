<?php

declare(strict_types=1);

namespace Modules\TasksProjects\Lifecycle;

use InvoiceShelf\Modules\Contracts\DataCleanup as DataCleanupContract;
use InvoiceShelf\Modules\Contracts\Host\SettingsStore;
use Modules\TasksProjects\Support\ModuleSettings;

/** Removes the module's per-company settings when the host asks to remove module data. */
final class DataCleanup implements DataCleanupContract
{
    /** The keys that are not switches; the switches come from ModuleSettings. */
    private const VALUE_KEYS = [
        'default_rate',
        'rounding_minutes',
        'rounding_direction',
        'week_start',
    ];

    public function __construct(private readonly SettingsStore $settings) {}

    public function cleanup(): void
    {
        foreach (self::settingKeys() as $key) {
            $this->settings->deleteCompanyForAll(ModuleSettings::PREFIX.$key);
        }
    }

    /**
     * Every key stored under `module.tasks-projects.<key>` for a company.
     *
     * The switches are read from the same table the getters and the settings
     * schema use, so a new toggle is added in one place and is cleaned up here
     * without anyone remembering to come back.
     *
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        return [...self::VALUE_KEYS, ...array_keys(ModuleSettings::FLAGS)];
    }
}
