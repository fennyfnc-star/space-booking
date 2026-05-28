<?php

/**
 * The factory that creates update checkers.
 */

namespace YahnisElsts\PluginUpdateChecker\v5;

class PucFactory
{
    public static function buildUpdateChecker($metadataUrlOrHost, $mainFile, $slug = null, $customOptions = array())
    {
        if (is_string($metadataUrlOrHost)) {
            $host = parse_url($metadataUrlOrHost, PHP_URL_HOST);
            if (empty($slug)) {
                $slug = self::getSlugFromFilePath($mainFile);
            }
            return self::buildGitHubChecker($metadataUrlOrHost, $mainFile, $slug, $customOptions);
        }
    }

    protected static function buildGitHubChecker($repo, $mainFile, $slug = null, $options = array())
    {
        if (empty($slug)) {
            $slug = self::getSlugFromFilePath($mainFile);
        }
        $checker = new Vcs\GitHubChecker($repo, $mainFile, $slug, $options);
        return $checker;
    }

    protected static function getSlugFromFilePath($file)
    {
        $file = wp_normalize_path($file);
        $slug = basename($file, '.php');
        return $slug;
    }
}
