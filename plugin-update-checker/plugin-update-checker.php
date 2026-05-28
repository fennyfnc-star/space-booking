<?php

/**
 * Plugin Update Checker 5.x by Yahnis Elsts.
 * https://github.com/YahnisElsts/plugin-update-checker
 */
if (!defined('ABSPATH')) {
    exit;
}

// Autoload classes.
spl_autoload_register(array(__NAMESPACE__ . '\Autoloader', 'autoload'));

/**
 * Simple classmap-based autoloader for PUC.
 */
class Autoloader
{
    public static function autoload($className)
    {
        // Only load classes from this library.
        if (strpos($className, "YahnisElsts\PluginUpdateChecker\\v5\\") !== 0) {
            return;
        }

        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        $path .= str_replace(array('\\', '_'), DIRECTORY_SEPARATOR, $className) . '.php';

        if (file_exists($path)) {
            include $path;
        }
    }
}
