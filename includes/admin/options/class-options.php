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
class WPF_Options {

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
	protected $post_data;

	// Optional variable to contain additional pages to register
	private $subpages;

	// Will contain all of the options as stored in the database
	protected $options;

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
		'title'    => null,
		'desc'     => null,
		'std'      => null,
		'type'     => 'checkbox',
		'section'  => '',
		'class'    => null,
		'disabled' => false,
		'unlock'   => null,
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
	public function __construct( $setup, $settings, $sections = null, $subpages = null ) {

		// Merge default setup with user-specified setup parameters
		$setup = wp_parse_args( $setup, $this->default_project );

		$this->selfpath = plugin_dir_url( __FILE__ );

		$this->setup                      = $setup;
		$this->sections                   = $sections;
		$this->subpages                   = $subpages;
		$this->settings                   = $settings;
		$this->default_setting['section'] = $setup['slug'];

		// Load option group
		$this->option_group = $setup['option_group'];
		$this->options      = get_option( 'wpf_options', array() );

		// Start it up
		add_action( 'init', array( $this, 'init' ) );

	}

	public function init() {

		if ( isset( $_GET['page'] ) && $_GET['page'] == 'wpf-settings' ) {

			do_action( 'wpf_settings_page_init' );

			// Set options based on configured settings
			//$this->options                 = apply_filters( $this->setup['project_slug'] . '_initialize_options', $this->options );
			//wp_fusion()->settings->options = $this->options;

			// Load in all pluggable settings
			$settings = apply_filters( $this->setup['project_slug'] . '_configure_settings', $this->settings, $this->options );

			// Initialize settings to default values
			$this->settings = $this->initialize_settings( $settings );

			if ( isset( $_POST['action'] ) && $_POST['action'] == 'update' && isset( $_POST[ $this->setup['project_slug'] . '_nonce' ] ) ) {

				$this->save_options();

				$this->initialize_settings( $this->options );

				// Reconfigure settings based on saved options
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
			return;}

		if ( ! wp_verify_nonce( $nonce, $this->setup['project_slug'] ) ) {
			die( 'Security check. Invalid nonce.' );
		}

		// Get array of form data
		if ( array_key_exists( $this->option_group, $_POST ) ) {
			$this->post_data = $_POST[ $this->option_group ];
		} else {
			$this->post_data = array();
		}

		// For each settings field, run the input through it's defined validation function
		$settings = $this->settings;

		// Beydefault $_POST ignores checkboxes with no value set, so we need to iterate through
		// all defined checkboxes and set their value to 0 if they haven't been set in the input
		if ( isset( $this->checkboxes ) ) {
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

			if ( isset( $this->post_data[ $id ] ) && ! isset( $setting['subfields'] ) ) {

				$this->post_data[ $id ] = $this->validate_options( $id, $this->post_data[ $id ], $setting );

			} elseif ( isset( $this->post_data[ $id ] ) && isset( $setting['subfields'] ) ) {

				foreach ( $this->post_data[ $id ] as $sub_id => $subfield ) {

					if ( isset( $this->post_data[ $id ][ $sub_id ] ) ) {

						$this->post_data[ $id ][ $sub_id ] = $this->validate_options( $sub_id, $this->post_data[ $id ][ $sub_id ], $setting['subfields'][ $sub_id ] );

					}
				}
			}
		}

		if ( $this->reset_options ) {

			delete_option( $this->option_group );
			$this->options = null;

			// Rebuild defaults and apply filters
			$settings       = $this->initialize_settings( $this->settings );
			$this->settings = apply_filters( $this->setup['project_slug'] . '_configure_settings', $settings, $this->options );

		} else {

			// Merge the form data with the existing options, updating as necessary
			$this->options = wp_parse_args( $this->post_data, $this->options );

			// Re-do init
			$this->options = apply_filters( $this->setup['project_slug'] . '_initialize_options', $this->options );

			// Update the option in the database
			update_option( $this->option_group, $this->options, false );

			// Update the options within the class
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

			// If an input fails validation, put the error message into the errors array for display
			$this->errors[ $id ] = $input->get_error_message();
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
	 * Checks for new settings fields and sets them to default values
	 *
	 * @access private
	 *
	 * @param $settings array
	 *
	 * @return array $settings The settings array
	 * @return array $options The options array
	 */

	private function initialize_settings( $settings ) {

		$options      = get_option( $this->option_group );
		$needs_update = false;

		foreach ( $settings as $id => $setting ) {

			$setting = wp_parse_args( $setting, $this->default_setting );

			// Set default values from global setting default template
			$settings[ $id ] = $setting;

			if ( $setting['type'] == 'checkbox' ) {
				$this->checkboxes[] = $id;
			}

			if ( $setting['type'] == 'multi_select' ) {
				$this->multi_selects[] = $id;
			}

			if ( $setting['type'] == 'checkboxes' ) {
				$this->multi_selects[] = $id;
			}

			if ( $setting['type'] == 'assign_tags' ) {
				$this->multi_selects[] = $id;
			}

			// If a custom setting template has been specified, load those values as well
			if ( has_filter( 'default_field_' . $setting['type'] ) ) {
				$settings[ $id ] = wp_parse_args( $settings[ $id ], apply_filters( 'default_field_' . $setting['type'], $setting ) );
			} elseif ( method_exists( $this, 'default_field_' . $setting['type'] ) ) {
				$settings[ $id ] = wp_parse_args( $settings[ $id ], call_user_func( array( $this, 'default_field_' . $setting['type'] ) ) );
			}

			// Load the array of settings currently in use
			if ( ! isset( $this->fields[ $setting['type'] ] ) ) {
				$this->fields[ $setting['type'] ] = true;
			}

			// Set the default value if no option exists
			if ( ! isset( $options[ $id ] ) && isset( $settings[ $id ]['std'] ) ) {

				$needs_update   = true;
				$options[ $id ] = $settings[ $id ]['std'];

			} elseif ( ! isset( $options[ $id ] ) && ! isset( $settings[ $id ]['std'] ) ) {

				// If no default has been specified, set the option to an empty string (to prevent PHP notices)
				$needs_update   = true;
				$options[ $id ] = '';
			}

			// Set defaults for subfields if any subfields are present
			if ( isset( $setting['subfields'] ) ) {
				foreach ( $setting['subfields'] as $sub_id => $sub_setting ) {

					// Fill in missing parts of the array
					$settings[ $id ]['subfields'][ $sub_id ] = wp_parse_args( $sub_setting, $this->default_setting );

					if ( method_exists( $this, 'default_field_' . $sub_setting['type'] ) ) {
						$settings[ $id ]['subfields'][ $sub_id ] = wp_parse_args( $settings[ $id ]['subfields'][ $sub_id ], call_user_func( array( $this, 'default_field_' . $sub_setting['type'] ) ) );
					}

					// Set default value if needed
					if ( ! isset( $options[ $id ][ $sub_id ] ) && isset( $setting['subfields'][ $sub_id ]['std'] ) ) {
						$options[ $id ][ $sub_id ] = $setting['subfields'][ $sub_id ]['std'];
					} elseif ( ! isset( $options[ $id ][ $sub_id ] ) && ! isset( $setting['subfields'][ $sub_id ]['std'] ) ) {
						$options[ $id ][ $sub_id ] = '';
					}
				}
			}
		}

		// If new options have been added, set their default values
		if ( $needs_update ) {
			update_option( $this->option_group, $options, false );
			$this->options                 = $options;
			wp_fusion()->settings->options = $options;
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

		// Create an array to contain all pages, and add the main setup page (registered with $setup)
		$pages = array(
			$this->setup['slug'] => array(
				'menu'       => $this->setup['menu'],
				'page_title' => $this->setup['page_title'],
				'menu_title' => $this->setup['menu_title'],
				'capability' => $this->setup['capability'],
				'page_icon'  => $this->setup['page_icon'],
				'icon_url'   => $this->setup['icon_url'],
				'position'   => $this->setup['position'],
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
		wp_enqueue_script( 'bootstrap-formhelpers', $this->selfpath . 'lib/bootstrap-formhelpers/bootstrap-formhelpers.min.js', array( 'jquery', 'bootstrap' ) );
		wp_enqueue_script( 'options-js', $this->selfpath . 'js/options.min.js', array( 'jquery', 'select4' ) );

		wp_enqueue_script( 'jquery-ui-sortable' );

		// wp_enqueue_style('bootstrap', $this->selfpath.'css/bootstrap.min.css');
		wp_enqueue_style( 'fontawesome', $this->selfpath . 'css/font-awesome.min.css' );
		wp_enqueue_style( 'options-css', $this->selfpath . 'css/options.css' );

		// Enqueue TinyMCE editor
		if ( isset( $this->fields['editor'] ) ) {
			wp_print_scripts( 'editor' );
		}

		// Enqueue codemirror js and css
		if ( isset( $this->fields['code'] ) ) {
			wp_enqueue_style( 'at-code-css', $this->selfpath . 'lib/codemirror/codemirror.css', array(), null );
			wp_enqueue_style( 'at-code-css-dark', $this->selfpath . 'lib/codemirror/twilight.css', array(), null );
			wp_enqueue_script( 'at-code-lib', $this->selfpath . 'lib/codemirror/codemirror.js', array( 'jquery' ), false, true );
			wp_enqueue_script( 'at-code-lib-xml', $this->selfpath . 'lib/codemirror/xml.js', array( 'jquery' ), false, true );
			wp_enqueue_script( 'at-code-lib-javascript', $this->selfpath . 'lib/codemirror/javascript.js', array( 'jquery' ), false, true );
			wp_enqueue_script( 'at-code-lib-css', $this->selfpath . 'lib/codemirror/css.js', array( 'jquery' ), false, true );
			wp_enqueue_script( 'at-code-lib-clike', $this->selfpath . 'lib/codemirror/clike.js', array( 'jquery' ), false, true );
			wp_enqueue_script( 'at-code-lib-php', $this->selfpath . 'lib/codemirror/php.js', array( 'jquery' ), false, true );
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
			do_action( $this->setup['project_slug'] . '_enqueue_scripts' );
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

		<div class="wrap">
		<img id="wpf-settings-logo" src="<?php echo WPF_DIR_URL; ?>/assets/img/logo-sm-trans.png">
		<h2 id="wpf-settings-header"><?php echo $page['page_title']; ?> <?php do_action( 'wpf_settings_page_title' ); ?></h2>

		<?php do_action( 'wpf_settings_after_page_title' ); ?>

		<?php
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
				echo '<div id="message" class="error"><p><i class="fa fa-warning"></i>' . $error_message . '</p></div>';
				echo '<style type="text/css">#' . $id . '{ border: 1px solid #d00; }</style>';
			}
		}

		?>

		<form id="<?php echo $page['slug']; ?>" class="
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
		<?php foreach ( $page['sections'] as $section_slug => $section ) { ?>

						<?php if ( ! is_array( $section ) ) : ?>

							<li id="tab-<?php echo $section_slug; ?>" 
													<?php
													if ( $isfirst ) {
														echo "class='active'"; }
?>
>
								<a href="#<?php echo $section_slug; ?>" data-toggle="tab"><?php echo $section; ?></a>
							</li>

						<?php else : ?>

							<?php if ( isset( $section['url'] ) ) : ?>

								<li id="tab-<?php echo $section_slug; ?>"> 
									<a href="<?php echo $section['url']; ?>"><?php echo $section['title']; ?></a>
								</li>

							<?php elseif ( isset( $section['slug'] ) ) : ?>

								<li id="tab-<?php echo $section_slug; ?>"> 
									<a href="<?php menu_page_url( $section['slug'] ); ?>"><?php echo $section['title']; ?></a>
								</li>

							<?php endif; ?>

						<?php endif; ?>

						<?php $isfirst = false; ?>

					<?php } ?>
							</ul>

						<?php } ?>
			<div class="<?php echo $page['id']; ?>-tab-content">
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
" id="<?php echo $section_slug; ?>">
						<?php if ( count( $page['sections'] ) > 1 ) { ?>
							<h3><?php echo $section; ?></h3>
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
			<p class="submit"><input name="Submit" type="submit" class="button-primary" value="<?php _e( 'Save Changes', 'wp-fusion-lite' ); ?>" /></p>
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

					// Allow filtering setting strings
					$setting = apply_filters( 'wpf_pre_show_field_settings', $setting, $id );

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
		echo '<th scope="row"><label for="' . $id . '">' . $field['title'] . '</label>';

		if ( isset( $field['tooltip'] ) ) {
			echo ' <i class="fa fa-question-circle wpf-tip right" data-tip="' . $field['tooltip'] . '"></i>';
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
			echo '<span class="description">' . $field['desc'] . '</span>';
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
	private function validate_field_default( $input, $setting ) {

		return $input;
	}

	/**
	 *
	 * Wrapper for fields with subfields
	 */

	/**
	 * Show subfields field begin
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_subfields_begin( $id, $field ) {
		echo '<tr valign="top">';
		echo '<th scope="row"><label for="' . $id . '">' . $field['title'] . '</label></th>';
		echo '<td class="subfields">';
	}

	/**
	 * Show subfields field
	 *
	 * @param string $id
	 * @param array  $field
	 */
	private function show_field_subfields( $id, $field ) {

		foreach ( $field['subfields'] as $subfield_id => $subfield ) {

			if ( has_action( 'show_field_' . $subfield['type'] ) ) {

				do_action( 'show_field_' . $subfield['type'], $id, $subfield );
			} else {
				// If no custom override, use the default
				call_user_func( array( $this, 'show_field_' . $subfield['type'] ), $id, $subfield, $subfield_id );
			}
		}
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
			echo '<h4>' . $field['title'] . '</h4>';
		}

		if ( ! empty( $field['desc'] ) ) {

			echo $field['desc'];
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
			echo '<h3 class="title">' . $field['title'] . '</h3>';
		}
		echo '<p>' . $field['desc'] . '</p>';
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

		if ( isset( $field['format'] ) && $field['format'] == 'phone' ) {

			echo '<input id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="form-control bfh-phone ' . $field['class'] . '" data-format="(ddd) ddd-dddd" type="text" id="' . $id . '" name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" placeholder="' . $field['std'] . '" value="' . esc_attr( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>';
		} else {

			echo '<input id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="form-control ' . $field['class'] . '" type="text" name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" placeholder="' . $field['std'] . '" value="' . esc_attr( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>';
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

				return $input;
			} else {

				return new WP_Error( 'error', __( 'Invalid ZIP code.' ), $input );
			}
		} elseif ( $setting['format'] == 'html' ) {

			return stripslashes( $input );

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

		echo '<textarea class="form-control ' . $field['class'] . '" id="' . $id . '" name="' . $this->option_group . '[' . $id . ']" placeholder="' . $field['placeholder'] . '" rows="' . $field['rows'] . '" cols="' . $field['cols'] . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>' . format_for_editor( $this->options[ $id ] ) . '</textarea>';
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

		return esc_textarea( $input );
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

		if ( isset( $field['unlock'] ) ) {
			$unlock = '';

			foreach ( $field['unlock'] as $target ) {
				$unlock .= $target . ' ';
			}
		}

		if ( ! isset( $field['class'] ) ) {
			$field['class'] = '';
		}

		echo '<input class="checkbox ' . $field['class'] . '" type="checkbox" id="' . $id . '" name="' . $this->option_group . '[' . $id . ']" value="1" ' . checked( $this->options[ $id ], 1, false ) . ' ' . ( isset( $field['disabled'] ) && $field['disabled'] == true ? 'disabled="true"' : '' ) . ' ' . ( isset( $unlock ) ? 'data-unlock="' . trim( $unlock ) . '"' : '' ) . ' />';

		if ( $field['desc'] != '' ) {
			echo '<label for="' . $id . '">' . $field['desc'] . '</label>';
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

			echo '<input class="checkbox ' . $field['class'] . '" type="checkbox" id="' . $id . '-' . $value . '" name="' . $this->option_group . '[' . $id . '][' . $value . ']" value="1" ' . checked( $this->options[ $id ][ $value ], 1, false ) . ' ' . ( isset( $field['disabled'] ) && $field['disabled'] == true ? 'disabled="true"' : '' ) . ' />';

			echo '<label for="' . $id . '-' . $value . '">' . $label . '</label><br />';

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

			echo '<input class="radio ' . $field['class'] . '" type="radio" name="' . $this->option_group . '[' . $id . ']" id="' . $id . $i . '" value="' . esc_attr( $value ) . '" ' . checked( $this->options[ $id ], $value, false ) . ' ' . ( $field['disabled'] ? 'disabled=true' : '' ) . '><label for="' . $id . $i . '">' . $label . '</label>';

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

		echo '<select multiple="multiple" id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="select4 ' . $field['class'] . '" name="' . $this->option_group . '[' . $id . '][]' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" ' . ( $field['disabled'] ? 'disabled="true" ' : '' ) . ( $field['placeholder'] ? 'data-placeholder="' . $field['placeholder'] . '" ' : '' ) . '>';

		if ( isset( $field['placeholder'] ) ) {
			echo '<option></option>';
		}

		foreach ( (array) $field['choices'] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( true, in_array( $value, $this->options[ $id ] ), false ) . '>' . $label . '</option>';
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
			'allow_null' => false,
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
			$field['allow_null'] = false;
		}

		if ( ! isset( $field['placeholder'] ) ) {
			$field['placeholder'] = false;
		}

		echo '<select id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="select4 ' . $field['class'] . '" name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . ' ' . ( $field['placeholder'] ? 'data-placeholder="' . $field['placeholder'] . '"' : '' ) . ' ' . ( $field['allow_null'] == false ? 'data-allow-clear="false"' : '' ) . '>';
		if ( $field['allow_null'] == true || ! empty( $field['placeholder'] ) ) {
			echo '<option></option>';}

		if ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			foreach ( $field['choices'] as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $this->options[ $id ], $value, false ) . '>' . $label . '</option>';
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
			'min' => 0,
			'max' => null,
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

		echo '<input id="' . ( $subfield_id ? $subfield_id : $id ) . '" type="number" class="select form-control ' . $field['class'] . '" name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" min="' . $field['min'] . '" ' . ( $field['max'] ? 'max=' . $field['max'] : '' ) . ' value="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>';
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

		if ( $input < $setting['min'] ) {

			return new WP_Error( 'error', __( 'Number must be greater than or equal to ' . $setting['min'] . '.' ), $input );
		} elseif ( $input > $setting['max'] && $setting['max'] != null ) {

			return new WP_Error( 'error', __( 'Number must be less than or equal to ' . $setting['max'] . '.' ), $input );
		} else {

			return $input;
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-slider ' . $field['class'] . '" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-min="' . $field['min'] . '" ' . ( $field['max'] ? 'data-max=' . $field['max'] : '' ) . ' data-value="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '></div>';
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-datepicker ' . $field['class'] . '" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-format="' . $field['format'] . '" data-date="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" data-min="' . $field['min'] . '" data-max="' . $field['max'] . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '></div>';
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-timepicker ' . $field['class'] . '" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-time="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '></div>';
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

		echo '<input id="' . $id . '" type="hidden" name="' . $this->option_group . '[' . $id . ']" value="' . $this->options[ $id ] . '">';

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

		echo '<input id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="form-control ' . $field['class'] . '" type="password" name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" placeholder="' . $field['std'] . '" value="' . esc_attr( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>';

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

		echo '<textarea id="' . $id . '" class="code_text ' . $field['class'] . '" name="' . $this->option_group . '[' . $id . ']" data-lang="' . $field['lang'] . '" data-theme="' . $field['theme'] . '">' . ( ! empty( $this->options[ $id ] ) ? stripslashes( $this->options[ $id ] ) : '' ) . '</textarea>';
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-selectbox bfh-fontsizes ' . $field['class'] . '"" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-fontsize="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" data-blank="false"></div>';
	}

	/**
	 * Show font face field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_font_face( $id, $field, $subfield_id = null ) {

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-selectbox bfh-googlefonts ' . $field['class'] . '"" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-font="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '"></div>';
	}

	/**
	 * Show font weight field
	 *
	 * @param string $id
	 * @param array  $field
	 * @param null   $subfield_id
	 */
	private function show_field_font_weight( $id, $field, $subfield_id = null ) {

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-selectbox ' . $field['class'] . '"" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-value="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '">
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-selectbox ' . $field['class'] . '"" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-value="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '">
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-colorpicker ' . $field['class'] . '"" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-color="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '" data-close="false"></div>';
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-selectbox bfh-states ' . $field['class'] . '"" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-country="' . $field['country'] . '" data-state="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '"></div>';
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

		echo '<div id="' . ( $subfield_id ? $subfield_id : $id ) . '" class="bfh-selectbox bfh-countries ' . $field['class'] . '"" data-name="' . $this->option_group . '[' . $id . ']' . ( $subfield_id ? '[' . $subfield_id . ']' : '' ) . '" data-flags="true" data-country="' . ( $subfield_id ? $this->options[ $id ][ $subfield_id ] : $this->options[ $id ] ) . '"></div>';
	}

	/**
	 *
	 * File upload field and utility functions
	 */

	/**
	 * Add data encoding type for file uploading
	 *
	 * @since  0.1
	 * @access public
	 */
	public function add_enctype() {
		echo ' enctype="multipart/form-data"';
	}

	/**
	 * Defaults for file field
	 *
	 * @return array $args
	 */
	private function default_field_file() {

		$args = array(
			'layout' => 'standard',
			'width'  => 250,
			'height' => 150,
		);

		return $args;
	}

	/**
	 * Show file field
	 *
	 * @param $id
	 * @param $field
	 *
	 * @internal param string $input
	 *
	 * @return string $input
	 */
	private function show_field_file( $id, $field ) {

		if ( $field['layout'] == 'image' ) {

			echo '<div class="fileinput fileinput-field fileinput-image ' . ( isset( $this->options[ $id ] ) && $this->options[ $id ] != '' ? 'fileinput-exists' : 'fileinput-new' ) . '" data-provides="fileinput">
			  <div class="fileinput-new thumbnail" style="width: ' . $field['width'] . 'px; height: ' . $field['height'] . 'px;">
			    <img data-src="' . $this->selfpath . 'js/holder.js/' . $field['width'] . 'x' . $field['height'] . '">
			  </div>
			  <div class="fileinput-preview fileinput-exists thumbnail" style="max-width: ' . $field['width'] . 'px; max-height: ' . $field['height'] . 'px;">
			  ' . ( isset( $this->options[ $id ] ) && $this->options[ $id ] != '' ? '<img src="' . $this->options[ $id ] . '">' : '' ) . '
			  </div>
			  <div>
			    <span class="btn btn-default btn-file"><span class="fileinput-new">Select image</span><span class="fileinput-exists">Change</span><input class="fileinput-input" type="text" value="' . ( ! empty( $this->options[ $id ] ) ? $this->options[ $id ] : '' ) . '" name="' . $this->option_group . '[' . $id . ']"></span>
			    <a href="#" class="btn btn-default fileinput-exists" data-dismiss="fileinput">Remove</a>
			  </div>
			</div>';
		} else {

			echo '<div id="' . $id . '" class="fileinput fileinput-field fileinput-file ' . ( isset( $this->options[ $id ] ) && $this->options[ $id ] != '' ? 'fileinput-exists' : 'fileinput-new' ) . '" data-provides="fileinput">
			  <div class="input-group">
			    <div class="form-control uneditable-input span3" data-trigger="fileinput"><i class="fa fa-file-o fileinput-exists"></i> <span class="fileinput-filename">' . basename( $this->options[ $id ] ) . '</span></div>
			    <span class="input-group-addon btn btn-default btn-file"><span class="fileinput-new">Select file</span><span class="fileinput-exists">Change</span><input class="fileinput-input" type="text" value="' . $this->options[ $id ] . '" name="' . $this->option_group . '[' . $id . ']"></span>
			    <a href="#" class="input-group-addon btn btn-default fileinput-exists" data-dismiss="fileinput">Remove</a>
			  </div>
			</div>';
		}
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
			'textarea_name' => $this->option_group . '[' . $id . ']',
			'media_buttons' => $field['media_buttons'],
			'wpautop'       => $field['wpautop'],
			'textarea_rows' => $field['textarea_rows'],
			'editor_css'    => $field['editor_css'],
		);

		wp_editor( stripslashes( stripslashes( html_entity_decode( $this->options[ $id ] ) ) ), $id, $settings );
	}

	/**
	 *
	 * Import, export, and utility functions
	 */

	/**
	 * Show export field
	 *
	 * @param string $id
	 * @param array  $field
	 */

	private function show_field_export( $id, $field ) {

		$nonce = wp_create_nonce( 'export-options' );

		echo '<a id="' . $id . '" class="button button-default" href="' . admin_url( 'admin-post.php?action=export&option_group=' . $this->option_group . '&_wpnonce=' . $nonce ) . '" >Download Export</a>';
	}

	/**
	 * Prepare and serve export settings
	 *
	 * @return string $content
	 */

	public function download_export() {

		if ( $this->option_group == $_REQUEST['option_group'] ) {

			if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'export-options' ) ) {
				wp_die( 'Security check' );
			}
			// here you get the options to export and set it as content, ex:
			$content   = base64_encode( serialize( $this->options ) );
			$file_name = 'exported_settings_' . date( 'm-d-y' ) . '.txt';
			header( 'HTTP/1.1 200 OK' );
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				wp_die( '<p>' . __( 'You do not have sufficient permissions to edit templates for this site.' ) . '</p>' );
			}
			if ( $content === null || $file_name === null ) {
				wp_die( '<p>' . __( 'Error Downloading file.' ) . '</p>' );
			}
			$fsize = strlen( $content );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename=' . $file_name );
			header( 'Content-Length: ' . $fsize );
			header( 'Expires: 0' );
			header( 'Pragma: public' );
			echo $content;
			exit;
		}
	}

	/**
	 * Show import field
	 *
	 * @param string $id
	 * @param array  $field
	 */

	private function show_field_import( $id, $field ) {

		echo '<textarea class="form-control ' . $field['class'] . '" id="' . $id . '" name="' . $this->option_group . '[' . $id . ']" placeholder="' . $field['std'] . '" rows="3" cols="39" ' . ( $field['disabled'] ? 'disabled="true"' : '' ) . '>' . format_for_editor( $this->options[ $id ] ) . '</textarea>';
	}

	/**
	 * Validate import field.
	 *
	 * @param string  $input
	 *
	 * @param        $setting
	 *
	 * @return string $input
	 */

	public function validate_field_import( $input, $setting ) {

		$import_code = unserialize( base64_decode( $input ) );

		if ( is_array( $import_code ) ) {

			update_option( $this->option_group, $import_code, false );
			$this->options           = $import_code;
			$this->settings_imported = true;

			return true;
		} else {

			return new WP_Error( 'error', __( 'Error importing settings. Check your import file and try again.' ) );
		}
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

		echo '<input class="checkbox warning" type="checkbox" id="' . $id . '" name="' . $this->option_group . '[' . $id . ']" value="1" ' . checked( $this->options[ $id ], 1, false ) . ' />';

		if ( $field['desc'] != '' ) {
			echo '<label for="' . $id . '">' . $field['desc'] . '</label>';
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

		if ( isset( $input ) ) {
			$this->reset_options = true;
		}

		return $input;
	}

}
