<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Bundle;

use RuntimeException;

/**
 * Runs the Composer binary for a bundle build.
 *
 * The SDK shells out rather than depending on composer/composer: the binary is
 * already on every machine that builds a module, and its version is what the
 * release workflow pins.
 */
final readonly class ComposerProcess
{
    /** @param list<string> $command */
    private function __construct(private array $command) {}

    /**
     * The explicit --composer path wins, then COMPOSER_BINARY (set by Composer
     * for scripts), then `composer` on the PATH. A .phar runs through this PHP.
     */
    public static function locate(?string $binary = null): self
    {
        $binary ??= getenv('COMPOSER_BINARY') ?: 'composer';

        return new self(str_ends_with($binary, '.phar') ? [PHP_BINARY, $binary] : [$binary]);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{int, string} exit code and combined output
     */
    public function run(array $arguments, string $directory): array
    {
        $environment = getenv();
        // A parent Composer run can point these at its own project.
        unset($environment['COMPOSER'], $environment['COMPOSER_VENDOR_DIR'], $environment['COMPOSER_BIN_DIR']);
        $environment['COMPOSER_NO_INTERACTION'] = '1';
        // The release workflow audits separately; a bundle build should not depend on the advisory API.
        $environment['COMPOSER_NO_AUDIT'] = '1';

        $pipes = [];
        $process = proc_open(
            [...$this->command, ...$arguments],
            // One pipe for both streams, so a chatty stderr cannot fill its buffer and stall the run.
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $directory,
            $environment,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start Composer ('.implode(' ', $this->command).').');
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), trim($output)];
    }
}
