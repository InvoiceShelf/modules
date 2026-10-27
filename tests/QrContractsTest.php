<?php

declare(strict_types=1);

namespace InvoiceShelf\Modules\Tests;

use InvoiceShelf\Modules\Contracts\Host\QrCodeGenerator;
use InvoiceShelf\Modules\Qr\ErrorCorrection;
use ReflectionClass;
use ReflectionMethod;

class QrContractsTest extends TestCase
{
    public function test_the_host_contract_draws_svg_and_svg_data_uris_only(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(QrCodeGenerator::class))->getMethods(),
        );
        sort($methods);

        $this->assertSame(['svg', 'svgDataUri'], $methods);

        foreach ($methods as $name) {
            $method = new ReflectionMethod(QrCodeGenerator::class, $name);

            $this->assertSame('string', (string) $method->getReturnType());
            $this->assertSame(
                ['content' => 'string', 'size' => 'int', 'errorCorrection' => ErrorCorrection::class, 'margin' => 'int'],
                array_combine(
                    array_map(static fn ($parameter): string => $parameter->getName(), $method->getParameters()),
                    array_map(static fn ($parameter): string => (string) $parameter->getType(), $method->getParameters()),
                ),
            );
            $this->assertSame([200, ErrorCorrection::Medium, 4], array_map(
                static fn ($parameter) => $parameter->getDefaultValue(),
                array_slice($method->getParameters(), 1),
            ));
        }
    }

    public function test_error_correction_levels_carry_the_standard_letters(): void
    {
        $this->assertSame(
            ['Low' => 'L', 'Medium' => 'M', 'Quartile' => 'Q', 'High' => 'H'],
            array_combine(
                array_map(static fn (ErrorCorrection $level): string => $level->name, ErrorCorrection::cases()),
                array_map(static fn (ErrorCorrection $level): string => $level->value, ErrorCorrection::cases()),
            ),
        );
    }
}
