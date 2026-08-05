<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Manifest;

use FilesystemIterator;
use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Validates the reversible migration contract without executing module code. */
final class MigrationValidator
{
    public static function validateDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            self::validateFile($file->getPathname());
        }
    }

    public static function validateFile(string $path): void
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new InvalidArgumentException("Module migration '{$path}' cannot be read.");
        }

        try {
            $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $statements = $traverser->traverse($statements);
        } catch (Error $error) {
            throw new InvalidArgumentException("Module migration '{$path}' is not valid PHP: {$error->getMessage()}", previous: $error);
        }

        $classes = (new NodeFinder)->findInstanceOf($statements, Class_::class);
        if (count($classes) !== 1) {
            throw new InvalidArgumentException("Module migration '{$path}' must declare exactly one concrete Laravel migration class.");
        }

        self::validateMigrationClass($classes[0], $path);
    }

    private static function validateMigrationClass(Class_ $class, string $path): void
    {
        if ($class->isAbstract() || ltrim($class->extends?->toString() ?? '', '\\') !== 'Illuminate\\Database\\Migrations\\Migration') {
            throw new InvalidArgumentException("Module migration '{$path}' must extend Illuminate\\Database\\Migrations\\Migration and be concrete.");
        }

        $up = self::method($class, 'up');
        $down = self::method($class, 'down');

        self::validateMethod($up, 'up', $path);
        self::validateMethod($down, 'down', $path);

        foreach ((new NodeFinder)->find($up->stmts, static fn (Node $node): bool => self::isDestructiveCall($node)) as $call) {
            $name = self::callName($call);
            throw new InvalidArgumentException("Module migration '{$path}' may not call destructive method '{$name}' from up(). Destructive calls are allowed only in down().");
        }
    }

    private static function method(Class_ $class, string $name): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) === $name) {
                return $method;
            }
        }

        return null;
    }

    private static function validateMethod(?ClassMethod $method, string $name, string $path): void
    {
        if ($method === null || ! $method->isPublic() || $method->isStatic() || $method->params !== []
            || $method->stmts === null || $method->stmts === [] || $method->returnType?->toString() !== 'void') {
            throw new InvalidArgumentException("Module migration '{$path}' must declare a public, non-static, zero-argument, non-empty {$name}(): void method.");
        }
    }

    private static function isDestructiveCall(Node $node): bool
    {
        if (! ($node instanceof MethodCall || $node instanceof StaticCall || $node instanceof FuncCall)) {
            return false;
        }

        $name = self::callName($node);

        return str_starts_with($name, 'drop')
            || str_starts_with($name, 'rename')
            || str_starts_with($name, 'delete')
            || str_starts_with($name, 'truncate')
            || str_starts_with($name, 'update')
            || in_array($name, ['raw', 'unprepared', 'statement', 'affectingstatement'], true);
    }

    private static function callName(MethodCall|StaticCall|FuncCall $call): string
    {
        if ($call->name instanceof Identifier) {
            return strtolower($call->name->toString());
        }

        return $call->name instanceof Name ? strtolower($call->name->getLast()) : '';
    }
}
