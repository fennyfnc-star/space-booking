<?php

/**
 * Base class for update checkers.
 */

namespace YahnisElsts\PluginUpdateChecker\v5;

abstract class PucBase
{
    public $slug;
    public $version = '';
    public $minWpVersion = '';
    public $updateCheckErrors = array();

    protected $optionName;
    protected $pluginFile;
    protected $debugMode = false;

    public function __construct($pluginFile, $slug, $customOptions = array())
    {
        $this->pluginFile = $pluginFile;
        $this->slug = $slug;
        $this->optionName = $this->getOptionName();
        $this->throttle = 12 * HOUR_IN_SECONDS;
        $this->addHooks();
    }

    abstract protected function requestInfo();

    public function checkForUpdates()
    {
        $installedVersion = $this->getInstalledVersion();
        $state = $this->getStateFromStorage();
        $state->lastCheck = time();

        if (empty($state->lastCheck) || (time() - $state->lastCheck) > $this->throttle) {
            $apiResponse = $this->requestInfo();
            if (!empty($apiResponse) && isset($apiResponse->version) && version_compare($installedVersion, $apiResponse->version, '<')) {
                $state->update = $apiResponse;
            } else {
                $state->update = null;
            }
            $this->setStateInStorage($state);
        }

        return $state->update;
    }

    // Simplified storage using options for demo; full has more.
    protected function getOptionName()
    {
        return $this->slug . '_update_state';
    }

    protected function getStateFromStorage()
    {
        $state = get_site_option($this->getOptionName(), new \stdClass());
        if (!is_object($state)) {
            $state = new \stdClass();
        }
        $state->lastCheck = isset($state->lastCheck) ? $state->lastCheck : 0;
        $state->update = isset($state->update) ? $state->update : null;
        return $state;
    }

    protected function setStateInStorage($state)
    {
        update_site_option($this->getOptionName(), $state);
    }

    protected function getInstalledVersion()
    {
        if (!function_exists('get_plugin_data')) {
            return '';
        }
        $data = get_plugin_data($this->pluginFile);
        return isset($data['Version']) ? $data['Version'] : '';
    }

    protected function addHooks()
    {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'injectUpdate'));
        add_filter('plugins_api', array($this, 'handlePluginInfoRequest'), 20, 3);
    }

    public function injectUpdate($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $update = $this->checkForUpdates();
        if ($update) {
            $transient->response[$this->pluginFile] = $update;
        }
        return $transient;
    }

    public function handlePluginInfoRequest($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $info = $this->requestInfo();
        if ($info) {
            $result = $this->convertObjectToPluginInfo($info);
        }
        return $result;
    }

    protected function convertObjectToPluginInfo($update)
    {
        $info = new stdClass();
        $info->name = 'Space Booking';
        $info->slug = $this->slug;
        $info->version = $update->version;
        $info->download_link = $update->download_url;
        $info->author = 'Senior WP Architect';
        $info->sections = isset($update->sections) ? (array) $update->sections : array();
        return $info;
    }
}
