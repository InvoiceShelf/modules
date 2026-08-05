<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use FilesystemIterator;
use InvalidArgumentException;
use InvoiceShelf\Modules\Contracts\DataCleanup;
use PhpParser\Error;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Confirms a package declares its schema-v2 cleanup class without loading it. */
final class CleanupValidator
{
    public static function validateDirectory(string $directory, string $dataCleanup): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php' || self::isExcluded($file->getPathname(), $directory)) {
                continue;
            }

            $class = self::findClass($file->getPathname(), $dataCleanup);
            if ($class !== null) {
                self::validateClass($class, $dataCleanup);

                return;
            }
        }

        throw new InvalidArgumentException("Module uninstall data_cleanup class '{$dataCleanup}' must exist and implement ".DataCleanup::class.'.');
    }

    private static function findClass(string $path, string $dataCleanup): ?Class_
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new InvalidArgumentException("Module cleanup source '{$path}' cannot be read.");
        }

        try {
            $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $statements = $traverser->traverse($statements);
        } catch (Error $error) {
            throw new InvalidArgumentException("Module cleanup source '{$path}' is not valid PHP: {$error->getMessage()}", previous: $error);
        }

        foreach ((new NodeFinder)->findInstanceOf($statements, Class_::class) as $class) {
            if (self::className($class) === $dataCleanup) {
                return $class;
            }
        }

        return null;
    }

    private static function className(Class_ $class): ?string
    {
        $name = $class->namespacedName;

        return $name instanceof Name ? $name->toString() : null;
    }

    private static function validateClass(Class_ $class, string $dataCleanup): void
    {
        $implementsCleanup = false;
        foreach ($class->implements as $interface) {
            if (ltrim($interface->toString(), '\\') === DataCleanup::class) {
                $implementsCleanup = true;
                break;
            }
        }
        if (! $implementsCleanup) {
            throw new InvalidArgumentException("Module uninstall data_cleanup class '{$dataCleanup}' must implement ".DataCleanup::class.'.');
        }

        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) === 'cleanup') {
                self::validateCleanupMethod($method, $dataCleanup);

                if ($class->isAbstract()) {
                    throw new InvalidArgumentException("Module uninstall data_cleanup class '{$dataCleanup}' must not be abstract.");
                }

                return;
            }
        }

        throw new InvalidArgumentException("Module uninstall data_cleanup class '{$dataCleanup}' must declare a concrete public cleanup(): void method.");
    }

    private static function validateCleanupMethod(ClassMethod $method, string $dataCleanup): void
    {
        if (! $method->isPublic() || $method->isStatic() || $method->isAbstract() || $method->params !== [] || $method->returnType?->toString() !== 'void') {
            throw new InvalidArgumentException("Module uninstall data_cleanup class '{$dataCleanup}' must declare a concrete public zero-argument cleanup(): void method.");
        }
    }

    private static function isExcluded(string $path, string $directory): bool
    {
        $relative = substr($path, strlen(rtrim($directory, DIRECTORY_SEPARATOR)) + 1);

        return $relative !== false && preg_match('#(?:^|/)(?:vendor|node_modules|\.git)(?:/|$)#', str_replace(DIRECTORY_SEPARATOR, '/', $relative)) === 1;
    }
}
