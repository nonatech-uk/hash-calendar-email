<?php
/**
 * GitHub Plugin Updater for GH3 Hash Runs Email Gateway
 *
 * Checks GitHub releases for new versions and integrates
 * with the WordPress plugin update system.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GH3_Email_Updater {

    private $slug;
    private $plugin_file;
    private $github_user;
    private $github_repo;
    private $plugin_data;
    private $github_response;

    /**
     * Constructor
     */
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->slug        = plugin_basename($plugin_file);
        $this->github_user = 'nonatech-uk';
        $this->github_repo = 'hash-calendar-email';
    }

    /**
     * Initialize hooks
     */
    public function init() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    /**
     * Get plugin data from header
     */
    private function get_plugin_data() {
        if (empty($this->plugin_data)) {
            $this->plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->slug);
        }
        return $this->plugin_data;
    }

    /**
     * Fetch latest release info from GitHub
     */
    private function get_github_release() {
        if (!empty($this->github_response)) {
            return $this->github_response;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $this->github_response = json_decode($body);

        return $this->github_response;
    }

    /**
     * Check for plugin updates
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $transient;
        }

        $plugin_data = $this->get_plugin_data();
        $github_version = ltrim($release->tag_name, 'v');

        if (version_compare($github_version, $plugin_data['Version'], '>')) {
            $download_url = $release->zipball_url;

            if (!empty($release->assets)) {
                foreach ($release->assets as $asset) {
                    if (substr($asset->name, -4) === '.zip') {
                        $download_url = $asset->browser_download_url;
                        break;
                    }
                }
            }

            $transient->response[$this->slug] = (object) array(
                'slug'        => dirname($this->slug),
                'new_version' => $github_version,
                'url'         => $release->html_url,
                'package'     => $download_url,
            );
        }

        return $transient;
    }

    /**
     * Provide plugin info for the WordPress plugin details popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if ($args->slug !== dirname($this->slug)) {
            return $result;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $result;
        }

        $plugin_data = $this->get_plugin_data();

        $result = (object) array(
            'name'          => $plugin_data['Name'],
            'slug'          => dirname($this->slug),
            'version'       => ltrim($release->tag_name, 'v'),
            'author'        => $plugin_data['AuthorName'],
            'homepage'      => $plugin_data['PluginURI'],
            'requires'      => '5.0',
            'tested'        => '6.7',
            'downloaded'    => 0,
            'last_updated'  => $release->published_at,
            'sections'      => array(
                'description'  => $plugin_data['Description'],
                'changelog'    => nl2br($release->body),
            ),
            'download_link' => $release->zipball_url,
        );

        return $result;
    }

    /**
     * Rename the extracted folder to match the plugin slug after install
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->slug) {
            return $result;
        }

        $proper_destination = WP_PLUGIN_DIR . '/' . dirname($this->slug);
        $wp_filesystem->move($result['destination'], $proper_destination);
        $result['destination'] = $proper_destination;

        activate_plugin($this->slug);

        return $result;
    }
}
