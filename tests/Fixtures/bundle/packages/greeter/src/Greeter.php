<?php

namespace Sample\Greeter;

use Sample\Strings\Shout;

final class Greeter
{
    public function greet(string $name): string
    {
        return Shout::loud('hello '.$name);
    }
}
