<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use FilesystemIterator;
use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks a bundled module's bundle/ directory without executing any of it.
 *
 * The host loads bundle/classmap.php after its own autoloader, so the rules
 * here are what keep a bundle from shadowing host or framework classes,
 * smuggling in Composer's own (prepending) autoloader, or shipping a package
 * the module never declared.
 */
final class BundleValidator
{
    /** The host's archive limit is 10,000 entries for the whole module; leave room for the module itself. */
    public const MAX_FILES = 9000;

    /** Namespaces a bundled class may never use: the host, its modules, the SDK, Composer and the framework. */
    public const RESERVED_NAMESPACES = [
        'App\\', 'Composer\\', 'Database\\', 'Illuminate\\', 'InvoiceShelf\\', 'Laravel\\', 'Modules\\', 'Nwidart\\', 'Tests\\',
    ];

    /**
     * @param  list<string>  $requires  third-party packages the module's composer.json requires
     */
    public static function validate(string $root, ModuleManifest $module, array $requires): BundleManifest
    {
        $bundle = $root.DIRECTORY_SEPARATOR.BundleManifest::DIRECTORY;
        if (! is_dir($bundle) || is_link($bundle)) {
            throw new InvalidArgumentException('Bundled module must contain a bundle/ directory; run invoiceshelf-module bundle.');
        }

        $manifest = BundleManifest::fromFile($bundle.DIRECTORY_SEPARATOR.BundleManifest::FILE);

        self::validateTree($bundle, $manifest);
        self::validateClassmap($bundle, $manifest);
        self::validateFiles($bundle, $manifest);

        foreach ($requires as $package) {
            if (! isset($manifest->packages[$package]) && ! isset($manifest->hostPackages[$package])) {
                throw new InvalidArgumentException("composer.json requires '{$package}', but bundle/ neither ships it nor records it as a host package; run invoiceshelf-module bundle.");
            }
        }

        $missing = array_diff($manifest->extensions(), $module->compatibility->extensions, BundleManifest::CORE_EXTENSIONS);
        if ($missing !== []) {
            throw new InvalidArgumentException('Bundled packages need '.implode(', ', $missing).'; add them to compatibility.extensions in module.json.');
        }

        return $manifest;
    }

    /**
     * Read bundle/classmap.php as data: a single `return [...]` of string pairs.
     *
     * @return array<string, string>
     */
    public static function classmap(string $bundle): array
    {
        $path = $bundle.DIRECTORY_SEPARATOR.BundleManifest::CLASSMAP;
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidArgumentException('Bundled module must contain bundle/classmap.php; run invoiceshelf-module bundle.');
        }

