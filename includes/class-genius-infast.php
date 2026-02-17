<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Genius_Infast
 * @subpackage Genius_Infast/includes
 * @author     Your Name <email@example.com>
 */
class Genius_Infast {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Genius_Infast_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Product synchronisation helper.
	 *
	 * @var Genius_Infast_Product_Sync|null
	 */
	protected $product_sync;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'PLUGIN_NAME_VERSION' ) ) {
			$this->version = PLUGIN_NAME_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'genius-infast';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_integration_hooks();
		$this->define_webhook_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Genius_Infast_Loader. Orchestrates the hooks of the plugin.
	 * - Genius_Infast_i18n. Defines internationalization functionality.
	 * - Genius_Infast_Admin. Defines all hooks for the admin area.
	 * - Genius_Infast_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-genius-infast-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-genius-infast-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-genius-infast-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-genius-infast-public.php';

		/**
		 * Core client and services for INFast API.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-genius-infast-client.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/services/class-genius-infast-service-customers.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/services/class-genius-infast-service-documents.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/services/class-genius-infast-service-items.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-genius-infast-api.php';

		/**
		 * The class responsible for WooCommerce specific integrations.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-genius-infast-woocommerce.php';

		/**
		 * The class responsible for handling incoming webhooks.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-genius-infast-webhooks.php';

		/**
		 * The class responsible for WooCommerce product synchronisation.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-genius-infast-product-sync.php';

		$this->loader = new Genius_Infast_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Genius_Infast_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Genius_Infast_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Genius_Infast_Admin( $this->get_plugin_name(), $this->get_version() );

		if ( ! $this->product_sync ) {
			$this->product_sync = new Genius_Infast_Product_Sync( $this->get_plugin_name(), $this->get_version() );
		}

		$plugin_admin->set_product_sync( $this->product_sync );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_settings_page' );
		$this->loader->add_action( 'admin_post_genius_infast_action', $plugin_admin, 'handle_admin_action' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'maybe_display_admin_notice' );
		$this->loader->add_action( 'wp_ajax_genius_infast_test_credentials', $plugin_admin, 'ajax_test_credentials' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Genius_Infast_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'init', $plugin_public, 'register_shortcodes' );
		$this->loader->add_action( 'admin_post_genius_infast_document_pdf', $plugin_public, 'handle_document_pdf_download' );
		$this->loader->add_action( 'admin_post_nopriv_genius_infast_document_pdf', $plugin_public, 'handle_document_pdf_download' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Register integration hooks (WooCommerce actions).
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_integration_hooks() {
		$integration = new Genius_Infast_WooCommerce( $this->get_plugin_name(), $this->get_version() );

		if ( ! $this->product_sync ) {
			$this->product_sync = new Genius_Infast_Product_Sync( $this->get_plugin_name(), $this->get_version() );
		}

		$this->loader->add_action( 'woocommerce_payment_complete', $integration, 'handle_payment_complete', 10, 1 );
		$this->loader->add_action( 'woocommerce_order_status_completed', $integration, 'handle_order_completed', 10, 1 );
		$this->loader->add_action( 'woocommerce_order_status_processing', $integration, 'handle_order_completed', 10, 1 );
		$this->loader->add_action( 'woocommerce_order_status_changed', $integration, 'handle_order_status_changed', 10, 4 );
		$this->loader->add_action( 'save_post_product', $this->product_sync, 'handle_product_save', 20, 3 );
		$this->loader->add_action( 'transition_post_status', $this->product_sync, 'handle_transition_status', 20, 3 );
		$this->loader->add_action( 'before_delete_post', $this->product_sync, 'handle_delete_post', 20, 1 );
	}

	/**
	 * Register webhook REST endpoints.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_webhook_hooks() {
		$webhooks = new Genius_Infast_Webhooks( $this->get_plugin_name() );

		$this->loader->add_action( 'rest_api_init', $webhooks, 'register_routes' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Genius_Infast_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
