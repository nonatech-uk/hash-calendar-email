<?php
/**
 * REST API Webhook Endpoint for incoming emails
 */

if (!defined('ABSPATH')) {
    exit;
}

class GH3_Email_Webhook {

    /**
     * Initialize hooks
     */
    public function init() {
        add_action('rest_api_init', array($this, 'register_route'));
    }

    /**
     * Register REST route
     */
    public function register_route() {
        register_rest_route('gh3-email/v1', '/incoming', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_incoming'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handle incoming webhook from ForwardEmail.net
     */
    public function handle_incoming($request) {
        // Validate webhook token
        $token = $request->get_param('token');
        $settings = (new GH3_Email_Settings())->get_settings();

        if (empty($settings['webhook_secret']) || !hash_equals($settings['webhook_secret'], $token ?? '')) {
            error_log('GH3 Email Gateway: Invalid webhook token');
            return new WP_REST_Response(array('error' => 'Forbidden'), 403);
        }

        // Extract email data from ForwardEmail.net payload
        $body = $request->get_json_params();

        // ForwardEmail sends sender info in various formats
        $from = $this->extract_sender_email($body);
        $subject = sanitize_text_field($body['subject'] ?? '');
        $text = $body['text'] ?? '';
        $html = $body['html'] ?? '';

        // Use plain text body, fall back to stripped HTML
        $email_body = $text;
        if (empty($email_body) && !empty($html)) {
            $email_body = wp_strip_all_tags($html);
        }

        if (empty($from)) {
            error_log('GH3 Email Gateway: No sender email found in payload');
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        // Check sender is authorised
        $settings_obj = new GH3_Email_Settings();
        $authorised = $settings_obj->get_authorised_emails();

        if (!in_array(strtolower($from), $authorised, true)) {
            error_log('GH3 Email Gateway: Unauthorised sender: ' . $from);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        // Parse email with Claude
        $parser = new GH3_Email_Parser();
        $parsed = $parser->parse_email($subject, $email_body);

        if (is_wp_error($parsed)) {
            error_log('GH3 Email Gateway: Parse error - ' . $parsed->get_error_message());
            $this->send_error_email($from, $parsed->get_error_message(), $settings);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        // Process parsed data into hash_run post
        $processor = new GH3_Email_Processor();
        $result = $processor->process($parsed, $from);

        if (is_wp_error($result)) {
            error_log('GH3 Email Gateway: Process error - ' . $result->get_error_message());
            $this->send_error_email($from, $result->get_error_message(), $settings);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        // Send confirmation email
        $this->send_confirmation_email($from, $result, $settings);

        return new WP_REST_Response(array('status' => 'ok'), 200);
    }

    /**
     * Extract sender email address from ForwardEmail payload
     *
     * ForwardEmail uses mailparser's simpleParser which outputs from as:
     * { "value": [{"address": "user@example.com", "name": "User"}], "text": "User <user@example.com>" }
     */
    private function extract_sender_email($body) {
        $from = $body['from'] ?? '';

        // simpleParser object format: { value: [{address, name}], text: "..." }
        if (is_array($from)) {
            // from.value[0].address (standard simpleParser format)
            if (isset($from['value'][0]['address'])) {
                return sanitize_email($from['value'][0]['address']);
            }
            // from.address (flat object)
            if (isset($from['address'])) {
                return sanitize_email($from['address']);
            }
            // Array of senders
            if (isset($from[0]['address'])) {
                return sanitize_email($from[0]['address']);
            }
            // from.text fallback - parse email from "Name <email>" string
            if (isset($from['text']) && preg_match('/<([^>]+)>/', $from['text'], $matches)) {
                return sanitize_email($matches[1]);
            }
        }

        // String format: "Name <email@example.com>"
        if (is_string($from) && preg_match('/<([^>]+)>/', $from, $matches)) {
            return sanitize_email($matches[1]);
        }

        // Plain email string
        if (is_string($from) && is_email($from)) {
            return sanitize_email($from);
        }

        return '';
    }

    /**
     * Send confirmation email via SMTP
     */
    private function send_confirmation_email($to, $result, $settings) {
        $action = $result['action'] === 'created' ? 'created' : 'updated';
        $title = $result['title'];

        $subject = 'GH3: ' . $title . ' ' . $action;

        $lines = array();
        $lines[] = '"' . $title . '" has been ' . $action . '.';
        $lines[] = '';

        $field_labels = array(
            'run_number' => 'Run #',
            'run_date'   => 'Date',
            'hares'      => 'Hare(s)',
            'location'   => 'Location',
            'start_time' => 'Start Time',
            'oninn'      => 'On Inn',
            'what3words' => 'What3Words',
            'maps_url'   => 'Maps',
            'notes'      => 'Notes',
        );

        foreach ($field_labels as $key => $label) {
            if (!empty($result['fields_set'][$key])) {
                $lines[] = $label . ': ' . $result['fields_set'][$key];
            }
        }

        $lines[] = '';
        $lines[] = 'View: ' . get_permalink($result['post_id']);

        $body = implode("\n", $lines);

        $this->send_email($to, $subject, $body, $settings);
    }

    /**
     * Send error email via SMTP
     */
    private function send_error_email($to, $error_message, $settings) {
        $subject = 'GH3: Email processing error';
        $body = "Your email could not be processed.\n\n" . $error_message . "\n\nPlease try again, making sure to include at least a date for the run.";

        $this->send_email($to, $subject, $body, $settings);
    }

    /**
     * Send email using wp_mail with SMTP configuration
     */
    private function send_email($to, $subject, $body, $settings) {
        // Only configure SMTP if settings are present
        if (empty($settings['smtp_host']) || empty($settings['smtp_user'])) {
            error_log('GH3 Email Gateway: SMTP not configured, skipping confirmation email');
            return;
        }

        // Temporarily hook into phpmailer to configure SMTP
        $smtp_config = function ($phpmailer) use ($settings) {
            $phpmailer->isSMTP();
            $phpmailer->Host       = $settings['smtp_host'];
            $phpmailer->Port       = $settings['smtp_port'] ?: 465;
            $phpmailer->SMTPAuth   = true;
            $phpmailer->Username   = $settings['smtp_user'];
            $phpmailer->Password   = $settings['smtp_password'];
            $phpmailer->SMTPSecure = ($settings['smtp_port'] == 587) ? 'tls' : 'ssl';
            $phpmailer->From       = $settings['from_email'] ?: $settings['smtp_user'];
            $phpmailer->FromName   = $settings['from_name'] ?: 'GH3 Hash Runs';
        };

        add_action('phpmailer_init', $smtp_config);
        $sent = wp_mail($to, $subject, $body);
        remove_action('phpmailer_init', $smtp_config);

        if (!$sent) {
            error_log('GH3 Email Gateway: Failed to send email to ' . $to);
        }
    }
}
