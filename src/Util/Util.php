<?php

namespace Palmtree\TinyPng\Util;

class Util
{
    /**
     * Passes $path through realpath() and adds a trailing slash.
     *
     * @param string $path Path to normalize.
     *
     * @return string Normalized path.
     */
    public static function normalizePath($path)
    {
        return static::addTrailingSlash(realpath($path));
    }

    /**
     * Returns the input string with a trailing slash appended.
     *
     * @param string $string String to add trailing slash to.
     *
     * @return string String with trailing slash added.
     */
    public static function addTrailingSlash($string)
    {
        return rtrim($string, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Convert a given number of bytes into a human readable format,
     * using the largest unit the bytes will fit into.
     * Credit: WordPress core.
     *
     * @param mixed $bytes Number of bytes. Accepts int or string.
     *
     * @return boolean|string False on failure. Number string on success
     */
    public static function sizeFormat($bytes)
    {
        $quant = [
            // ========================= Origin ====
            'TB' => 1099511627776, // pow( 1024, 4)
            'GB' => 1073741824, // pow( 1024, 3)
            'MB' => 1048576, // pow( 1024, 2)
            'kB' => 1024, // pow( 1024, 1)
            'B'  => 1, // pow( 1024, 0)
        ];

        foreach ($quant as $unit => $mag) {
            if (doubleval($bytes) >= $mag) {
                return number_format($bytes / $mag, 2, '.', ',') . $unit;
            }
        }

        return false;
    }

    /**
     * Returns truthy/falsey strings as a boolean and numeric strings as an integer.
     *
     * @param string $value
     *
     * @return bool|int
     */
    public static function normalizeValue($value, $toArray = false)
    {
        if ($toArray) {
            return explode(',', $value);
        }

        if ($value === '1' || $value === 'true') {
            return true;
        }

        if ($value === '0' || $value === 'false') {
            return false;
        }

        if (ctype_digit($value)) {
            return (int)$value;
        }

        return $value;
    }
}
