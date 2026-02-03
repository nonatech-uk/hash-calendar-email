<?php
/**
 * Settings Page for GH3 Hash Runs Email Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class GH3_Email_Settings {

    private $option_name = 'gh3_email_settings';

    /**
     * Initialize hooks
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    /**
     * Add settings page under Hash Runs menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=hash_run',
            __('Email Gateway Settings', 'gh3-hash-runs-email'),
            __('Email Gateway', 'gh3-hash-runs-email'),
            'manage_options',
            'gh3-email-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting($this->option_name, $this->option_name, array($this, 'sanitize_settings'));

        // API Section
        add_settings_section(
            'gh3_email_api',
            __('API Settings', 'gh3-hash-runs-email'),
            null,
            'gh3-email-settings'
        );

        add_settings_field(
            'anthropic_api_key',
            __('Anthropic API Key', 'gh3-hash-runs-email'),
            array($this, 'render_password_field'),
            'gh3-email-settings',
            'gh3_email_api',
            array('field' => 'anthropic_api_key', 'description' => 'Claude API key for email parsing')
        );

        // Webhook Section
        add_settings_section(
            'gh3_email_webhook',
            __('Webhook Settings', 'gh3-hash-runs-email'),
            array($this, 'render_webhook_url'),
            'gh3-email-settings'
        );

        add_settings_field(
            'webhook_secret',
            __('Webhook Secret', 'gh3-hash-runs-email'),
            array($this, 'render_text_field'),
            'gh3-email-settings',
            'gh3_email_webhook',
            array('field' => 'webhook_secret', 'description' => 'Token appended to webhook URL for authentication')
        );

        add_settings_field(
            'authorised_emails',
            __('Authorised Emails', 'gh3-hash-runs-email'),
            array($this, 'render_textarea_field'),
            'gh3-email-settings',
            'gh3_email_webhook',
            array('field' => 'authorised_emails', 'description' => 'One email address per line. Only these senders can create/update runs.')
        );

        // SMTP Section
        add_settings_section(
            'gh3_email_smtp',
            __('SMTP Settings (ForwardEmail)', 'gh3-hash-runs-email'),
            null,
            'gh3-email-settings'
        );

        add_settings_field(
            'smtp_host',
            __('SMTP Host', 'gh3-hash-runs-email'),
            array($this, 'render_text_field'),
            'gh3-email-settings',
            'gh3_email_smtp',
            array('field' => 'smtp_host', 'placeholder' => 'smtp.forwardemail.net')
        );

        add_settings_field(
            'smtp_port',
            __('SMTP Port', 'gh3-hash-runs-email'),
            array($this, 'render_number_field'),
            'gh3-email-settings',
            'gh3_email_smtp',
            array('field' => 'smtp_port', 'placeholder' => '465')
        );

        add_settings_field(
            'smtp_user',
            __('SMTP Username', 'gh3-hash-runs-email'),
            array($this, 'render_text_field'),
            'gh3-email-settings',
            'gh3_email_smtp',
            array('field' => 'smtp_user', 'placeholder' => 'runs@guildfordh3.org.uk')
        );

        add_settings_field(
            'smtp_password',
            __('SMTP Password', 'gh3-hash-runs-email'),
            array($this, 'render_password_field'),
            'gh3-email-settings',
            'gh3_email_smtp',
            array('field' => 'smtp_password', 'description' => 'ForwardEmail SMTP password')
        );

        add_settings_field(
            'from_email',
            __('From Email', 'gh3-hash-runs-email'),
            array($this, 'render_text_field'),
            'gh3-email-settings',
            'gh3_email_smtp',
            array('field' => 'from_email', 'placeholder' => 'runs@guildfordh3.org.uk')
        );

        add_settings_field(
            'from_name',
            __('From Name', 'gh3-hash-runs-email'),
            array($this, 'render_text_field'),
            'gh3-email-settings',
            'gh3_email_smtp',
            array('field' => 'from_name', 'placeholder' => 'GH3 Hash Runs')
        );
    }

    /**
     * Get settings
     */
    public function get_settings() {
        $defaults = array(
            'anthropic_api_key' => '',
            'webhook_secret'    => '',
            'authorised_emails' => '',
            'smtp_host'         => 'smtp.forwardemail.net',
            'smtp_port'         => 465,
            'smtp_user'         => '',
            'smtp_password'     => '',
            'from_email'        => '',
            'from_name'         => 'GH3 Hash Runs',
        );

        return wp_parse_args(get_option($this->option_name, array()), $defaults);
    }

    /**
     * Sanitize settings on save
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        $sanitized['anthropic_api_key'] = sanitize_text_field($input['anthropic_api_key'] ?? '');
        $sanitized['webhook_secret']    = sanitize_text_field($input['webhook_secret'] ?? '');
        $sanitized['authorised_emails'] = sanitize_textarea_field($input['authorised_emails'] ?? '');
        $sanitized['smtp_host']         = sanitize_text_field($input['smtp_host'] ?? '');
        $sanitized['smtp_port']         = absint($input['smtp_port'] ?? 465);
        $sanitized['smtp_user']         = sanitize_text_field($input['smtp_user'] ?? '');
        $sanitized['smtp_password']     = sanitize_text_field($input['smtp_password'] ?? '');
        $sanitized['from_email']        = sanitize_email($input['from_email'] ?? '');
        $sanitized['from_name']         = sanitize_text_field($input['from_name'] ?? '');

        // Auto-generate webhook secret if empty
        if (empty($sanitized['webhook_secret'])) {
            $sanitized['webhook_secret'] = wp_generate_password(32, false);
        }

        return $sanitized;
    }

    /**
     * Get authorised emails as array
     */
    public function get_authorised_emails() {
        $settings = $this->get_settings();
        $emails = array_filter(array_map('trim', explode("\n", $settings['authorised_emails'])));
        return array_map('strtolower', $emails);
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'hash_run_page_gh3-email-settings') {
            return;
        }

        wp_enqueue_style(
            'gh3-email-admin-css',
            GH3_EMAIL_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            GH3_EMAIL_VERSION
        );
    }

    /**
     * Display webhook URL
     */
    public function render_webhook_url() {
        $settings = $this->get_settings();
        $secret = $settings['webhook_secret'];

        if ($secret) {
            $url = rest_url('gh3-email/v1/incoming') . '?token=' . $secret;
            echo '<div class="gh3-email-webhook-url">';
            echo '<strong>' . esc_html__('Webhook URL:', 'gh3-hash-runs-email') . '</strong><br>';
            echo '<code>' . esc_html($url) . '</code>';
            echo '<p class="description">' . esc_html__('Configure ForwardEmail.net to POST to this URL.', 'gh3-hash-runs-email') . '</p>';
            echo '</div>';
        } else {
            echo '<p class="description">' . esc_html__('Save settings to generate the webhook URL.', 'gh3-hash-runs-email') . '</p>';
        }
    }

    /**
     * Render text input field
     */
    public function render_text_field($args) {
        $settings = $this->get_settings();
        $field = $args['field'];
        $value = $settings[$field] ?? '';
        $placeholder = $args['placeholder'] ?? '';
        $description = $args['description'] ?? '';

        printf(
            '<input type="text" name="%s[%s]" value="%s" placeholder="%s" class="regular-text">',
            esc_attr($this->option_name),
            esc_attr($field),
            esc_attr($value),
            esc_attr($placeholder)
        );

        if ($description) {
            printf('<p class="description">%s</p>', esc_html($description));
        }
    }

    /**
     * Render password input field
     */
    public function render_password_field($args) {
        $settings = $this->get_settings();
        $field = $args['field'];
        $value = $settings[$field] ?? '';
        $description = $args['description'] ?? '';

        printf(
            '<input type="password" name="%s[%s]" value="%s" class="regular-text">',
            esc_attr($this->option_name),
            esc_attr($field),
            esc_attr($value)
        );

        if ($description) {
            printf('<p class="description">%s</p>', esc_html($description));
        }
    }

    /**
     * Render number input field
     */
    public function render_number_field($args) {
        $settings = $this->get_settings();
        $field = $args['field'];
        $value = $settings[$field] ?? '';
        $placeholder = $args['placeholder'] ?? '';

        printf(
            '<input type="number" name="%s[%s]" value="%s" placeholder="%s" class="small-text">',
            esc_attr($this->option_name),
            esc_attr($field),
            esc_attr($value),
            esc_attr($placeholder)
        );
    }

    /**
     * Render textarea field
     */
    public function render_textarea_field($args) {
        $settings = $this->get_settings();
        $field = $args['field'];
        $value = $settings[$field] ?? '';
        $description = $args['description'] ?? '';

        printf(
            '<textarea name="%s[%s]" rows="5" class="large-text">%s</textarea>',
            esc_attr($this->option_name),
            esc_attr($field),
            esc_textarea($value)
        );

        if ($description) {
            printf('<p class="description">%s</p>', esc_html($description));
        }
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap gh3-email-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('gh3-email-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
