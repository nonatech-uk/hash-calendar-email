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
            $this->log('Invalid webhook token');
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

        $this->log('From=' . $from . ' Subject=' . $subject);

        if (empty($from)) {
            $this->log('No sender email found in payload');
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        // Check sender is authorised
        $settings_obj = new GH3_Email_Settings();
        $authorised = $settings_obj->get_authorised_emails();

        if (!in_array(strtolower($from), $authorised, true)) {
            $this->log('Unauthorised sender: ' . $from);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        // Handle special commands
        $subject_trimmed = strtolower(trim($subject));

        if ($subject_trimmed === 'help') {
            $this->log('Help requested by ' . $from);
            $this->send_help_email($from, $settings);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        if ($subject_trimmed === 'export') {
            $this->log('Export requested by ' . $from);
            $this->send_export_email($from, $settings);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        if ($subject_trimmed === 'import') {
            $this->log('Import requested by ' . $from);
            $this->handle_import($from, $body, $settings);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        // Parse email with Claude
        $parser = new GH3_Email_Parser();
        $parsed = $parser->parse_email($subject, $email_body);

        if (is_wp_error($parsed)) {
            $this->log('Parse error: ' . $parsed->get_error_message());
            $this->send_error_email($from, $parsed->get_error_message(), $settings);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        $this->log('Parsed: ' . wp_json_encode($parsed));

        // Process parsed data into hash_run post
        $processor = new GH3_Email_Processor();
        $result = $processor->process($parsed, $from);

        if (is_wp_error($result)) {
            $this->log('Process error: ' . $result->get_error_message());
            $this->send_error_email($from, $result->get_error_message(), $settings);
            return new WP_REST_Response(array('status' => 'ok'), 200);
        }

        $this->log('Result: ' . $result['action'] . ' post #' . $result['post_id'] . ' "' . $result['title'] . '"');

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
        $run_number = $result['fields_set']['run_number'] ?? '';

        // Subject and heading: include run number if present
        $full_title = $run_number ? 'Run #' . $run_number . ' - ' . $title : $title;
        $subject = 'GH3: ' . $full_title . ' ' . $action;

        $lines = array();
        $lines[] = '"' . $full_title . '" has been ' . $action . '.';
        $lines[] = '';

        $field_labels = array(
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
     * Send CSV export of all hash runs
     */
    private function send_export_email($to, $settings) {
        $fields = array(
            'run_number' => '_gh3_run_number',
            'title'      => '_post_title',
            'run_date'   => '_gh3_run_date',
            'start_time' => '_gh3_start_time',
            'hares'      => '_gh3_hares',
            'location'   => '_gh3_location',
            'what3words' => '_gh3_what3words',
            'maps_url'   => '_gh3_maps_url',
            'oninn'      => '_gh3_oninn',
            'notes'      => '_gh3_notes',
        );

        $posts = get_posts(array(
            'post_type'   => 'hash_run',
            'post_status' => 'any',
            'numberposts' => -1,
            'meta_key'    => '_gh3_run_date',
            'orderby'     => 'meta_value',
            'order'       => 'ASC',
        ));

        // Build CSV
        $csv_file = tempnam(sys_get_temp_dir(), 'gh3_export_');
        $fp = fopen($csv_file, 'w');
        fputcsv($fp, array_keys($fields));

        foreach ($posts as $post) {
            $row = array();
            foreach ($fields as $key => $meta_key) {
                if ($key === 'title') {
                    $row[] = $post->post_title;
                } else {
                    $row[] = get_post_meta($post->ID, $meta_key, true);
                }
            }
            fputcsv($fp, $row);
        }
        fclose($fp);

        $csv_path = $csv_file . '.csv';
        rename($csv_file, $csv_path);

        $count = count($posts);
        $subject = 'GH3: Hash Runs Export (' . $count . ' runs)';
        $body = 'Attached is a CSV export of all ' . $count . ' hash runs.';

        $this->send_email($to, $subject, $body, $settings, false, array($csv_path));

        @unlink($csv_path);
    }

    /**
     * Handle CSV import from email attachment
     */
    private function handle_import($to, $payload, $settings) {
        $attachments = $payload['attachments'] ?? array();

        if (empty($attachments)) {
            $this->log('Import: No attachments found');
            $this->send_error_email($to, 'No CSV file attached. Please attach a CSV file and resend.', $settings);
            return;
        }

        // Find the CSV attachment
        $csv_content = null;
        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'] ?? '';
            $content_type = $attachment['contentType'] ?? $attachment['type'] ?? '';

            if (
                substr($filename, -4) === '.csv' ||
                strpos($content_type, 'csv') !== false ||
                strpos($content_type, 'text/plain') !== false
            ) {
                $csv_content = $this->extract_attachment_content($attachment);
                break;
            }
        }

        if (empty($csv_content)) {
            $this->log('Import: No CSV attachment found in ' . count($attachments) . ' attachments');
            $this->send_error_email($to, 'No CSV file found in attachments. Please attach a .csv file.', $settings);
            return;
        }

        // Parse CSV
        $lines = array_filter(explode("\n", $csv_content));
        if (count($lines) < 2) {
            $this->send_error_email($to, 'CSV file is empty or has no data rows.', $settings);
            return;
        }

        // Parse header row
        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', array_map('strtolower', $header));

        $valid_fields = array('run_number', 'title', 'run_date', 'start_time', 'hares', 'location', 'what3words', 'maps_url', 'oninn', 'notes');
        $field_indices = array();
        foreach ($valid_fields as $field) {
            $index = array_search($field, $header);
            if ($index !== false) {
                $field_indices[$field] = $index;
            }
        }

        if (empty($field_indices)) {
            $this->send_error_email($to, 'CSV header not recognised. Expected columns: ' . implode(', ', $valid_fields), $settings);
            return;
        }

        // Process rows
        $processor = new GH3_Email_Processor();
        $created_list = array();
        $updated_list = array();
        $skipped = 0;
        $errors = array();

        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $values = str_getcsv($line);
            $parsed_data = array();

            foreach ($field_indices as $field => $index) {
                if (isset($values[$index]) && $values[$index] !== '') {
                    $parsed_data[$field] = trim($values[$index]);
                }
            }

            if (empty($parsed_data['run_date']) && empty($parsed_data['run_number'])) {
                $errors[] = 'Row ' . ($line_num + 2) . ': no date or run number';
                continue;
            }

            $result = $processor->process($parsed_data, $to);

            if (is_wp_error($result)) {
                $errors[] = 'Row ' . ($line_num + 2) . ': ' . $result->get_error_message();
            } elseif ($result['action'] === 'created') {
                $created_list[] = $result;
            } elseif (!empty($result['fields_changed'])) {
                $updated_list[] = $result;
            } else {
                $skipped++;
            }
        }

        // Send summary
        $subject = 'GH3: Import complete';
        $lines = array();
        $lines[] = 'CSV import complete.';
        $lines[] = '';
        $lines[] = 'Created: ' . count($created_list);
        $lines[] = 'Updated: ' . count($updated_list);
        $lines[] = 'Unchanged: ' . $skipped;

        if (!empty($created_list)) {
            $lines[] = '';
            $lines[] = '--- Created ---';
            foreach ($created_list as $r) {
                $lines[] = '  ' . $r['title'] . ' (' . ($r['fields_set']['run_date'] ?? '') . ')';
            }
        }

        if (!empty($updated_list)) {
            $lines[] = '';
            $lines[] = '--- Updated ---';
            foreach ($updated_list as $r) {
                $lines[] = '  ' . $r['title'] . ': ' . implode(', ', $r['fields_changed']);
            }
        }

        if (!empty($errors)) {
            $lines[] = '';
            $lines[] = '--- Errors ---';
            foreach ($errors as $error) {
                $lines[] = '  ' . $error;
            }
        }

        $this->log('Import: created=' . count($created_list) . ' updated=' . count($updated_list) . ' unchanged=' . $skipped . ' errors=' . count($errors));
        $this->send_email($to, $subject, implode("\n", $lines), $settings);
    }

    /**
     * Extract content from a ForwardEmail attachment
     *
     * simpleParser may encode content as:
     * - Buffer: {"type": "Buffer", "data": [byte, byte, ...]}
     * - Base64 string
     * - Plain string
     */
    private function extract_attachment_content($attachment) {
        $content = $attachment['content'] ?? '';

        // Buffer format: {"type": "Buffer", "data": [...]}
        if (is_array($content) && isset($content['type']) && $content['type'] === 'Buffer' && isset($content['data'])) {
            $bytes = $content['data'];
            $str = '';
            foreach ($bytes as $byte) {
                $str .= chr($byte);
            }
            return $str;
        }

        // Base64 encoded string
        if (is_string($content) && preg_match('/^[A-Za-z0-9+\/=]+$/', $content) && strlen($content) > 20) {
            $decoded = base64_decode($content, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        // Plain string
        if (is_string($content)) {
            return $content;
        }

        return '';
    }

    /**
     * Send help email with usage instructions
     */
    private function send_help_email($to, $settings) {
        $from_email = $settings['from_email'] ?: $settings['smtp_user'];
        $subject = 'GH3: Email Gateway Help';

        $html = '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

<h2 style="color: #2271b1; margin-top: 0;">GH3 Hash Runs &ndash; Email Gateway</h2>

<p>Send an email to <strong>' . esc_html($from_email) . '</strong> to create or update a hash run. Just write naturally &ndash; the details will be extracted automatically.</p>

<h3 style="color: #2271b1;">Creating a new run</h3>
<p>Include at least a <strong>date</strong>. Everything else is optional.</p>

<div style="background: #f0f6fc; border: 1px solid #c8d6e5; border-radius: 6px; padding: 16px; margin: 12px 0;">
<strong>Example:</strong><br><br>
<em>Subject:</em> Next Monday\'s run<br><br>
Run 2120, hare is Speedy, next Monday at the Cricket Ground Shere.<br>
On Inn: The William Bray<br>
///happy.running.trail
</div>

<h3 style="color: #2271b1;">Updating an existing run</h3>
<p>Include the <strong>run number</strong> and only the fields you want to change. No date needed for updates.</p>

<div style="background: #f0f6fc; border: 1px solid #c8d6e5; border-radius: 6px; padding: 16px; margin: 12px 0;">
<strong>Example:</strong><br><br>
<em>Subject:</em> Run 2120 update<br><br>
Run 2120 &ndash; start time 11am, note: wear fancy dress
</div>

<h3 style="color: #2271b1;">Supported fields</h3>
<table style="border-collapse: collapse; width: 100%; margin: 12px 0;">
<tr style="background: #f0f6fc;">
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Run number</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">e.g. Run 2120</td>
</tr>
<tr>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Date</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Any format &ndash; &ldquo;next Monday&rdquo;, &ldquo;15th March&rdquo;, &ldquo;2026-03-15&rdquo;</td>
</tr>
<tr style="background: #f0f6fc;">
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Start time</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Defaults to 19:30 if not specified</td>
</tr>
<tr>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Hare(s)</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Who is laying the trail</td>
</tr>
<tr style="background: #f0f6fc;">
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Location</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Start location</td>
</tr>
<tr>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>What3Words</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">e.g. ///happy.running.trail</td>
</tr>
<tr style="background: #f0f6fc;">
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Google Maps link</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Paste a maps URL</td>
</tr>
<tr>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>On Inn</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Pub or venue after the run</td>
</tr>
<tr style="background: #f0f6fc;">
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Notes</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Any other info</td>
</tr>
</table>

<h3 style="color: #2271b1;">Bulk export &amp; import</h3>
<table style="border-collapse: collapse; width: 100%; margin: 12px 0;">
<tr style="background: #f0f6fc;">
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Export</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Send an email with subject <strong>Export</strong> to receive a CSV of all runs</td>
</tr>
<tr>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;"><strong>Import</strong></td>
    <td style="padding: 8px 12px; border: 1px solid #c8d6e5;">Send an email with subject <strong>Import</strong> and attach a CSV file. Use the same column headers as the export.</td>
</tr>
</table>

<h3 style="color: #2271b1;">Tips</h3>
<ul style="padding-left: 20px;">
<li>You\'ll get a confirmation email with a link to the published run</li>
<li>Write in any style &ndash; the AI will figure out the details</li>
<li>To update a run, always include the run number</li>
<li>Send an email with subject <strong>Help</strong> to see this message again</li>
</ul>

<hr style="border: none; border-top: 1px solid #ddd; margin: 24px 0;">
<p style="color: #888; font-size: 13px;">GH3 Hash Runs Email Gateway &ndash; <a href="https://guildfordh3.org.uk" style="color: #2271b1;">guildfordh3.org.uk</a></p>

</body>
</html>';

        $this->send_email($to, $subject, $html, $settings, true);
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
    private function send_email($to, $subject, $body, $settings, $is_html = false, $attachments = array()) {
        // Only configure SMTP if settings are present
        if (empty($settings['smtp_host']) || empty($settings['smtp_user'])) {
            $this->log('SMTP not configured, skipping confirmation email');
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

        $headers = array();
        if ($is_html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }

        add_action('phpmailer_init', $smtp_config);
        $sent = wp_mail($to, $subject, $body, $headers, $attachments);
        remove_action('phpmailer_init', $smtp_config);

        if (!$sent) {
            $this->log('Failed to send email to ' . $to);
        }
    }

    /**
     * Log to plugin-specific file
     */
    private function log($message) {
        $log_file = WP_CONTENT_DIR . '/gh3-email-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
}
