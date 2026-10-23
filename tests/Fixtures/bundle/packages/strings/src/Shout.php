<?php

namespace Sample\Strings;

final class Shout
{
    public static function loud(string $text): string
    {
        return strtoupper($text);
    }
}
