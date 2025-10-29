<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Genius_Infast
 * @subpackage Genius_Infast/admin
 * @author     Your Name <email@example.com>
 */
class Genius_Infast_Admin
{

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Product synchronisation helper.
	 *
	 * @var Genius_Infast_Product_Sync|null
	 */
	private $product_sync;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct($plugin_name, $version)
	{

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Provide product synchronisation service.
	 *
	 * @param Genius_Infast_Product_Sync $product_sync Product synchronisation helper.
	 * @return void
	 */
	public function set_product_sync(Genius_Infast_Product_Sync $product_sync)
	{
		$this->product_sync = $product_sync;
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings()
	{
		register_setting(
			'genius_infast_settings',
			'genius_infast_client_id',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_client_secret',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_webhook_token',
			array(
				'type' => 'string',
				'sanitize_callback' => array($this, 'sanitize_webhook_token'),
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_send_email',
			array(
				'type' => 'string',
				'sanitize_callback' => array($this, 'sanitize_checkbox'),
				'default' => 'yes',
			)
		);

		add_settings_section(
			'genius_infast_section_credentials',
			__('Identifiants API INFast', 'genius_infast'),
			array($this, 'render_credentials_section_intro'),
			'genius-infast-settings'
		);

		add_settings_field(
			'genius_infast_client_id',
			__('ID client', 'genius_infast'),
			array($this, 'render_text_field'),
			'genius-infast-settings',
			'genius_infast_section_credentials',
			array(
				'option' => 'genius_infast_client_id',
				'type' => 'text',
				'description' => __('Fourni par INFast lors de la creation de votre application API.', 'genius_infast'),
			)
		);

		add_settings_field(
			'genius_infast_client_secret',
			__('Secret client', 'genius_infast'),
			array($this, 'render_text_field'),
			'genius-infast-settings',
			'genius_infast_section_credentials',
			array(
				'option' => 'genius_infast_client_secret',
				'type' => 'password',
				'description' => __('Conservez cette valeur confidentielle. Elle est nécessaire pour demander des jetons OAuth.', 'genius_infast'),
			)
		);

		add_settings_field(
			'genius_infast_webhook_token',
			__('Jeton webhook', 'genius_infast'),
			array($this, 'render_text_field'),
			'genius-infast-settings',
			'genius_infast_section_credentials',
			array(
				'option' => 'genius_infast_webhook_token',
				'type' => 'password',
				'description' => __('Jeton Bearer à renseigner dans INFast et vérifié lors de la réception des webhooks.', 'genius_infast'),
			)
		);

		add_settings_field(
			'genius_infast_send_email',
			__('Envoyer les factures par e-mail', 'genius_infast'),
			array($this, 'render_checkbox_field'),
			'genius-infast-settings',
			'genius_infast_section_credentials',
			array(
				'option' => 'genius_infast_send_email',
				'label' => __('Envoyer automatiquement les factures dès leur creation.', 'genius_infast'),
				'default' => 'yes',
			)
		);

		add_settings_field(
			'genius_infast_email_copy',
			__('Destinataire en copie', 'genius_infast'),
			array($this, 'render_text_field'),
			'genius-infast-settings',
			'genius_infast_section_credentials',
			array(
				'option' => 'genius_infast_email_copy',
				'type' => 'email',
				'description' => __('Adresse e-mail optionnelle recevant une copie des factures envoyées via INFast.', 'genius_infast'),
			)
		);

		add_settings_section(
			'genius_infast_section_behaviour',
			__('Préferences d\'automatisation', 'genius_infast'),
			array($this, 'render_behaviour_section_intro'),
			'genius-infast-settings'
		);

		add_settings_field(
			'genius_infast_skip_description',
			__('Importer les produits sans description', 'genius_infast'),
			array($this, 'render_checkbox_field'),
			'genius-infast-settings',
			'genius_infast_section_behaviour',
			array(
				'option' => 'genius_infast_skip_description',
				'label' => __('Ne pas envoyer les descriptions de produits WooCommerce vers INFast.', 'genius_infast'),
				'default' => 'yes',
			)
		);

		add_settings_field(
			'genius_infast_trigger_statuses',
			__('Statuts déclenchant la facture', 'genius_infast'),
			array($this, 'render_statuses_field'),
			'genius-infast-settings',
			'genius_infast_section_behaviour',
			array(
				'option' => 'genius_infast_trigger_statuses',
			)
		);

		add_settings_field(
			'genius_infast_enable_legal_notice',
			__('Ajouter une mention légale', 'genius_infast'),
			array($this, 'render_checkbox_field'),
			'genius-infast-settings',
			'genius_infast_section_behaviour',
			array(
				'option' => 'genius_infast_enable_legal_notice',
				'label' => __('Ajouter une mention légale specifique aux factures INFast.', 'genius_infast'),
			)
		);

		add_settings_field(
			'genius_infast_legal_notice',
			__('Texte de la mention légale', 'genius_infast'),
			array($this, 'render_text_field'),
			'genius-infast-settings',
			'genius_infast_section_behaviour',
			array(
				'option' => 'genius_infast_legal_notice',
				'type' => 'text',
				'description' => __('Ce texte est envoyé à INFast et affiché sur les factures generées lorsque l\'option est activée.', 'genius_infast'),
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_email_copy',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_email',
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_skip_description',
			array(
				'type' => 'string',
				'sanitize_callback' => array($this, 'sanitize_checkbox'),
				'default' => 'no',
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_trigger_statuses',
			array(
				'type' => 'array',
				'sanitize_callback' => array($this, 'sanitize_statuses'),
				'default' => array('wc-completed'),
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_enable_legal_notice',
			array(
				'type' => 'string',
				'sanitize_callback' => array($this, 'sanitize_checkbox'),
				'default' => 'no',
			)
		);

		register_setting(
			'genius_infast_settings',
			'genius_infast_legal_notice',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Add settings page in admin area.
	 *
	 * @return void
	 */
	public function add_settings_page()
	{
		if (class_exists('WooCommerce')) {
			add_submenu_page(
				'woocommerce',
				__('Paramètres INFast', 'genius_infast'),
				__('INFast', 'genius_infast'),
				'manage_woocommerce',
				'genius-infast-settings',
				array($this, 'render_settings_page')
			);
		} else {
			add_options_page(
				__('Paramètres INFast', 'genius_infast'),
				__('INFast', 'genius_infast'),
				'manage_options',
				'genius-infast-settings',
				array($this, 'render_settings_page')
			);
		}
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page()
	{
		if (!current_user_can(class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options')) {
			return;
		}
		settings_errors('genius_infast_settings');
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Paramètres INFast', 'genius_infast'); ?></h1>
			<form method="post" action="options.php" id="genius-infast-settings-form">
				<?php
				settings_fields('genius_infast_settings');
				do_settings_sections('genius-infast-settings');
				submit_button();
				?>
			</form>

			<div class="genius-infast-test-connection">
				<button type="button" class="button"
					id="genius-infast-test-connection"><?php esc_html_e('Tester la connexion', 'genius_infast'); ?></button>
				<span id="genius-infast-connection-status" class="genius-infast-status" role="status" aria-live="polite"></span>
			</div>

			<h2><?php esc_html_e('Synchroniser les produits', 'genius_infast'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('genius_infast_sync_products'); ?>
				<input type="hidden" name="action" value="genius_infast_action" />
				<input type="hidden" name="task" value="sync_products" />
				<?php submit_button(__('Démarrer la synchronisation', 'genius_infast'), 'primary', 'submit', false); ?>
			</form>

			<h2><?php esc_html_e('Délier les produits WooCommerce', 'genius_infast'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('genius_infast_unlink_products'); ?>
				<input type="hidden" name="action" value="genius_infast_action" />
				<input type="hidden" name="task" value="unlink_products" />
				<?php submit_button(__('Délier', 'genius_infast'), 'delete', 'submit', false); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render introduction for credentials section.
	 *
	 * @return void
	 */
	public function render_credentials_section_intro()
	{
		echo '<p><a href="https://doc.api.infast.fr/docs/api/infast-api" target="_blank" rel="noopener noreferrer">' . esc_html__('Retrouvez vos clés API INFast', 'genius_infast') . '</a></p>';
	}

	/**
	 * Render introduction for automation section.
	 *
	 * @return void
	 */
	public function render_behaviour_section_intro()
	{
		echo '<p>' . esc_html__('Ajustez la manière dont WooCommerce partage les commandes et produits avec INFast.', 'genius_infast') . '</p>';
	}

	/**
	 * Render a generic text/password field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_text_field($args)
	{
		$option = isset($args['option']) ? $args['option'] : '';
		$type = isset($args['type']) ? $args['type'] : 'text';
		$value = get_option($option, '');

		printf(
			'<input type="%1$s" class="regular-text" autocomplete="off" name="%2$s" id="%2$s" value="%3$s" />',
			esc_attr($type),
			esc_attr($option),
			esc_attr($value)
		);

		if (!empty($args['description'])) {
			printf(
				'<p class="description">%s</p>',
				esc_html($args['description'])
			);
		}
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_checkbox_field($args)
	{
		$option = isset($args['option']) ? $args['option'] : '';
		$default = isset($args['default']) ? $args['default'] : 'no';
		$value = get_option($option, $default);

		printf(
			'<input type="hidden" name="%1$s" value="no" /><label for="%1$s"><input type="checkbox" name="%1$s" id="%1$s" value="yes" %2$s /> %3$s</label>',
			esc_attr($option),
			checked('yes', $value, false),
			isset($args['label']) ? esc_html($args['label']) : ''
		);
	}

	/**
	 * Sanitize checkbox values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_checkbox($value)
	{
		return ('yes' === $value || '1' === $value || 1 === $value || true === $value) ? 'yes' : 'no';
	}

	/**
	 * Sanitize webhook bearer token values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_webhook_token($value)
	{
		$value = is_string($value) ? trim($value) : '';

		if ('' === $value) {
			return '';
		}

		$value = preg_replace('/\s+/', '', $value);

		return sanitize_text_field($value);
	}

	/**
	 * Render statuses selection field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_statuses_field($args)
	{
		$option = isset($args['option']) ? $args['option'] : '';
		$selected = (array) get_option($option, array('wc-completed'));
		$statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();

		if (empty($statuses)) {
			echo '<p>' . esc_html__('Les statuts de commande WooCommerce ne sont pas disponibles.', 'genius_infast') . '</p>';
			return;
		}

		echo '<input type="hidden" name="' . esc_attr($option) . '[]" value="" />';

		foreach ($statuses as $status_key => $label) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr($option),
				esc_attr($status_key),
				checked(in_array($status_key, $selected, true), true, false),
				esc_html($label)
			);
		}
	}

	/**
	 * Sanitize WooCommerce statuses array.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize_statuses($value)
	{
		$statuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : array();
		$value = is_array($value) ? $value : array();
		$value = array_map('sanitize_text_field', $value);
		$value = array_values(array_intersect($value, $statuses));

		if (empty($value) && !empty($statuses)) {
			$value = array('wc-completed');
		}

		return $value;
	}

	/**
	 * Handle custom admin-post actions.
	 *
	 * @return void
	 */
	public function handle_admin_action()
	{
		if (!current_user_can(class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options')) {
			wp_die(esc_html__('Vous n\'êtes pas autorisé(e) à effectuer cette action.', 'genius_infast'));
		}

		$task = isset($_POST['task']) ? sanitize_text_field(wp_unslash($_POST['task'])) : '';

		switch ($task) {
			case 'sync_products':
				check_admin_referer('genius_infast_sync_products');
				$result = $this->sync_products();
				break;
			case 'unlink_products':
				check_admin_referer('genius_infast_unlink_products');
				$result = $this->unlink_products();
				break;
			default:
				$result = new WP_Error('genius_infast_unknown_action', __('Action inconnue.', 'genius_infast'));
		}

		if (is_wp_error($result)) {
			$message = $result->get_error_message();
			$data = $result->get_error_data();
			if (isset($data['details']) && is_array($data['details']) && !empty($data['details'])) {
				$message .= ' ' . implode(' | ', array_map('sanitize_text_field', $data['details']));
			}
			$this->set_admin_notice('error', $message);
		} elseif (is_string($result)) {
			$this->set_admin_notice('success', $result);
		}

		wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=genius-infast-settings'));
		exit;
	}

	/**
	 * AJAX handler to test INFast credentials.
	 */
	public function ajax_test_credentials()
	{
		check_ajax_referer('genius_infast_ajax', 'nonce');

		if (!current_user_can(class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options')) {
			wp_send_json_error(array('message' => __('Permission refusée.', 'genius_infast')), 403);
		}

		$client_id = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
		$client_secret = isset($_POST['client_secret']) ? sanitize_text_field(wp_unslash($_POST['client_secret'])) : '';

		if ('' === $client_id || '' === $client_secret) {
			wp_send_json_error(array('message' => __('Veuillez renseigner un ID client et un secret client.', 'genius_infast')));
		}

		$api = new Genius_Infast_API($client_id, $client_secret);
		$response = $api->get_current_user();

		if (is_wp_error($response)) {
			wp_send_json_error(array('message' => $response->get_error_message()));
		}

		$name = '';
		if (isset($response['data']) && is_array($response['data']) && !empty($response['data']['name'])) {
			$name = sanitize_text_field($response['data']['name']);
		}

		$message = $name ? sprintf(__('Connecté en tant que %s.', 'genius_infast'), $name) : __('Connexion à INFast réussie.', 'genius_infast');

		wp_send_json_success(array('message' => $message));
	}

	/**
	 * Attempt to contact INFast API with current credentials.
	 *
	 * @return string|WP_Error
	 */
	private function test_connection()
	{
		$api = new Genius_Infast_API(
			get_option('genius_infast_client_id', ''),
			get_option('genius_infast_client_secret', '')
		);

		$response = $api->get_current_user();

		if (is_wp_error($response)) {
			return $response;
		}

		$name = '';
		if (isset($response['data']) && is_array($response['data']) && !empty($response['data']['name'])) {
			$name = sanitize_text_field($response['data']['name']);
		}

		return $name ? sprintf(__('Connecté en tant que %s.', 'genius_infast'), $name) : __('Connexion à INFast réussie.', 'genius_infast');
	}

	/**
	 * Trigger full product synchronisation.
	 *
	 * @return string|WP_Error
	 */
	private function sync_products()
	{
		if (!$this->product_sync) {
			return new WP_Error('genius_infast_missing_dependency', __('Le service de synchronisation des produits n\'est pas disponible.', 'genius_infast'));
		}

		return $this->product_sync->sync_all_products();
	}

	/**
	 * Unlink all products from INFast references.
	 *
	 * @return string|WP_Error
	 */
	private function unlink_products()
	{
		if (!$this->product_sync) {
			return new WP_Error('genius_infast_missing_dependency', __('Le service de synchronisation des produits n\'est pas disponible.', 'genius_infast'));
		}

		$result = $this->product_sync->unlink_all_products();
		if (is_wp_error($result)) {
			return $result;
		}

		return $result;
	}

	/**
	 * Store admin notice for later display.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function set_admin_notice($type, $message)
	{
		update_option(
			'genius_infast_admin_notice',
			array(
				'type' => $type,
				'message' => $message,
			)
		);
	}

	/**
	 * Display stored admin notices.
	 *
	 * @return void
	 */
	public function maybe_display_admin_notice()
	{
		$notice = get_option('genius_infast_admin_notice');

		if (empty($notice['message'])) {
			return;
		}

		$class = 'notice notice-' . ('error' === $notice['type'] ? 'error' : 'success');

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr($class),
			esc_html($notice['message'])
		);

		delete_option('genius_infast_admin_notice');
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles()
	{

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Genius_Infast_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Genius_Infast_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/genius-infast-admin.css', array(), $this->version, 'all');

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts()
	{

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Genius_Infast_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Genius_Infast_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/genius-infast-admin.js', array('jquery'), $this->version, true);
		wp_localize_script(
			$this->plugin_name,
			'GeniusInfastAdmin',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('genius_infast_ajax'),
				'successText' => __('Connexion à INFast réussie.', 'genius_infast'),
				'errorText' => __('Échec de la connexion à INFast.', 'genius_infast'),
				'loadingText' => __('Test en cours...', 'genius_infast'),
				'testButtonSelector' => '#genius-infast-test-connection',
				'clientIdSelector' => '#genius_infast_client_id',
				'clientSecretSelector' => '#genius_infast_client_secret',
				'feedbackSelector' => '#genius-infast-connection-status',
			)
		);

	}

}
