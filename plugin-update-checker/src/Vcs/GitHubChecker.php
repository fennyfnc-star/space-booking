<?php

/**
 * GitHub Updater.
 */

namespace YahnisElsts\PluginUpdateChecker\v5\Vcs;

use YahnisElsts\PluginUpdateChecker\v5\PucBase;
use stdClass;

class GitHubChecker extends PucBase
{
    protected $apiUrl;
    protected $user;
    protected $repo;
    protected $branch = 'main';

    public function __construct($ghRepo, $pluginFile, $slug, $options = array())
    {
        parent::__construct($pluginFile, $slug, $options);

        $urlParts = parse_url($ghRepo);
        $this->user = $urlParts['path'][1];
        $this->repo = trim($urlParts['path'], '/');
        $this->apiUrl = 'https://api.github.com/repos/' . $this->user . '/' . $this->repo . '/releases/latest';

        if (isset($options['branch'])) {
            $this->branch = $options['branch'];
        }
    }

    protected function requestInfo()
    {
        $response = wp_remote_get($this->apiUrl, array(
            'timeout' => 10,
            'headers' => array('Accept' => 'application/vnd.github.v3+json')
        ));

        if (is_wp_error($response)) {
            $this->updateCheckErrors[] = $response->get_error_message();
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data) || isset($data->message)) {
            $this->updateCheckErrors[] = 'GitHub API error';
            return null;
        }

        $update = new stdClass();
        $update->version = $data->tag_name;
        $update->download_url = $data->zipball_url;
        $update->sections = array('description' => $data->body);
        $update->tested = $this->getTestedVersion($data);
        $update->requires = $this->getMinWpVersion($data);
        $update->slug = $this->slug;

        return $update;
    }

    // Simplified; full version extracts readme.txt from repo.
    protected function getTestedVersion($release)
    {
        return '';  // Or parse from repo
    }

    protected function getMinWpVersion($release)
    {
        return '';
    }

    public function setBranch($branch)
    {
        $this->branch = $branch;
        $this->apiUrl = sprintf('https://api.github.com/repos/%s/%s/releases/tags/%s', $this->user, $this->repo, $this->branch);
        return $this;
    }
}
