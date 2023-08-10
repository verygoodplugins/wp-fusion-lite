<?php
/**
 * The Mindshare Options Framework is a flexible, lightweight framework for creating WordPress theme and plugin options screens.
 *
 * @version        2.2
 * @author         Mindshare Studios, Inc.
 * @copyright      Copyright (c) 2014
 * @link           http://www.mindsharelabs.com/documentation/
 *
 * @credits        Originally inspired by: Admin Page Class 0.9.9 by Ohad Raz http://bainternet.info
 *
 * @license        GNU General Public License v3.0 - license.txt
 *                 THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *                 IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *                 FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT. IN NO EVENT SHALL THE
 *                 AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *                 LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *                 OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *                 THE SOFTWARE.
 *
 * Changelog:
 *
 * 2.2 - Several new features. See readme.
 * 2.1.3 - Settings will now be set to their defaults and saved on first load
 * 2.1.1 - Minor bugfix
 * 2.1 - Added import / export, refactored pages and sections, bugfixes
 * 2.0 - Major refactor in prep for public release
 * 0.3.4 - some refactoring, styling for checkbox lists
 * 0.3.3 - updated CodeMirror
 * 0.3.2 - fixed issue with code fields. css updates
 * 0.3.1 - fixed htmlspecialchars/stripslashes issue with text fields
 * 0.3 - bugfixes
 * 0.2.1 - fix for attribute escape problem
 * 0.2 - major update, fixed import/export, added subtitle field, sanitization
 * 0.1 - first release
 *
 * Damian and Bryce were here.
 */
class WP_Fusion_Options {

	/**
	 * The MOF version number.
	 *
	 * @var string
	 */
	private $version = '2.1.3';

	private $option_group, $setup, $sections;

	// Protected so child class can update choices/values if needed
	protected $settings;

	// Protected so child class can update post_data if needed
	public $post_data;

	// Optional variable to contain additional pages to register
	private $subpages;

	// Will contain all of the options as stored in the database
	public $options;

	// Temporary array to contain all of the checboxes in use
	private $checkboxes;

	// Temporary array to contain all of the multi select fields in use
	private $multi_selects;

	// Temporary array to contain all of the field types currently in use
	private $fields;

	// Path to the Mindshare Options Framework
	private $selfpath;

	// Set to true if settings have been imported
	private $settings_imported;

	// Set to true if settings have been updated
	private $settings_updated;

	// Array containing errors (if any) encountered on save
	public $errors;

	// Is set to true when options are being reset
	private $reset_options;

	// Default values for the setup variable
	private $default_project = array(
		'project_name' => 'Untitled Project',
		'project_slug' => 'untitled-project',
		'menu'         => 'settings',
		'page_title'   => 'Untitled Project Settings',
		'menu_title'   => 'Untitled Project',
		'capability'   => 'manage_options',
		'option_group' => 'untitled_project_options',
		'slug'         => 'untitled-project-settings',
		'page_icon'    => 'options-general',
		'icon_url'     => '',
		'position'     => null,
	);

	private $default_page = array(
		'menu'       => 'settings',
		'page_title' => 'New Page',
		'menu_title' => 'New Page',
		'capability' => 'manage_options',
		'slug'       => 'new-page',
		'page_icon'  => 'options-general',
		'icon_url'   => '',
		'position'   => null,
	);

	private $default_setting = array(
		'title'          => null,
		'desc'           => null,
		'std'            => null,
		'type'           => 'checkbox',
		'section'        => '',
		'class'          => null,
		'disabled'       => false,
		'input_disabled' => false,
		'unlock'         => null,
		'lock'           => null,
	);

	/**
	 * Constructor
	 *
	 * @param array $setup    Contains the universal project setup parameters
	 * @param array $settings Contains all of the settings fields and their assigned section
	 * @param array $sections Contains the various sections (pages and tabs) and their relationships
	 * @param null  $subpages
	 *
	 * @internal param array $subpages (optional) Contains subpages to be generated off of the main page if a top-level menus is being created
	 */
	public function __construct( $setup, $sections = null, $subpages = null ) {

		// Merge default setup with user-specified setup parameters
		$setup = wp_parse_args( $setup, $this->default_project );

		$this->selfpath = plugin_dir_url( __FILE__ );

		$this->setup                      = $setup;
		$this->sections                   = $sections;
		$this->subpages                   = $subpages;
		$this->settings                   = array();
		$this->default_setting['section'] = $setup['slug'];

		// Load option group
		$this->option_group = $setup['option_group'];
		$this->options      = get_option( 'wpf_options', array() );

		// Start it up
		add_action( 'init', array( $this, 'init' ) );

	}