        try {
            $statements = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];
        } catch (Error $exception) {
            throw new InvalidArgumentException('bundle/classmap.php cannot be parsed: '.$exception->getMessage(), previous: $exception);
        }

        if (count($statements) !== 1 || ! $statements[0] instanceof Return_ || ! $statements[0]->expr instanceof Array_) {
            throw new InvalidArgumentException('bundle/classmap.php must contain only a returned array.');
        }

        $classmap = [];
        foreach ($statements[0]->expr->items as $item) {
            if (! $item instanceof ArrayItem || ! $item->key instanceof String_ || ! $item->value instanceof String_ || $item->byRef || $item->unpack) {
                throw new InvalidArgumentException('bundle/classmap.php must map class names to relative paths as plain strings.');
            }
            $classmap[$item->key->value] = $item->value->value;
        }

        return $classmap;
    }

    /**
     * Functions a file declares unconditionally, fully qualified.
     *
     * Declarations inside an `if` (the usual `if (! function_exists(...))`
     * guard) are left out: they cannot collide fatally.
     *
     * @return list<string>
     */
    public static function declaredFunctions(string $path): array
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidArgumentException("Cannot read bundled file {$path}.");
        }

        try {
            $statements = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];
        } catch (Error $exception) {
            throw new InvalidArgumentException("Bundled file {$path} cannot be parsed: ".$exception->getMessage(), previous: $exception);
        }

        $functions = [];
        $collect = function (array $statements, string $namespace) use (&$functions): void {
            foreach ($statements as $statement) {
                if ($statement instanceof Function_) {
                    $functions[] = ltrim($namespace.'\\'.$statement->name->toString(), '\\');
                }
            }
        };
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $collect($statement->stmts, $statement->name?->toString() ?? '');
            }
        }
        $collect(array_filter($statements, fn (Stmt $statement): bool => ! $statement instanceof Namespace_), '');

        return $functions;
    }

    public static function isReservedClass(string $class): bool
    {
        foreach (self::RESERVED_NAMESPACES as $prefix) {
            if (stripos($class, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function validateTree(string $bundle, BundleManifest $manifest): void
    {
        $files = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bundle, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($bundle) + 1));
            if ($entry->isLink()) {
                throw new InvalidArgumentException("bundle/{$relative} is a symbolic link; bundles must contain plain files.");
            }

            $parts = explode('/', $relative);
            if (count($parts) === 1) {
                if (in_array($relative, [BundleManifest::FILE, BundleManifest::CLASSMAP], true)) {
                    $files++;

                    continue;
                }
                if ($relative === 'autoload.php') {
                    throw new InvalidArgumentException("bundle/autoload.php is Composer's own autoloader, which the host never loads; rebuild with invoiceshelf-module bundle.");
                }
                if (! $entry->isDir()) {
                    throw new InvalidArgumentException("bundle/{$relative} is not part of a bundled package.");
                }

                continue;
            }

            $package = $parts[0].'/'.$parts[1];
            if (! isset($manifest->packages[$package])) {
                throw new InvalidArgumentException("bundle/{$package} is not a package declared in bundle.json.");
            }
            if ($entry->isFile() && ++$files > self::MAX_FILES) {
                throw new InvalidArgumentException('bundle/ holds more than '.self::MAX_FILES.' files; the marketplace accepts at most 10,000 entries per module.');
            }
        }

        foreach (array_keys($manifest->packages) as $package) {
            if (! is_dir($bundle.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $package))) {
                throw new InvalidArgumentException("bundle.json declares '{$package}', but bundle/{$package} is missing.");
            }
        }
    }

    private static function validateFiles(string $bundle, BundleManifest $manifest): void
    {
        $functions = [];
        foreach ($manifest->files as $file) {
            $path = $bundle.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (! is_file($path)) {
                throw new InvalidArgumentException("bundle.json lists bundle/{$file}, which does not exist.");
            }
            array_push($functions, ...self::declaredFunctions($path));
        }
        foreach ($functions as $function) {
            if (self::isReservedClass($function)) {
                throw new InvalidArgumentException("Bundled function '{$function}' uses a namespace reserved for InvoiceShelf, its modules, Composer or Laravel.");
            }
        }

        $declared = $manifest->functions;
        sort($functions, SORT_STRING);
        sort($declared, SORT_STRING);
        if ($functions !== $declared) {
            throw new InvalidArgumentException('bundle.json functions do not match what the bundled files declare; rebuild with invoiceshelf-module bundle.');
        }
    }

    private static function validateClassmap(string $bundle, BundleManifest $manifest): void
    {
        foreach (self::classmap($bundle) as $class => $path) {
            if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $class) !== 1) {
                throw new InvalidArgumentException("bundle/classmap.php lists an invalid class name '{$class}'.");
            }
            if (self::isReservedClass($class)) {
                throw new InvalidArgumentException("Bundled class '{$class}' uses a namespace reserved for InvoiceShelf, its modules, Composer or Laravel.");
            }

            $parts = explode('/', $path);
            if (str_starts_with($path, '/') || str_contains($path, '\\') || in_array('..', $parts, true) || in_array('', $parts, true)
                || ! str_ends_with($path, '.php') || count($parts) < 3 || ! isset($manifest->packages[$parts[0].'/'.$parts[1]])) {
                throw new InvalidArgumentException("bundle/classmap.php maps '{$class}' to '{$path}', which is not a PHP file inside a bundled package.");
            }
            if (! is_file($bundle.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path))) {
                throw new InvalidArgumentException("bundle/classmap.php maps '{$class}' to bundle/{$path}, which does not exist.");
            }
        }
    }
}
