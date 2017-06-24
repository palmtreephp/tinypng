<?php

namespace Palmtree\TinyPng\Util;

class Cli
{
    /**
     * Returns whether the script was executed from the command line.
     * @return boolean
     */
    public static function isCli()
    {
        return (!isset($_SERVER['SERVER_SOFTWARE']) &&
                (php_sapi_name() === 'cli' || (is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0))
        );
    }

    /**
     * Replaces an array of default options with any passed in the command line.
     *
     * @param array $defaults Array of defaults to build options with.
     * @param array $required Array of required keys.
     *
     * @return array
     */
    public static function getOptions(array $defaults, array $required = [])
    {
        $optionMap = [];

        foreach ($defaults as $key => $value) {
            $chr         = substr($key, 0, 1);
            $longoptKey  = $key . ':';
            $shortoptKey = $chr . ':';

            if (!in_array($key, $required)) {
                $longoptKey  .= ':';
                $shortoptKey .= ':';
            }

            $optionMap[$shortoptKey] = $longoptKey;
        }

        $opts = getopt(implode('', array_keys($optionMap)), $optionMap);

        $options = [];

        foreach ($opts as $key => $value) {
            $mapKey = current(preg_grep('/^' . $key . '\:{1,2}/', array_keys($optionMap)));

            if (isset($optionMap[$mapKey])) {
                $key = $optionMap[$mapKey];
            }

            $optionKey = rtrim($key, ':');

            $toArray = isset($defaults[$optionKey]) && is_array($defaults[$optionKey]);

            $value = Util::normalizeValue($value, $toArray);

            $options[$optionKey] = $value;
        }

        return $options;
    }

    public static function getUsage()
    {
        $usage = <<< USAGE
USAGE:
 -a, --api_key          API key obtained from https://tinypng.com/developers
 -b, --backup_path      Path to store backups of original images. Defaults to false (no backups).
 -c, --callback         Optional callback function to be called for every file iteration of the shrink() method.
 -d, --date_format      Date format for log files.
 -e, --extensions       Valid file extensions to search for in 'path'.
 -f, --fail_log         File to write all failed compressions to, relative to 'path' option. Set to false to disable.
 -l, --log              File to write all log messages to, relative to 'path' option. Set to false to disable.
 -m, --max_failures     Maximum number of failed compressions to allow before giving up.
 -p, --path             Path in which to search for files.
 -q, --quiet            Set to true to disable echo-ing of log messages. Defaults to false in a CLI environment.


USAGE;

        return $usage;
    }
}
