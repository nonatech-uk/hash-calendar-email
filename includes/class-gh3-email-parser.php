<?php
/**
 * Claude AI Email Parser
 *
 * Sends email content to Anthropic Claude API for structured data extraction.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GH3_Email_Parser {

    private $api_url = 'https://api.anthropic.com/v1/messages';
    private $model = 'claude-sonnet-4-20250514';

    /**
     * Parse email content using Claude API
     *
     * @param string $subject Email subject
     * @param string $body    Email body (plain text)
     * @return array|WP_Error Parsed data array or error
     */
    public function parse_email($subject, $body) {
        $settings = (new GH3_Email_Settings())->get_settings();
        $api_key = $settings['anthropic_api_key'];

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'Anthropic API key is not configured.');
        }

        $system_prompt = $this->get_system_prompt();
        $user_message = "Subject: " . $subject . "\n\n" . $body;

        $response = wp_remote_post($this->api_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode(array(
                'model'      => $this->model,
                'max_tokens' => 1024,
                'system'     => $system_prompt,
                'messages'   => array(
                    array(
                        'role'    => 'user',
                        'content' => $user_message,
                    ),
                ),
            )),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'Claude API request failed: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $error_body = wp_remote_retrieve_body($response);
            error_log('GH3 Email Gateway: Claude API error (' . $status_code . '): ' . $error_body);
            return new WP_Error('api_error', 'Claude API returned error (HTTP ' . $status_code . ').');
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($response_body['content'][0]['text'])) {
            return new WP_Error('empty_response', 'Claude API returned empty response.');
        }

        $text = $response_body['content'][0]['text'];

        // Strip any markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('GH3 Email Gateway: Invalid JSON from Claude: ' . $text);
            return new WP_Error('invalid_json', 'Could not parse AI response. Please try rephrasing your email.');
        }

        // Check for error field from Claude
        if (!empty($parsed['error'])) {
            return new WP_Error('parse_error', $parsed['error']);
        }

        // Validate required field
        if (empty($parsed['run_date'])) {
            return new WP_Error('no_date', 'No date found in your email. Please include a date for the run.');
        }

        return $parsed;
    }

    /**
     * Get system prompt for Claude
     */
    private function get_system_prompt() {
        return 'You are a data extraction assistant for a Hash House Harriers running club.
Extract structured data from the email below into JSON with these fields:
- run_number (integer, optional - the hash run number)
- run_date (string, YYYY-MM-DD format, required)
- start_time (string, HH:MM 24hr format, optional - default is 19:30)
- hares (string, optional - the person(s) laying the trail)
- location (string, optional - start location)
- what3words (string, optional - ///word.word.word format)
- maps_url (string, optional - Google Maps URL)
- oninn (string, optional - pub/venue after the run)
- notes (string, optional - any other info)
- title (string, required if none of run_number, hare or location set) if hare,location set use same logic as event plugin else Run #{run number}

Rules:
- Return ONLY valid JSON, no markdown or explanation
- If a field is not mentioned, omit it from the JSON
- run_date is required. If you cannot determine a date, set "error": "No date found"
- Dates can be in any format in the email, convert to YYYY-MM-DD
- Times should be 24hr HH:MM format
- what3words always starts with ///
- For title: if hare and location are both set, use "Hare - Location". If only hare, use "Hare". If only location, use "Location". If run_number set but no hare/location, use "Run #N". Otherwise derive a short title from the email content.';
    }
}
