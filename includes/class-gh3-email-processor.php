<?php
/**
 * Processor for creating/updating hash_run posts from parsed email data
 */

if (!defined('ABSPATH')) {
    exit;
}

class GH3_Email_Processor {

    /**
     * Field mapping from parsed data keys to post meta keys
     */
    private $field_map = array(
        'run_number' => '_gh3_run_number',
        'run_date'   => '_gh3_run_date',
        'start_time' => '_gh3_start_time',
        'hares'      => '_gh3_hares',
        'location'   => '_gh3_location',
        'what3words' => '_gh3_what3words',
        'maps_url'   => '_gh3_maps_url',
        'oninn'      => '_gh3_oninn',
        'notes'      => '_gh3_notes',
    );

    /**
     * Process parsed data into a hash_run post
     *
     * @param array  $parsed_data  Data from Claude parser
     * @param string $sender_email Sender's email address
     * @return array|WP_Error Result array or error
     */
    public function process($parsed_data, $sender_email) {
        $existing_post_id = null;
        $action = 'created';

        // Check for existing run by run_number
        if (!empty($parsed_data['run_number'])) {
            $existing_post_id = $this->find_existing_run($parsed_data['run_number']);
        }

        // Date is required for new runs, optional for updates
        if (empty($parsed_data['run_date']) && !$existing_post_id) {
            return new WP_Error('no_date', 'No date found in parsed data.');
        }

        if ($existing_post_id) {
            // Update existing post - merge new data with existing meta for title
            $action = 'updated';
            $post_id = $existing_post_id;

            $merged = $this->merge_with_existing($post_id, $parsed_data);
            $title = $this->build_title($merged);

            wp_update_post(array(
                'ID'          => $post_id,
                'post_title'  => $title,
                'post_status' => 'publish',
            ));

            // Force publish - WordPress overrides to 'future' for future-dated posts
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'publish'), array('ID' => $post_id));
            clean_post_cache($post_id);
        } else {
            // Create new post
            $title = $this->build_title($parsed_data);
            $post_id = wp_insert_post(array(
                'post_type'   => 'hash_run',
                'post_title'  => $title,
                'post_status' => 'publish',
                'post_content' => '',
            ));

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            // Force publish - WordPress overrides to 'future' for future-dated posts
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'publish'), array('ID' => $post_id));
            clean_post_cache($post_id);
        }

        // Update meta fields (only those present in parsed data)
        $fields_set = array();
        foreach ($this->field_map as $data_key => $meta_key) {
            if (isset($parsed_data[$data_key]) && $parsed_data[$data_key] !== '') {
                $value = $this->sanitize_field($data_key, $parsed_data[$data_key]);
                update_post_meta($post_id, $meta_key, $value);
                $fields_set[$data_key] = $value;
            }
        }

        return array(
            'action'     => $action,
            'post_id'    => $post_id,
            'title'      => $title,
            'fields_set' => $fields_set,
        );
    }

    /**
     * Find existing hash_run by run number
     *
     * @param int $run_number
     * @return int|null Post ID or null
     */
    private function find_existing_run($run_number) {
        $posts = get_posts(array(
            'post_type'  => 'hash_run',
            'meta_key'   => '_gh3_run_number',
            'meta_value' => intval($run_number),
            'numberposts' => 1,
            'post_status' => 'any',
        ));

        return !empty($posts) ? $posts[0]->ID : null;
    }

    /**
     * Merge parsed data with existing post meta so title reflects full state
     */
    private function merge_with_existing($post_id, $parsed_data) {
        $merged = array();
        foreach ($this->field_map as $data_key => $meta_key) {
            $existing = get_post_meta($post_id, $meta_key, true);
            if (isset($parsed_data[$data_key]) && $parsed_data[$data_key] !== '') {
                $merged[$data_key] = $parsed_data[$data_key];
            } elseif ($existing !== '') {
                $merged[$data_key] = $existing;
            }
        }
        return $merged;
    }

    /**
     * Build post title from parsed data
     */
    private function build_title($parsed_data) {
        // Use title from Claude if provided
        if (!empty($parsed_data['title'])) {
            return sanitize_text_field($parsed_data['title']);
        }

        $hares = $parsed_data['hares'] ?? '';
        $location = $parsed_data['location'] ?? '';
        $run_number = $parsed_data['run_number'] ?? 0;

        if ($hares && $location) {
            return sanitize_text_field($hares . ' - ' . $location);
        } elseif ($hares) {
            return sanitize_text_field($hares);
        } elseif ($location) {
            return sanitize_text_field($location);
        } elseif ($run_number) {
            return 'Run #' . intval($run_number);
        }

        return 'Hash Run';
    }

    /**
     * Sanitize a field value based on its type
     */
    private function sanitize_field($key, $value) {
        switch ($key) {
            case 'run_number':
                return intval($value);
            case 'maps_url':
                return esc_url_raw($value);
            case 'notes':
                return sanitize_textarea_field($value);
            default:
                return sanitize_text_field($value);
        }
    }
}
