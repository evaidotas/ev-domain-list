<?php
/*
Plugin Name: Domain List/Rank Plugin
Description: A plugin to list domains and their PageRank from OpenPageRank API.
Version: 0.1
Author: Eimantas Vaidotas
*/

if (!defined('ABSPATH')) {
    exit;
}

class EV_Domain_List {
    private $api_key_option_name = 'dlp_api_key';
    private $json_url = 'https://raw.githubusercontent.com/Kikobeats/top-sites/master/top-sites.json';

    public function __construct() {
        add_action('admin_menu', array($this, 'create_admin_page'));
        add_action('wp_ajax_fetch_domains', array($this, 'fetch_domains'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function create_admin_page() {
        add_menu_page('Domain List', 'Domain List', 'manage_options', 'domain-list', array($this, 'admin_page_html'));
    }

    public function admin_page_html() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Domain List', 'ev-domain-rank'); ?></h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('dlp_settings_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('OpenPageRank API key', 'ev-domain-rank'); ?></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->api_key_option_name); ?>" value="<?php echo esc_attr(get_option($this->api_key_option_name)); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <div class="search-form">
                <input type="text" id="domain-search" placeholder="<?php esc_html_e('Search by domain name', 'ev-domain-rank'); ?>">
                <button id="search-btn"><?php esc_html_e('Search', 'ev-domain-rank'); ?></button>
            </div>
            <div id="domain-list"></div>
        </div>
        <script type="text/javascript">
          jQuery(document).ready(function($) {
            let currentPage = 1;
            fetchDomains(currentPage);

            function fetchDomains(page, searchTerm = '') {
              $.post(ajaxurl, { action: 'fetch_domains', page: page, search: searchTerm }, function(response) {
                if (response.success) {
                  $('#domain-list').html(response.data);

                  $('.pagination a').click(function(e) {
                    e.preventDefault();
                    let newPage = $(this).data('page');
                    fetchDomains(newPage, $('#domain-search').val());
                  });

                  $('#search-btn').click(function(e) {
                    e.preventDefault();
                    fetchDomains(1, $('#domain-search').val());
                  });
                } else {
                  $('#domain-list').html('<p>Error fetching domains: ' + response.data + '</p>');
                }
              });
            }

            function initialFetch() {
              fetchDomains(currentPage, $('#domain-search').val());
            }

            initialFetch();

            $('#domain-search').keypress(function(e) {
              if (e.which == 13) {
                fetchDomains(1, $(this).val());
              }
            });
          });
        </script>
        <?php
    }

    public function fetch_domains() {
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        $response = wp_remote_get($this->json_url);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch JSON file.');
            return;
        }

        $domains = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Error decoding JSON: ' . json_last_error_msg());
            return;
        }

        if (!empty($search)) {
            $domains = array_filter($domains, function($domain) use ($search) {
                return strpos($domain['rootDomain'], $search) !== false;
            });
        }

        $domain_names = array_map(function($domain) {
            return $domain['rootDomain'];
        }, $domains);

        $per_page = 100;
        $offset = ($page - 1) * $per_page;
        $paged_domains = array_slice($domain_names, $offset, $per_page);

        $paged_domains_with_rank = $this->get_page_rank($paged_domains);

        if (is_wp_error($paged_domains_with_rank)) {
            wp_send_json_error('Failed to fetch PageRank data.');
            return;
        }

        ob_start();
        ?>
        <table>
            <thead>
            <tr>
                <th><?php esc_html_e('Domain', 'ev-domain-rank'); ?></th>
                <th><?php esc_html_e('Page Rank', 'ev-domain-rank'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($paged_domains_with_rank as $data) : ?>
                <tr>
                    <td><?php echo esc_html($data['domain']); ?></td>
                    <td><?php echo esc_html($data['page_rank']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pagination">
            <?php
            $total_pages = ceil(count($domain_names) / $per_page);
            for ($i = 1; $i <= $total_pages; $i++) :
                ?>
                <a href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php
        $output = ob_get_clean();
        wp_send_json_success($output);
    }

    private function get_page_rank($domains) {
        $url = 'https://openpagerank.com/api/v1.0/getPageRank';
        $chunks = array_chunk($domains, 100);

        $results = [];
        foreach ($chunks as $chunk) {
            $query = http_build_query(array('domains' => $chunk));
            $full_url = $url . '?' . $query;

            $ch = curl_init();
            $headers = ['API-OPR: ' . get_option($this->api_key_option_name)];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_URL, $full_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $output = curl_exec($ch);
            curl_close($ch);

            if ($output === false) {
                error_log('cURL error: ' . curl_error($ch));
                return new WP_Error('curl_error', 'Failed to fetch data from OpenPageRank API.');
            }

            $output = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON decode error: ' . json_last_error_msg());
                return new WP_Error('json_error', 'Failed to decode JSON response.');
            }

            if (!isset($output['response']) || !is_array($output['response'])) {
                return new WP_Error('invalid_response', 'Invalid API response.');
            }

            foreach ($output['response'] as $response) {
                if (is_array($response)) {
                    $results[] = [
                        'domain' => $response['domain'],
                        'page_rank' => $response['page_rank_integer'] ?? 'N/A'
                    ];
                } else {
                    error_log('Invalid response format: ' . print_r($response, true));
                }
            }
        }

        return $results;
    }

    public function register_settings() {
        register_setting('dlp_settings_group', $this->api_key_option_name, 'sanitize_text_field');
    }
}

new EV_Domain_List();
