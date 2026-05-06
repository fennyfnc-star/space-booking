<?php declare(strict_types=1);

namespace SpaceBooking;

use SpaceBooking\Admin\AdminMenu;
use SpaceBooking\Admin\ExtraMetaBox;
use SpaceBooking\Admin\PackageMetaBox;
use SpaceBooking\Admin\SpaceMetaBox;
use SpaceBooking\Controllers\AvailabilityController;
use SpaceBooking\Controllers\BookingController;
use SpaceBooking\Controllers\CartController;
use SpaceBooking\Controllers\ConflictsController;
use SpaceBooking\Controllers\CustomerController;
use SpaceBooking\Controllers\PricingController;
use SpaceBooking\Controllers\SpaceController;
use SpaceBooking\CPT\ExtraCPT;
use SpaceBooking\CPT\PackageCPT;
use SpaceBooking\CPT\SpaceCPT;
use SpaceBooking\Taxonomies\TypeTaxonomy;

/**
 * Main plugin singleton – boots all subsystems.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void
    {
        $this->register_rest_api();
        $this->register_wc_hooks();
        $this->enqueue_assets();
        $this->register_admin();

        $this->init_boot();  // Run directly
        add_action('init', [$this, 'init_boot']);  // Also hook for future requests
    }

    public function init_boot(): void
    {
        $this->load_textdomain();
        $this->register_cpts();
        $this->register_shortcodes();
        $this->register_taxonomies();
    }

    // ── Taxonomies ───────────────────────────────────────────────────────────
    private function register_taxonomies(): void
    {
        (new TypeTaxonomy())->register();
    }

    // ── Text domain ──────────────────────────────────────────────────────────
    private function load_textdomain(): void
    {
        load_plugin_textdomain(
            'space-booking',
            false,
            dirname(plugin_basename(SB_FILE)) . '/languages/'
        );
    }

    // ── CPTs ─────────────────────────────────────────────────────────────────
    private function register_cpts(): void
    {
        (new SpaceCPT())->register();
        (new ExtraCPT())->register();
        (new PackageCPT())->register();
    }

    // ── REST API ─────────────────────────────────────────────────────────────
    private function register_rest_api(): void
    {
        add_action('rest_api_init', static function (): void {
            (new SpaceController())->register_routes();
            (new AvailabilityController())->register_routes();
            (new BookingController())->register_routes();
            (new ConflictsController())->register_routes();
            (new CustomerController())->register_routes();
            (new PricingController())->register_routes();
            (new CartController())->register_routes();
        });
    }

    private function register_wc_hooks(): void
    {
        \SpaceBooking\Integrations\WooCommerceIntegration::init();
    }

    // ── Assets ───────────────────────────────────────────────────────────────
    private function enqueue_assets(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
    }

    public function frontend_assets(): void
    {
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        if (has_shortcode($post->post_content, 'space_booking')) {
            $asset_file = SB_DIR . 'assets/js/booking-app.asset.php';
            $asset = file_exists($asset_file)
                ? require $asset_file
                : ['dependencies' => [], 'version' => SB_VERSION];

            wp_enqueue_style(
                'space-booking-app',
                SB_ASSETS_URL . 'css/styles.css',
                [],
                $asset['version']
            );

            wp_enqueue_script(
                'space-booking-app',
                SB_ASSETS_URL . 'js/booking-app.js',
                $asset['dependencies'],
                $asset['version'],
                true
            );

            wp_localize_script(
                'space-booking-app',
                'sbConfig',
                [
                    'apiBase' => rest_url('space-booking/v1'),
                    'nonce' => wp_create_nonce('wp_rest'),
                    'currency' => get_option('sb_currency', 'USD'),
                    'symbol' => \SpaceBooking\Services\CurrencyService::get_symbol(),
                    'dateFormat' => get_option('date_format', 'Y-m-d'),
                    'bookingPolicy' => get_option('sb_booking_policy', ''),
                ]
            );

            add_filter(
                'script_loader_tag',
                static function (string $tag, string $handle): string {
                    if ('space-booking-app' !== $handle) {
                        return $tag;
                    }
                    if (false !== strpos($tag, 'type=')) {
                        return $tag;
                    }
                    return str_replace('<script ', '<script type="module" ', $tag);
                },
                10,
                2
            );
        }

        if (has_shortcode($post->post_content, 'space_booking_lookup')) {
            $asset_file = SB_DIR . 'assets/js/lookup-app.asset.php';
            $asset = file_exists($asset_file)
                ? require $asset_file
                : ['dependencies' => [], 'version' => SB_VERSION];

            wp_enqueue_style(
                'sb-lookup-app',
                SB_ASSETS_URL . 'css/styles.css',
                [],
                $asset['version']
            );

            wp_enqueue_script(
                'sb-lookup-app',
                SB_ASSETS_URL . 'js/lookup-app.js',
                $asset['dependencies'],
                $asset['version'],
                true
            );

            wp_localize_script(
                'sb-lookup-app',
                'sbConfig',
                [
                    'apiBase' => rest_url('space-booking/v1'),
                    'nonce' => wp_create_nonce('wp_rest'),
                    'dateFormat' => get_option('date_format', 'Y-m-d'),
                ]
            );

            add_filter(
                'script_loader_tag',
                static function (string $tag, string $handle): string {
                    if ('sb-lookup-app' !== $handle) {
                        return $tag;
                    }
                    if (false !== strpos($tag, 'type=')) {
                        return $tag;
                    }
                    return str_replace('<script ', '<script type="module" ', $tag);
                },
                10,
                2
            );
        }
    }

    // ── Shortcodes ───────────────────────────────────────────────────────────
    private function register_shortcodes(): void
    {
        add_shortcode('space_booking', [$this, 'render_booking_app']);
        add_shortcode('space_booking_lookup', [$this, 'render_lookup_app']);
        add_action('init', [$this, 'add_rewrite_rules']);
    }

    public function add_rewrite_rules(): void
    {
        add_rewrite_rule('^booking-confirmation/?$', 'index.php?pagename=booking-confirmation', 'top');
    }

    public function render_booking_app(array $atts): string
    {
        $atts = shortcode_atts([
            'space_id' => '',
            'package_id' => '',
        ], $atts, 'space_booking');

        $data_attrs = '';
        if ($atts['space_id']) {
            $data_attrs .= ' data-space-id="' . esc_attr($atts['space_id']) . '"';
        }
        if ($atts['package_id']) {
            $data_attrs .= ' data-package-id="' . esc_attr($atts['package_id']) . '"';
        }

        // Check for direct booking confirmation via query params
        $booking_id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        if ($booking_id > 0 && $status === 'in_review') {
            $repo = new \SpaceBooking\Services\BookingRepository();
            $booking = $repo->find($booking_id);

            if ($booking && $booking['status'] === 'in_review') {
                $data_attrs .= ' data-booking-id="' . esc_attr($booking_id) . '" data-direct-step="7"';
            }
        }

        return '<div id="sb-booking-app"' . $data_attrs . '></div>';
    }

    public function render_lookup_app(): string
    {
        return '<div id="sb-lookup-app"></div>';
    }

    // ── Admin ────────────────────────────────────────────────────────────────
    private function register_admin(): void
    {
        if (!is_admin()) {
            return;
        }
        (new AdminMenu())->register();
        (new SpaceMetaBox())->register();
        (new ExtraMetaBox())->register();
        (new PackageMetaBox())->register();

        (new \SpaceBooking\Admin\WooCommerceOrderActions())->register();

        // Export/Import AJAX
        add_action('wp_ajax_sb_export_data', [$this, 'ajax_export_data']);

        add_action('wp_ajax_sb_import_data', [$this, 'ajax_import_data']);

        // Customer Fields AJAX
        add_action('wp_ajax_sb_save_customer_fields', [$this, 'ajax_save_customer_fields']);
    }

    public function ajax_save_customer_fields(): void
    {
        check_ajax_referer('sb_export_import', '_wpnonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $fields_json = isset($_POST['fields']) ? sanitize_textarea_field(wp_unslash($_POST['fields'])) : '';
        $fields = json_decode($fields_json, true);

        if (JSON_ERROR_NONE !== json_last_error() || !is_array($fields)) {
            wp_send_json_error('Invalid fields JSON');
        }

        $service = new \SpaceBooking\Services\CustomerFieldsService();
        if ($service->save_fields($fields)) {
            wp_send_json_success(['message' => 'Fields saved successfully']);
        } else {
            wp_send_json_error('Validation failed');
        }
    }

    public function ajax_export_data(): void
    {
        check_ajax_referer('sb_export_import', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $service = new \SpaceBooking\Services\ExportImportService();
        $json = $service->export_json();

        @header('Content-Type: application/json');
        @header('Content-Disposition: attachment; filename="space-booking-data.json"');
        @header('Content-Length: ' . strlen($json));
        echo $json;
        wp_die();
    }

    public function ajax_import_data(): void
    {
        check_ajax_referer('sb_export_import', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $file = $_FILES['json_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'File too large (upload_max_filesize)',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'Partial upload',
                UPLOAD_ERR_NO_FILE => 'No file',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Disk failure',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
            ];
            wp_send_json_error($errors[$file['error']] ?? 'Unknown upload error: ' . $file['error']);
        }

        if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            wp_send_json_error('Upload failed: temp file missing (' . esc_js($file['tmp_name']) . ')');
        }

        $service = new \SpaceBooking\Services\ExportImportService();
        $result = $service->import_json($file['tmp_name'], !empty($_POST['delete_existing']));

        if ($result[0]) {
            wp_send_json_success($result[1]);
        } else {
            wp_send_json_error($result[1]);
        }
    }
}
