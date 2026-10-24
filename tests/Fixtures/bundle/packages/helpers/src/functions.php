<?php

namespace Sample\Helpers;

function shout(string $text): string
{
    return strtoupper($text);
}

if (! function_exists(__NAMESPACE__.'\\whisper')) {
    function whisper(string $text): string
    {
        return strtolower($text);
    }
}