	public function init() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['page'] ) && 'wpf-settings' == $_GET['page'] ) {

			// Remove all notices from other plugins

			remove_all_actions( 'admin_notices' );

			do_action( 'wpf_settings_page_init' );

			// Load in all pluggable settings
			$settings = apply_filters( $this->setup['project_slug'] . '_configure_settings', $this->settings, $this->options );

			// Initialize settings to default values
			$this->settings = $this->initialize_settings( $settings );

			// This is messy but it's a quick fix for making wpf_get_setting_ filters
			// work on the settings page.

			foreach ( $this->options as $id => $value ) {
				$this->options[ $id ] = apply_filters( 'wpf_get_setting_' . $id, $value );
			}

			if ( isset( $_POST['action'] ) && 'update' == $_POST['action'] && isset( $_POST[ $this->setup['project_slug'] . '_nonce' ] ) ) {

				$this->save_options();

				// Reconfigure settings based on the options that were just saved (for example unlocking things based on checkbox inputs)

				$this->settings = apply_filters( $this->setup['project_slug'] . '_configure_settings', $this->settings, $this->options );

			}
		}

		add_action( 'admin_menu', array( $this, 'add_menus' ) );

	}


	/*
	----------------------------------------------------------------*/
	/*
	/* Functions to handle saving and validation of options
	/*
	/*----------------------------------------------------------------*/

	/**
	 * Checks nonce and saves options to database
	 *
	 * @access   private
	 *
	 * @internal param \data $_POST
	 */

	private function save_options() {
		$nonce = $_POST[ $this->setup['project_slug'] . '_nonce' ];

		if ( ! isset( $_POST[ $this->setup['project_slug'] . '_nonce' ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, $this->setup['project_slug'] ) ) {
			die( 'Security check. Invalid nonce.' );
		}

		// Get array of form data
		if ( isset( $_POST[ $this->option_group ] ) ) {
			$this->post_data = wp_unslash( $_POST[ $this->option_group ] );
		} else {
			$this->post_data = array();
		}

		// For each settings field, run the input through it's defined validation function

		$settings = $this->settings;

		// Beydefault $_POST ignores checkboxes with no value set, so we need to
		// iterate through all defined checkboxes and set their value to 0 if
		// they haven't been set in the input. We'll only do this after the
		// connection is configured to avoid messing up default values added by
		// integrations that depend on the setup being complete.
		if ( isset( $this->checkboxes ) && ! empty( $this->post_data['connection_configured'] ) ) {
			foreach ( $this->checkboxes as $id ) {
				if ( ! isset( $this->post_data[ $id ] ) || $this->post_data[ $id ] != '1' ) {
					$this->post_data[ $id ] = 0;
				} else {
					$this->post_data[ $id ] = 1;
				}
			}
		}

		// Set multi selects to default values if they're registered but haven't been posted
		if ( isset( $this->multi_selects ) ) {
			foreach ( $this->multi_selects as $id ) {
				if ( ! isset( $this->post_data[ $id ] ) ) {
					$this->post_data[ $id ] = '';
				}
			}
		}

		foreach ( $settings as $id => $setting ) {

			if ( empty( $this->post_data[ $id ] ) && 'hidden' === $setting['type'] ) {
				// Don't erase saved values with empty hidden fields.
				unset( $this->post_data[ $id ] );
			}

			if ( isset( $this->post_data[ $id ] ) && $this->post_data[ $id ] !== $this->options[ $id ] ) {

				$this->post_data[ $id ] = $this->validate_options( $id, $this->post_data[ $id ], $setting );

			}
		}

		if ( $this->reset_options ) {

			do_action( 'wpf_resetting_options', wp_parse_args( $this->post_data, $this->options ) );

			delete_option( $this->option_group );
			delete_option( 'wpf_available_tags' );
			delete_option( 'wpf_crm_fields' );

			$this->options = array();

			// Rebuild defaults and apply filters
			$settings = apply_filters( $this->setup['project_slug'] . '_configure_settings', $this->settings, $this->options );

			// Initialize settings to default values
			$this->settings = $this->initialize_settings( $settings );

			// Update the options within the class
			wp_fusion()->settings->options = $this->options;

		} else {

			// Merge the form data with the existing options, updating as necessary
			$this->options = wp_parse_args( $this->post_data, $this->options );

			// Re-do init
			$this->options = apply_filters( $this->setup['project_slug'] . '_initialize_options', $this->options );

			// Clear out any empty or default values so they don't need to be saved

			$options = $this->options;

			foreach ( $options as $id => $value ) {

				if ( isset( $settings[ $id ] ) ) {

					// If the setting is known

					if ( empty( $value ) && empty( $settings[ $id ]['std'] ) ) {

						// If the setting is empty and the standard is empty, then we don't need to save it to the database
						unset( $options[ $id ] );

					}
				} elseif ( ! isset( $settings[ $id ] ) && empty( $value ) ) {

					// Cases where a setting was posted but it wasn't registered (like a CRM config panel we're no longer using)
					unset( $options[ $id ] );

				}
			}

			// As of v3.37.0 we now store these in their own keys for performance reasons

			if ( isset( $options['available_tags'] ) ) {
				update_option( 'wpf_available_tags', $options['available_tags'], false );
				unset( $options['available_tags'] );
			}

			if ( isset( $options['crm_fields'] ) ) {
				update_option( 'wpf_crm_fields', $options['crm_fields'], false );
				unset( $options['crm_fields'] );
			}

			// Update the option in the database
			update_option( $this->option_group, $options );

			// Update the options within the WPF class
			wp_fusion()->settings->options = $this->options;

			// Let the page renderer know that the settings have been updated
			$this->settings_updated = true;

		}
	}

	/**
	 * Looks for the proper validation function for a given setting and returns the validated input
	 *
	 * @access private
	 *
	 * @param string $id      ID of field
	 * @param mixed  $input   Input
	 * @param array  $setting Setting properties
	 *
	 * @return mixed $input Validated input
	 */

	private function validate_options( $id, $input, $setting ) {

		if ( method_exists( $this, 'validate_field_' . $setting['type'] ) && ! has_filter( 'validate_field_' . $setting['type'] . '_override' ) ) {

			// If a validation filter has been specified for the setting type, register it with add_filters
			add_filter( 'validate_field_' . $setting['type'], array( $this, 'validate_field_' . $setting['type'] ), 10, 3 );

		}

		// Handles the Reset option.
		if ( method_exists( $this, 'validate_field_' . $id ) ) {
			add_filter( 'validate_field_' . $id, array( $this, 'validate_field_' . $id ), 10, 3 );
		}

		if ( has_filter( 'validate_field_' . $id ) ) {

			// If there's a validation function for this particular field ID
			$input = apply_filters( 'validate_field_' . $id, $input, $setting, $this );

		} elseif ( has_filter( 'validate_field_' . $setting['type'] ) || has_filter( 'validate_field_' . $setting['type'] . '_override' ) ) {

			// If there's a validation for this field type or an override
			if ( has_filter( 'validate_field_' . $setting['type'] . '_override' ) ) {

				$input = apply_filters( 'validate_field_' . $setting['type'] . '_override', $input, $setting, $this );

			} elseif ( has_filter( 'validate_field_' . $setting['type'] ) ) {

				$input = apply_filters( 'validate_field_' . $setting['type'], $input, $setting, $this );

			}
		} else {

			// If no validator specified, use the default validator
			// @todo right now the validator just passes the input back. see what base-level validation we need
			$input = $this->validate_field_default( $input, $setting );
		}

		if ( is_wp_error( $input ) ) {

			// If an input fails validation, put the error message into the errors array for display.
			$this->errors[ $id ] = sprintf( __( 'Error saving field %1$s: %2$s', 'wp-fusion-lite' ), '<strong>' . esc_html( $setting['title'] ) . '</strong>', esc_html( $input->get_error_message() ) );
			$input               = $input->get_error_data();
		}

		return $input;
	}

	/*
	----------------------------------------------------------------*/
	/*
	/* Functions to handle initialization of settings fields
	/*
	/*----------------------------------------------------------------*/

	/**
	 * Checks for new settings fields and sets their options to default values
	 *
	 * @access private
	 *
	 * @param $settings array
	 *
	 * @return array $settings The settings array
	 * @return array $options The options array
	 */

	private function initialize_settings( $settings ) {

		foreach ( $settings as $id => $setting ) {

			// Set default values from global setting default template

			$settings[ $id ] = wp_parse_args( $setting, $this->default_setting );

			// We need to keep track of some types here

			if ( 'checkbox' == $setting['type'] ) {
				$this->checkboxes[] = $id;
			} elseif ( 'multi_select' == $setting['type'] || 'checkboxes' == $setting['type'] || 'assign_tags' == $setting['type'] ) {
				$this->multi_selects[] = $id;
			}

			// If a custom setting template has been specified, load those values as well

			if ( has_filter( 'default_field_' . $setting['type'] ) ) {

				$default         = apply_filters( 'default_field_' . $setting['type'], $setting );
				$settings[ $id ] = wp_parse_args( $settings[ $id ], $default );

			} elseif ( method_exists( $this, 'default_field_' . $setting['type'] ) ) {

				$default         = call_user_func( array( $this, 'default_field_' . $setting['type'] ) );
				$settings[ $id ] = wp_parse_args( $settings[ $id ], $default );

			}

			// Load the array of settings currently in use
			if ( ! isset( $this->fields[ $setting['type'] ] ) ) {
				$this->fields[ $setting['type'] ] = true;
			}

			if ( ! isset( $this->options[ $id ] ) && ! empty( $settings[ $id ]['std'] ) ) {

				// Set the default value if no option exists
				$this->options[ $id ] = $settings[ $id ]['std'];

			} elseif ( ! isset( $this->options[ $id ] ) ) {

				// If there's no std, set it to false
				$this->options[ $id ] = false;
			}
		}

		return $settings;

	}

	/*
	----------------------------------------------------------------*/
	/*
	/* Functions to handle creating menu items and registering pages
	/*
	/*----------------------------------------------------------------*/

	/**
	 * Sets the top level menu slug based on the user preference
	 *
	 * @access   private
	 *
	 * @param $menu
	 *
	 * @internal param array $setup
	 * @internal param array $subpages
	 *
	 * @return string
	 */

	private function parent_slug( $menu ) {

		switch ( $menu ) {
			case 'posts':
				return 'edit.php';

			case 'dashboard':
				return 'index.php';

			case 'media':
				return 'upload.php';

			case 'links':
				return 'link-manager.php';

			case 'pages':
				return 'edit.php?post_type=page';

			case 'comments':
				return 'edit-comments.php';

			case 'theme':
				return 'themes.php';

			case 'plugins':
				return 'plugins.php';

			case 'users':
				return 'users.php';

			case 'tools':
				return 'tools.php';

			case 'settings':
				return 'options-general.php';

			default:
				if ( post_type_exists( $menu ) ) {
					return 'edit.php?post_type=' . $menu;
				} else {
					return $menu;
				}
		}
	}

	/**
	 * Builds menus and submenus according to the pages and subpages specified by the user
	 *
	 * @access public
	 */

	public function add_menus() {

		// Create an array to contain all pages, and add the main setup page (registered with $setup).
		// Moved strings to this function in 3.40.23 so they pass through gettext
		$pages = array(
			$this->setup['slug'] => array(
				'menu'         => 'settings',
				'page_title'   => __( 'WP Fusion Settings', 'wp-fusion-lite' ),
				'menu_title'   => __( 'WP Fusion', 'wp-fusion-lite' ),
				'capability'   => 'manage_options',
				'option_group' => 'wpf_options',
				'slug'         => 'wpf-settings',
				'page_icon'    => 'tools',
			),
		);

		// If additional pages have been specified, load them into the pages array
		if ( $this->subpages ) {
			foreach ( $this->subpages as $slug => $page ) {
				$pages[ $slug ] = wp_parse_args( $page, $this->default_page );
			}
		}

		// For each page, register it with add_submenu_page and create an admin_print_scripts action
		foreach ( $pages as $slug => $page ) {

			// If page does not have a menu, create a top level menu item
			if ( $page['menu'] == null ) {

				$id = add_menu_page(
					$page['page_title'],
					$page['menu_title'],
					$page['capability'],
					$slug,
					array( $this, 'show_page' ),
					$page['icon_url'],
					$page['position']
				);
			} else {

				$id = add_submenu_page(
					$this->parent_slug( $page['menu'] ), // parent slug
					$page['page_title'], // page title
					$page['menu_title'], // menu title
					$page['capability'], // capability
					$slug, // slug
					array( $this, 'show_page' ) // display function
				);
			}

			// add_action( 'admin_print_scripts-'.$id, array( $this, 'scripts' ) );
			add_action( 'load-' . $id, array( $this, 'enqueue_scripts_action' ) );

			// Add the ID back into the array so we can locate this page again later
			$pages[ $slug ]['id'] = $id;
		}

		// Make the reorganized array available to the rest of the class
		$this->subpages = $pages;
	}

	/*
	----------------------------------------------------------------*/
	/*
	/* Functions to handle rendering page wrappers and outputting settings fields
	/*
	/*----------------------------------------------------------------*/

	/**
	 * Only runs on pages generated by the options framework
	 */

	public function enqueue_scripts_action() {

		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ), 20 );

	}

	/**
	 * Enqueue scripts and styles
	 */
	public function scripts() {

		wp_enqueue_script( 'bootstrap', $this->selfpath . 'js/bootstrap.min.js', array( 'jquery' ) );

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_style( 'fontawesome', $this->selfpath . 'css/font-awesome.min.css' );
		wp_enqueue_style( 'options-css', $this->selfpath . 'css/options.css' );

		wp_dequeue_script( 'premmerce-permalink-settings-script' ); // fixes conflict with the nonce and action getting wiped out in Premmerce Permalink Manager for WooCommerce.

		// Enqueue TinyMCE editor
		if ( isset( $this->fields['editor'] ) ) {
			wp_print_scripts( 'editor' );
		}

		if ( isset( $this->fields['select'] ) || isset( $this->fields['multi_select'] ) ) {
			wp_enqueue_style( 'select4', $this->selfpath . 'lib/select2/select4.min.css', array(), null );
			wp_enqueue_script( 'select4', $this->selfpath . 'lib/select2/select4.min.js', array( 'jquery' ), '4.0.1', true );
		}

		if ( isset( $this->fields['password'] ) ) {
			wp_enqueue_script( 'password-strength-meter' );
		}

		// Enqueue plupload
		if ( isset( $this->fields['plupload'] ) ) {
			wp_enqueue_script( 'plupload-all' );
			wp_register_script( 'myplupload', $this->selfpath . 'lib/plupload/myplupload.js', array( 'jquery' ) );
			wp_enqueue_script( 'myplupload' );
			wp_register_style( 'myplupload', $this->selfpath . 'lib/plupload/myplupload.css' );
			wp_enqueue_style( 'myplupload' );

			// Add data encoding type for file uploading.
			add_action( 'post_edit_form_tag', array( $this, 'add_enctype' ) );

			// Make upload feature work event when custom post type doesn't support 'editor'
			wp_enqueue_script( 'media-upload' );
			wp_enqueue_script( 'thickbox' );
			wp_enqueue_script( 'jquery-ui-core' );
			wp_enqueue_script( 'jquery-ui-sortable' );

			// Add filters for media upload.
			add_filter( 'media_upload_gallery', array( &$this, 'insert_images' ) );
			add_filter( 'media_upload_library', array( &$this, 'insert_images' ) );
			add_filter( 'media_upload_image', array( &$this, 'insert_images' ) );
			// Delete all attachments when delete custom post type.
			add_action( 'wp_ajax_at_delete_file', array( &$this, 'delete_file' ) );
			add_action( 'wp_ajax_at_reorder_images', array( &$this, 'reorder_images' ) );
			// Delete file via Ajax
			add_action( 'wp_ajax_at_delete_mupload', array( $this, 'wp_ajax_delete_image' ) );
		}

		// Enqueue any custom scripts and styles
		if ( has_action( $this->setup['project_slug'] . '_enqueue_scripts' ) ) {
			do_action( $this->setup['project_slug'] . '_enqueue_scripts' ); // wpf_enqueue_scripts.
		}
	}

	/**
	 * Gets the current page settings based on the screen object given by get_current_screen()
	 *
	 * @access private
	 *
	 * @param $screen object
	 */

	private function get_page_by_screen( $screen ) {

		foreach ( $this->subpages as $slug => $page ) {

			if ( $page['id'] == $screen->id ) {

				if ( isset( $this->sections[ $slug ] ) ) {

					// If sections have been given for this specific page
					$page['sections'] = $this->sections[ $slug ];
				} else {

					// If there are sections, but none for this specific page, create one section w/ the page's slug
					$page['sections'][ $slug ] = $slug;
				}

				$page['slug'] = $slug;

				return $page;
			}
		}
	}

	/**
	 *
	 * Page wrappers and layout handlers
	 */

	public function show_page() { ?>
		<?php $page = $this->get_page_by_screen( get_current_screen() ); ?>
		<?php $page = apply_filters( $this->setup['project_slug'] . '_configure_sections', $page, $this->options ); ?>

		<?php do_action( 'wpf_settings_page_before_wrap' ); ?>

		<div class="wrap wpf-settings-wrap">
		<img id="wpf-settings-logo" src="<?php echo esc_url( WPF_DIR_URL . '/assets/img/logo-sm-trans.png' ); ?>">
		<h2 class="wp-heading-inline" id="wpf-settings-header"><?php echo esc_html( $page['page_title'] ); ?></h2>
		<?php do_action( 'wpf_settings_page_title' ); ?>

		<hr class="wp-header-end" />

		<div id="wpf-settings-notices">

			<?php

			do_action( 'wpf_settings_notices' );

			if ( $this->settings_updated ) {
				echo '<div id="setting-error-settings_updated" class="updated settings-error"><p><strong>Settings saved.</strong></p></div>';
			}

			if ( $this->settings_imported ) {
				echo '<div id="setting-error-settings_updated" class="updated settings-error"><p><strong>Settings successfully imported.</strong></p></div>';
			}

			if ( $this->reset_options ) {
				echo '<div id="setting-error-settings_updated" class="updated settings-error"><p><strong>Settings successfully reset.</strong></p></div>';
			}

			if ( $this->errors ) {
				foreach ( $this->errors as $id => $error_message ) {
					echo '<div id="message" class="error"><p><i class="fa fa-warning"></i>' . wp_kses_post( $error_message ) . '</p></div>';
					echo '<style type="text/css">#' . esc_attr( $id ) . '{ border: 1px solid #d00; }</style>';
				}
			}

			?>

		</div>

		<form id="<?php echo esc_attr( $page['slug'] ); ?>" class="
								<?php
								if ( $this->options['connection_configured'] == false ) {
									echo 'setup';}
								?>
" action="" method="post">
			<?php wp_nonce_field( $this->setup['project_slug'], $this->setup['project_slug'] . '_nonce' ); ?>
			<input type="hidden" name="action" value="update">

			<?php
			if ( has_action( 'before_page_' . $page['id'] ) ) {
				do_action( 'before_page_' . $page['id'] );
			}

			// only display tabs if there's more than one section
			if ( count( $page['sections'] ) > 1 ) {
				?>

				<ul class="nav nav-tabs">
					<?php $isfirst = true; ?>
				<?php foreach ( $page['sections'] as $section_slug => $section ) {

					?>

						<?php if ( ! is_array( $section ) ) : ?>

							<li id="tab-<?php echo esc_attr( $section_slug ); ?>"
													<?php
													if ( $isfirst ) {
														echo "class='active'"; }
													?>
>
								<a href="#<?php echo esc_attr( $section_slug ); ?>" data-toggle="tab" data-taget="<?php echo esc_attr( $section_slug ); ?>"><?php echo esc_html( $section ); ?></a>
							</li>

						<?php else : ?>

							<?php if ( isset( $section['url'] ) ) : ?>

								<li id="tab-<?php echo esc_attr( $section_slug ); ?>">
									<a href="<?php echo esc_url( $section['url'] ); ?>"><?php echo esc_html( $section['title'] ); ?></a>
								</li>

							<?php elseif ( isset( $section['slug'] ) ) : ?>

								<li id="tab-<?php echo esc_attr( $section_slug ); ?>"> 

									<?php $allowed_tags = array( 'span' => array( 'title' => true, 'class' => true ) ); ?>

									<a href="<?php echo esc_url( menu_page_url( $section['slug'], false ) ); ?>"><?php echo wp_kses( $section['title'], $allowed_tags ); ?></a>
								</li>

							<?php endif; ?>

						<?php endif; ?>

						<?php $isfirst = false; ?>

					<?php } ?>
							</ul>

						<?php } ?>
			<div class="<?php echo esc_attr( $page['id'] ); ?>-tab-content">
				<?php $isfirst = true; ?>

				<?php

				// Move pane to end to fix max_input_vars issues
				if ( isset( $page['sections']['contact-fields'] ) ) {

					$tab = $page['sections']['contact-fields'];
					unset( $page['sections']['contact-fields'] );

					$page['sections']['contact-fields'] = $tab;

				}

				foreach ( $page['sections'] as $section_slug => $section ) {
					?>

					<?php
					if ( is_array( $section ) ) {
						continue;}
					?>

					<div class="tab-pane 
					<?php
					if ( $isfirst ) {
						echo 'active'; }
					?>
" id="<?php echo esc_attr( $section_slug ); ?>">
						<?php if ( count( $page['sections'] ) > 1 ) { ?>
							<h3><?php echo esc_html( $section ); ?></h3>
						<?php } ?>

						<?php
						// Check to see if a user-created override for the display function is available
						if ( has_action( 'show_section_' . $section_slug ) ) {

							do_action( 'show_section_' . $section_slug, $section_slug, $this->settings );
						} else {

							$this->show_section( $section_slug );
						}
						?>
					</div>
					<?php $isfirst = false; ?>
				<?php } ?>
			</div>
			<p class="submit"><input name="Submit" type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'wp-fusion-lite' ); ?>" /></p>
		</form>

		<?php
	}

	/**
	 * Renders the individual settings fields within their appropriate sections
	 *
	 * @access private
	 *
	 * @param $section string
	 */

	private function show_section( $section ) {

		?>

		<table class="form-table">

			<?php
			foreach ( $this->settings as $id => $setting ) {

				if ( $setting['section'] == $section ) {

					// For each part of the field (begin, content, and end) check to see if a user-specified override is available in the child class
					$defaults = $this->default_setting;

					if ( has_filter( 'default_field_' . $setting['type'] ) ) {

						$defaults = array_merge( $defaults, apply_filters( 'default_field_' . $setting['type'], $setting ) );

					} elseif ( method_exists( $this, 'default_field_' . $setting['type'] ) ) {

						$defaults = array_merge( $defaults, call_user_func( array( $this, 'default_field_' . $setting['type'] ) ) );

					}

					$setting = array_merge( $defaults, $setting );

					$setting = apply_filters( 'wpf_configure_setting_' . $id, $setting, $this->options );

					/**
					 * "field_begin" override
					 */
					if ( has_action( 'show_field_' . $id . '_begin' ) ) {

						// If there's a "field begin" override for this specific field
						do_action( 'show_field_' . $id . '_begin', $id, $setting );

					} elseif ( has_action( 'show_field_' . $setting['type'] . '_begin' ) ) {

						// If there's a "field begin" override for this specific field type
						do_action( 'show_field_' . $setting['type'] . '_begin', $id, $setting );

					} elseif ( has_action( 'show_field_begin' ) ) {

						// If there's a "field begin" override for all fields
						do_action( 'show_field_begin', $id, $setting );

					} elseif ( method_exists( $this, 'show_field_' . $setting['type'] . '_begin' ) ) {

						// If a custom override has been supplied in this file
						call_user_func( array( $this, 'show_field_' . $setting['type'] . '_begin' ), $id, $setting );

					} else {

						// If no override, use the default
						$this->show_field_begin( $id, $setting );
					}

					/**
					 * "show_field" override
					 */

					// Allow filtering setting strings (removed in v3.37.0 in favor of wpf_configure_setting_ )
					// $setting = apply_filters( 'wpf_pre_show_field_settings', $setting, $id );

					if ( has_action( 'show_field_' . $id ) ) {

						// If there's a "show field" override for this specific field
						do_action( 'show_field_' . $id, $id, $setting );

					} elseif ( has_action( 'show_field_' . $setting['type'] ) ) {

						do_action( 'show_field_' . $setting['type'], $id, $setting );
					} else {
						// If no custom override, use the default
						call_user_func( array( $this, 'show_field_' . $setting['type'] ), $id, $setting );
					}

					/**
					 * "field_end" override
					 */

					if ( has_action( 'show_field_' . $id . '_end' ) ) {

						// If there's a "field end" override for this specific field
						do_action( 'show_field_' . $id . '_end', $id, $setting );

					} elseif ( has_action( 'show_field_' . $setting['type'] . '_end' ) ) {

						// If there's a "field begin" override for this specific field type
						do_action( 'show_field_' . $setting['type'] . '_end', $id, $setting );
					} elseif ( has_action( 'show_field_end' ) ) {

						// If there's a "field begin" override for all fields
						do_action( 'show_field_end', $id, $setting );

					} elseif ( method_exists( $this, 'show_field_' . $setting['type'] . '_end' ) ) {

						// If a custom override has been supplied in this file
						call_user_func( array( $this, 'show_field_' . $setting['type'] . '_end' ), $id, $setting );
					} else {

						// If no override, use the default
						$this->show_field_end( $id, $setting );
					}
				}
			}
			?>
		</table>
		<?php
	}

	/*
	----------------------------------------------------------------
	 *
	 * Functions to handle display and validation of individual fields
	 *
	 *----------------------------------------------------------------

	/**
	 *
	 * Default field handlers
	 *
	 */

	/**
	 * Begin field.
	 *
	 * @param string $id
	 * @param array  $field
	 *
	 * @since  0.1
	 * @access private
	 */
	private function show_field_begin( $id, $field ) {
		echo '<tr valign="top"' . ( ! empty( $field['disabled'] ) ? ' class="disabled"' : '' ) . '>';
		echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['title'] ) . '</label>';

		if ( isset( $field['tooltip'] ) ) {
			echo ' <i class="fa fa-question-circle wpf-tip wpf-tip-right" data-tip="' . esc_attr( $field['tooltip'] ) . '"></i>';
		}

		echo '</th>';
		echo '<td>';
	}

	/**
	 * End field.
	 *
	 * @param string $id
	 * @param array  $field
	 *
	 * @since  0.1
	 * @access private
	 */
	private function show_field_end( $id, $field ) {

		if ( ! empty( $field['desc'] ) ) {
			echo '<span class="description">' . wp_kses_post( $field['desc'] ) . '</span>';
		}
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Validate field
	 *
	 * @param mixed   $input
	 * @param       $setting
	 *
	 * @return mixed
	 */
	private function validate_field_default( $input, $setting = false ) {

		if ( is_array( $input ) ) {
			$input = array_map( array( $this, 'validate_field_default' ), $input );
		} else {
			$input = sanitize_text_field( $input );
		}

		return $input;
	}


	/**
	 *
	 * Heading field
	 */

	/**
	 * Show Heading field begin
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_heading_begin( $id, $field ) {

		echo '</table>';
	}

	/**
	 * Show Heading field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_heading( $id, $field ) {

		if ( ! empty( $field['title'] ) ) {
			echo '<h4>';
			echo esc_html( $field['title'] );

			if ( ! empty( $field['url'] ) ) {
				echo '<a class="header-docs-link" href="' . esc_url( $field['url'] ) . '" target="_blank">' . esc_html__( 'View documentation', 'wp-fusion-lite' ) . ' &rarr;</a>';
			}

			echo '</h4>';
		}

		if ( ! empty( $field['desc'] ) ) {
			echo wp_kses_post( $field['desc'] );
		}
	}

	/**
	 * Show Heading field end.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_heading_end( $id, $field ) {

		echo '<table class="form-table">';
	}

	/**
	 *
	 * Paragraph field
	 */

	/**
	 * Show Paragraph field begin
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_paragraph_begin( $id, $field ) {
		// Dont output tr
	}

	/**
	 * Show Paragraph field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_paragraph( $id, $field ) {
		if ( isset( $field['title'] ) ) {
			echo '<h3 class="title">' . esc_html( $field['title'] ) . '</h3>';
		}

		echo '<p' . ( $field['class'] ? ' class="' . esc_attr( $field['class'] ) . '"' : '' ) . '>' . $field['desc'] . '</p>'; // yes $field['desc'] should be escaped but we sometimes need to output SVG content (such as in the webhooks section overview), and I can't find the right config for wp_kses_allowed_html. Please forgive me...
	}

	/**
	 * Show Paragraph field end.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_paragraph_end( $id, $field ) {
		// don't output /tr
	}

	/**
	 * Defaults for text field
	 *
	 * @return array $args
	 */
	private function default_field_text() {

		$args = array(
			'format'   => null,
			'class'    => '',
			'disabled' => false,
		);

		return $args;
	}

	/**
	 * Show field Text.
	 *
	 * @param        $id
	 * @param string $field
	 *
	 * @param null   $subfield_id
	 *
	 * @since  0.1
	 * @access private
	 */
	public function show_field_text( $id, $field, $subfield_id = null ) {

		if ( ! isset( $field['class'] ) ) {
			$field['class'] = '';
		}

		if ( ! isset( $field['disabled'] ) ) {
			$field['disabled'] = false;
		}

		if ( empty( $field['std'] ) && ! empty( $field['placeholder'] ) ) {
			$field['std'] = $field['placeholder'];
		}

		if ( isset( $field['format'] ) && $field['format'] == 'phone' ) {
			echo '<input id="' . esc_attr( $id ) . '" class="form-control bfh-phone ' . esc_attr( $field['class'] ) . '" data-format="(ddd) ddd-dddd" type="text" id="' . esc_attr( $id ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" placeholder="' . esc_attr( $field['std'] ) . '" value="' . esc_attr( $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>';
		} else {
			echo '<input id="' . esc_attr( $id ) . '" class="form-control ' . esc_attr( $field['class'] ) . '" type="text" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" placeholder="' . esc_attr( $field['std'] ) . '" value="' . esc_attr( $this->options[ $id ] ) . '" ' . ( $field['disabled'] || $field['input_disabled'] ? 'disabled="true"' : '' ) . '>';
		}
	}

	/**
	 * Validate Text field.
	 *
	 * @param string  $input
	 *
	 * @param        $setting
	 *
	 * @return string $input
	 */
	public function validate_field_text( $input, $setting ) {

		if ( $setting['format'] == 'phone' ) {
			// Remove all non-number characters
			$input = preg_replace( '/[^0-9]/', '', $input );

			// if we have 10 digits left, it's probably valid.
			if ( strlen( $input ) == 10 ) {

				return $input;
			} else {

				return new WP_Error( 'error', __( 'Invalid phone number.' ), $input );
			}
		} elseif ( $setting['format'] == 'zip' ) {

			if ( preg_match( '/^\d{5}$/', $input ) ) {

				return (int) $input;
			} else {

				return new WP_Error( 'error', __( 'Invalid ZIP code.' ), $input );
			}
		} elseif ( $setting['format'] == 'html' ) {

			return wp_kses_post( stripslashes( $input ) );

		} else {

			return stripslashes( sanitize_text_field( $input ) );

		}
	}

	/**
	 *
	 * Textarea field
	 */

	/**
	 * Defaults for textarea field
	 *
	 * @return array $args
	 */
	private function default_field_textarea() {

		$args = array(
			'rows' => 5,
			'cols' => 39,
		);

		return $args;
	}

	/**
	 * Show Textarea field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_textarea( $id, $field ) {

		echo '<textarea class="form-control ' . esc_attr( $field['class'] ) . '" id="' . esc_attr( $id ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" placeholder="' . esc_attr( $field['placeholder'] ) . '" rows="' . (int) $field['rows'] . '" cols="' . (int) $field['cols'] . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>' . format_for_editor( $this->options[ $id ] ) . '</textarea>';
	}

	/**
	 * Validate Textarea field.
	 *
	 * @param string  $input
	 *
	 * @param        $setting
	 *
	 * @return string $input
	 */
	public function validate_field_textarea( $input, $setting ) {

		return sanitize_textarea_field( $input );
	}

	/**
	 *
	 * Checkbox field
	 */

	/**
	 * Show Checkbox field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_checkbox( $id, $field ) {

		$unlock = '';

		if ( isset( $field['unlock'] ) ) {

			foreach ( $field['unlock'] as $target ) {
				$unlock .= $target . ' ';
			}
		}

		if ( isset( $field['lock'] ) ) {

			foreach ( $field['lock'] as $target ) {
				$unlock .= $target . ' ';
			}
		}

		if ( ! isset( $field['class'] ) ) {
			$field['class'] = '';
		}

		echo '<input class="checkbox ' . esc_attr( $field['class'] ) . '" type="checkbox" id="' . esc_attr( $id ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" value="1" ' . checked( $this->options[ $id ], 1, false ) . ' ' . ( isset( $field['disabled'] ) && $field['disabled'] == true ? 'disabled="true"' : '' ) . ' ' . ( ! empty( $unlock ) ? 'data-unlock="' . esc_attr( trim( $unlock ) ) . '"' : '' ) . ' />';

		if ( $field['desc'] != '' ) {
			echo '<label for="' . esc_attr( $id ) . '">' . wp_kses_post( $field['desc'] ) . '</label>';
		}

	}

	/**
	 * Checkbox end field
	 *
	 * @param        $id
	 * @param string $field
	 *
	 * @access private
	 */
	private function show_field_checkbox_end( $id, $field ) {

		echo '</td>';
		echo '</tr>';
	}

	/**
	 *
	 * Checkboxes field
	 */

	/**
	 * Show Checkboxes field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_checkboxes( $id, $field ) {

		if ( ! is_array( $this->options[ $id ] ) ) {
			$this->options[ $id ] = array();
		}

		foreach ( $field['options'] as $value => $label ) {

			if ( ! isset( $this->options[ $id ][ $value ] ) ) {
				$this->options[ $id ][ $value ] = false;
			}

			echo '<input class="checkbox ' . esc_attr( $field['class'] ) . '" type="checkbox" id="' . esc_attr( $id ) . '-' . esc_attr( $value ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . '][' . esc_attr( $value ) . ']" value="1" ' . checked( $this->options[ $id ][ $value ], 1, false ) . ' ' . ( isset( $field['disabled'] ) && $field['disabled'] == true ? 'disabled="true"' : '' ) . ' />';

			echo '<label for="' . esc_attr( $id ) . '-' . esc_attr( $value ) . '">' . esc_html( $label ) . '</label><br />';

		}

		echo '<br />';

	}

	/**
	 *
	 * Radio field
	 */

	/**
	 * Defaults for radio field
	 *
	 * @return array $args
	 */
	private function default_field_radio() {

		$args = array(
			'choices' => array(),
		);

		return $args;
	}

	/**
	 * Show Radio field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_radio( $id, $field ) {

		$i = 0;

		foreach ( $field['choices'] as $value => $label ) {

			echo '<input class="radio ' . esc_attr( $field['class'] ) . '" type="radio" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" id="' . esc_attr( $id . $i ) . '" value="' . esc_attr( $value ) . '" ' . checked( $this->options[ $id ], $value, false ) . ' ' . ( $field['disabled'] ? 'disabled=true' : '' ) . '>';
			echo '<label for="' . esc_attr( $id . $i ) . '">' . esc_html( $label ) . '</label>';

			if ( $i < count( $field['choices'] ) - 1 ) {
				echo '<br />';
			}

			$i++;
		}
	}

	/**
	 *
	 * Multi-select field
	 */

	/**
	 * Defaults for multi-select field
	 *
	 * @return array $args
	 */
	private function default_field_multi_select() {

		$args = array(
			'choices' => array(),
		);

		return $args;
	}

	/**
	 * Show Multi-select field.
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_multi_select( $id, $field, $subfield_id = null ) {

		if ( empty( $this->options[ $id ] ) ) {
			$this->options[ $id ] = array();
		}

		echo '<select multiple="multiple" id="' . esc_attr( $id ) . '" class="select4 ' . esc_attr( $field['class'] ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . '][]" ' . ( $field['disabled'] ? 'disabled="true" ' : '' ) . ( $field['placeholder'] ? 'data-placeholder="' . esc_attr( $field['placeholder'] ) . '" ' : '' ) . '>';

		if ( isset( $field['placeholder'] ) ) {
			echo '<option></option>';
		}

		foreach ( (array) $field['choices'] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( true, in_array( $value, $this->options[ $id ] ), false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 *
	 * Select field
	 */

	/**
	 * Defaults for select field
	 *
	 * @return array $args
	 */
	private function default_field_select() {

		$args = array(
			'choices'    => array(),
		);

		return $args;
	}

	/**
	 * Show Select field.
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_select( $id, $field, $subfield_id = null ) {

		if ( ! isset( $field['allow_null'] ) ) {
			if ( empty( $field['std'] ) ) {
				$field['allow_null'] = true;
			} else {
				$field['allow_null'] = false;
			}
		}

		if ( ! isset( $field['placeholder'] ) ) {
			$field['placeholder'] = false;
		}

		$unlock = '';

		if ( isset( $field['unlock'] ) ) {

			foreach ( $field['unlock'] as $target ) {
				$unlock .= $target . ' ';
			}
		}

		if ( count( $field['choices'] ) > 10 ) {
			$field['class'] .= 'select4-search';
		}

		echo '<select id="' . esc_attr( $id ) . '" class="select4 ' . esc_attr( $field['class'] ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . ' ' . ( $field['placeholder'] ? 'data-placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '' ) . ' ' . ( $field['allow_null'] == false ? 'data-allow-clear="false"' : '' ) . ' ' . ( ! empty( $unlock ) ? 'data-unlock="' . esc_attr( trim( $unlock ) ) . '"' : '' ) . '>';
		if ( $field['allow_null'] == true || ! empty( $field['placeholder'] ) ) {
			echo '<option></option>';}

		if ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			foreach ( $field['choices'] as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $this->options[ $id ], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
		}

		echo '</select>';

	}

	/**
	 *
	 * Number / slider / date / time fields
	 */

	/**
	 * Defaults for number field
	 *
	 * @return array $args
	 */
	private function default_field_number() {

		$args = array(
			'min'  => 0,
			'max'  => null,
			'step' => 1,
		);

		return $args;
	}

	/**
	 * Show Number field.
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_number( $id, $field, $subfield_id = null ) {

		if ( empty( $this->options[ $id ] ) ) {
			$this->options[ $id ] = 0;
		}

		echo '<input id="' . esc_attr( $id ) . '" type="number" class="select form-control ' . esc_attr( $field['class'] ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" min="' . (int) $field['min'] . '" ' . ( $field['max'] ? 'max=' . (int) $field['max'] : '' ) . ' step="' . floatval( $field['step'] ) . '" value="' . esc_attr( $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>';
	}

	/**
	 * Validate number field
	 *
	 * @param mixed   $input
	 *
	 * @param       $setting
	 *
	 * @return mixed|\WP_Error
	 */
	public function validate_field_number( $input, $setting ) {

		if ( ! empty( $setting['min'] ) && $input < $setting['min'] ) {
			return new WP_Error( 'error', __( 'Number must be greater than or equal to ' . $setting['min'] . '.' ), $input );
		} elseif ( $input > $setting['max'] && $setting['max'] != null ) {
			return new WP_Error( 'error', __( 'Number must be less than or equal to ' . $setting['max'] . '.' ), $input );
		} else {

			return floatval( $input );
		}
	}

	/**
	 * Defaults for slider field
	 *
	 * @return array $args
	 */
	private function default_field_slider() {

		$args = array(
			'min' => 0,
			'max' => 100,
		);

		return $args;
	}

	/**
	 * Show Slider field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_slider( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-slider ' . esc_attr( $field['class'] ) . '" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-min="' . (int) $field['min'] . '" ' . ( $field['max'] ? 'data-max=' . (int) $field['max'] : '' ) . ' data-value="' . esc_attr( $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '></div>';
	}

	/**
	 * Defaults for date field
	 *
	 * @return array $args
	 */
	private function default_field_date() {

		$args = array(
			'date'   => 'today',
			'format' => 'm/d/y',
			'min'    => null,
			'max'    => null,
		);

		return $args;
	}

	/**
	 * Show Date field.
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_date( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-datepicker ' . esc_attr( $field['class'] ) . '" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-format="' . esc_attr( $field['format'] ) . '" data-date="' . esc_attr( $this->options[ $id ] ) . '" data-min="' . (int) $field['min'] . '" data-max="' . (int) $field['max'] . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '></div>';
	}

	/**
	 * Defaults for time field
	 *
	 * @return array $args
	 */
	private function default_field_time() {

		$args = array(
			'time' => 'now',
		);

		return $args;
	}

	/**
	 * Show Time field.
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_time( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-timepicker ' . esc_attr( $field['class'] ) . '" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-time="' . esc_attr( $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '></div>';
	}

	/**
	 *
	 * Hidden field
	 */

	/**
	 * Hidden field begin
	 *
	 * @param        $id
	 * @param string $field
	 *
	 * @access private
	 */
	private function show_field_hidden_begin( $id, $field ) {
	}

	/**
	 * Show Hidden field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_hidden( $id, $field ) {

		echo '<input id="' . esc_attr( $id ) . '" type="hidden" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" value="' . esc_attr( $this->options[ $id ] ) . '">';

	}

	/**
	 * Hidden field end
	 *
	 * @param        $id
	 * @param string $field
	 *
	 * @access private
	 */
	private function show_field_hidden_end( $id, $field ) {
	}

	/**
	 * Validate hidden field
	 *
	 * @param mixed   $input
	 *
	 * @param       $setting
	 *
	 * @return mixed|\WP_Error
	 */
	public function validate_field_hidden( $input, $setting ) {

		if ( '1' === $input ) {
			$input = true;
		}

		return $input;

	}

	/**
	 *
	 * Password field
	 */

	/**
	 * Show password field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_password( $id, $field, $subfield_id = null ) {

		if ( ! isset( $field['class'] ) ) {
			$field['class'] = '';
		}

		if ( ! isset( $field['disabled'] ) ) {
			$field['disabled'] = false;
		}

		// Passwords with slashes in them get escaped on save so we'll un-slash them here

		$this->options[ $id ] = stripslashes( $this->options[ $id ] );

		echo '<input id="' . esc_attr( $id ) . '" class="form-control ' . esc_attr( $field['class'] ) . '" type="password" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" placeholder="' . esc_attr( $field['std'] ) . '" value="' . esc_attr( $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>';

	}

	/**
	 *
	 * Code editor field
	 */

	/**
	 * Defaults for code editor field
	 *
	 * @return array $args
	 */

	private function default_field_code() {
		$args = array(
			'theme' => 'default',
			'lang'  => 'php',
		);
		return $args;
	}

	/**
	 * Show code editor field
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_code( $id, $field ) {

		echo '<textarea id="' . esc_attr( $id ) . '" class="code_text ' . esc_attr( $field['class'] ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-lang="' . esc_attr( $field['lang'] ) . '" data-theme="' . esc_attr( $field['theme'] ) . '">' . ( ! empty( $this->options[ $id ] ) ? esc_textarea( stripslashes( $this->options[ $id ] ) ) : '' ) . '</textarea>';
	}

	/**
	 *
	 * Font picker fields
	 */

	/**
	 * Defaults for font size field
	 *
	 * @return array $args
	 */
	private function default_field_font_size() {

		$args = array(
			'min' => 12,
			'max' => 72,
		);

		return $args;
	}

	/**
	 * Show font size field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_font_size( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-selectbox bfh-fontsizes ' . esc_attr( $field['class'] ) . '"" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-fontsize="' . esc_attr( $this->options[ $id ] ) . '" data-blank="false"></div>';
	}

	/**
	 * Show font face field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_font_face( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-selectbox bfh-googlefonts ' . esc_attr( $field['class'] ) . '"" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-font="' . esc_attr( $this->options[ $id ] ) . '"></div>';
	}

	/**
	 * Show font weight field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_font_weight( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-selectbox ' . esc_attr( $field['class'] ) . '"" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-value="' . esc_attr( $this->options[ $id ] ) . '">
		  <div data-value="100">100</div>
		  <div data-value="200">200</div>
		  <div data-value="300">300</div>
		  <div data-value="400">400</div>
		  <div data-value="500">500</div>
		  <div data-value="600">600</div>
		  <div data-value="700">700</div>
		  <div data-value="800">800</div>
		  <div data-value="900">900</div>
		</div>';
	}

	/**
	 * Show font style field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_font_style( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-selectbox ' . esc_attr( $field['class'] ) . '"" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-value="' . esc_attr( $this->options[ $id ] ) . '">
		  <div data-value="normal">Normal</div>
		  <div data-value="italic">Italic</div>
		</div>';
	}

	/**
	 * Show color field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_color( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-colorpicker ' . esc_attr( $field['class'] ) . '"" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-color="' . esc_attr( $this->options[ $id ] ) . '" data-close="false"></div>';
	}

	/**
	 * Validate color field.
	 *
	 * @param string  $input
	 *
	 * @param        $setting
	 *
	 * @return string $input
	 */
	public function validate_field_color( $input, $setting ) {

		if ( preg_match( '/^#[a-f0-9]{6}$/i', $input ) ) {
			return $input;
		} else {
			return new WP_Error( 'error', __( 'Invalid color code.' ), $input );
		}
	}

	/**
	 *
	 * Location fields
	 */

	/**
	 * Defaults for state field
	 *
	 * @return array $args
	 */
	private function default_field_state() {

		$args = array(
			'country' => 'US',
		);

		return $args;
	}

	/**
	 * Show state field
	 *
	 * @param      $id
	 * @param      $field
	 * @param null  $subfield_id
	 *
	 * @internal param string $input
	 *
	 * @return string $input
	 */
	private function show_field_state( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-selectbox bfh-states ' . esc_attr( $field['class'] ) . '"" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-country="' . esc_attr( $field['country'] ) . '" data-state="' . esc_attr( $this->options[ $id ] ) . '"></div>';
	}

	/**
	 * Defaults for country field
	 *
	 * @return array $args
	 */
	private function default_field_country() {

		$args = array(
			'country' => 'US',
		);

		return $args;
	}

	/**
	 * Show country field
	 *
	 * @param      $id
	 * @param      $field
	 * @param null  $subfield_id
	 *
	 * @internal param string $input
	 *
	 * @return string $input
	 */
	private function show_field_country( $id, $field, $subfield_id = null ) {

		echo '<div id="' . esc_attr( $id ) . '" class="bfh-selectbox bfh-countries ' . esc_attr( $field['class'] ) . '"" data-name="' . $this->option_group . '[' . esc_attr( $id ) . ']" data-flags="true" data-country="' . esc_attr( $this->options[ $id ] ) . '"></div>';
	}

	/**
	 *
	 * WYSIWYG editor field
	 */

	/**
	 * Defaults for editor field
	 *
	 * @return array $args
	 */
	private function default_field_editor() {

		$args = array(
			'media_buttons' => true,
			'wpautop'       => true,
			'textarea_rows' => get_option( 'default_post_edit_rows', 10 ),
			'editor_css'    => '',
		);

		return $args;
	}

	/**
	 * Show editor field
	 *
	 * @param string $id
	 * @param array  $field
	 */

	private function show_field_editor( $id, $field ) {

		$settings = array(
			'editor_class'  => 'at-wysiwyg ' . $field['class'],
			'textarea_name' => $this->option_group . '[' . esc_attr( $id ) . ']',
			'media_buttons' => $field['media_buttons'],
			'wpautop'       => $field['wpautop'],
			'textarea_rows' => $field['textarea_rows'],
			'editor_css'    => $field['editor_css'],
		);

		wp_editor( stripslashes( stripslashes( html_entity_decode( $this->options[ $id ] ) ) ), $id, $settings );
	}

	/**
	 * Validate editor field.
	 *
	 * @since  3.38.15
	 *
	 * @param  mixed $input   The HTML content.
	 * @param  bool  $setting The setting.
	 * @return mixed HTML content.
	 */
	public function validate_field_editor( $input, $setting = false ) {

		return wp_kses_post( $input );

	}


	/**
	 *
	 * Reset options field
	 */

	/**
	 * Show Reset field.
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_reset( $id, $field ) {

		echo '<input class="checkbox warning" type="checkbox" id="' . esc_attr( $id ) . '" name="' . $this->option_group . '[' . esc_attr( $id ) . ']" value="1" ' . checked( $this->options[ $id ], 1, false ) . ' />';

		if ( $field['desc'] != '' ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['desc'] ) . '</label>';
		}
	}

	/**
	 * Reset field end
	 *
	 * @param string $id
	 * @param array  $field
	 *
	 * @access private
	 */
	private function show_field_reset_end( $id, $field ) {

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Validates input field
	 *
	 * @param  bool    $input
	 *
	 * @param       $setting
	 *
	 * @return bool $input
	 */
	public function validate_field_reset( $input, $setting ) {

		if ( ! empty( $input ) ) {
			$this->reset_options = true;
		}

		return $input;
	}

}
