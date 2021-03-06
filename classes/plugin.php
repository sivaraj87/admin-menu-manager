<?php
defined( 'WPINC' ) or die;

class Admin_Menu_Manager_Plugin extends WP_Stack_Plugin2 {

	/**
	 * @var self
	 */
	protected static $instance;

	/**
	 * Plugin version.
	 */
	const VERSION = '2.0.0-alpha';

	/**
	 * Constructs the object, hooks in to `plugins_loaded`.
	 */
	protected function __construct() {
		$this->hook( 'plugins_loaded', 'add_hooks' );
	}

	/**
	 * Adds hooks.
	 */
	public function add_hooks() {
		$this->hook( 'init' );

		// Load admin style sheet and JavaScript.
		$this->hook( 'admin_enqueue_scripts', 5 );

		// Handle form submissions
		$this->hook( 'wp_ajax_amm_update_menu', 'update_menu' );
		$this->hook( 'wp_ajax_amm_reset_menu', 'reset_menu' );

		// Modify admin menu
		$this->hook( 'admin_menu', 'alter_admin_menu', 999 );

		// Tell WordPress we're changing the menu order
		add_filter( 'custom_menu_order', '__return_true' );

		// Add our filter way later, after other plugins have defined the menu
		$this->hook( 'menu_order', 'alter_admin_menu_order', 9999 );
	}

	/**
	 * Initializes the plugin, registers textdomain, etc.
	 */
	public function init() {
		$this->load_textdomain( 'admin-menu-manager', '/languages' );
	}

	/**
	 * Load our JavaScript and CSS if the user has enough capabilities to edit the menu.
	 */
	public function admin_enqueue_scripts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Use minified libraries if SCRIPT_DEBUG is turned off
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_style( 'admin-menu-manager', $this->get_url() . 'css/admin-menu-manager' . $suffix . '.css', array(), self::VERSION );

		global $_wp_admin_css_colors;

		$current_color = get_user_option( 'admin_color' );
		if ( isset( $_wp_admin_css_colors[ $current_color ] ) ) {
			$border     = $_wp_admin_css_colors[ $current_color ]->icon_colors['base'];
			$background = $_wp_admin_css_colors[ $current_color ]->colors[0];
			$base       = $_wp_admin_css_colors[ $current_color ]->icon_colors['base'];
			$focus      = $_wp_admin_css_colors[ $current_color ]->icon_colors['focus'];
			$current    = $_wp_admin_css_colors[ $current_color ]->icon_colors['current'];
			$inline_css = "
			#adminmenu:not(.ui-sortable-disabled) .wp-menu-separator.ui-sortable-handle { background-color: $background; border-color: $border !important; }
			#admin-menu-manager-edit .menu-top { color: $base; }
			#admin-menu-manager-edit .menu-top:focus,
			#admin-menu-manager-edit .menu-top:focus div.wp-menu-image:before { color: $focus !important; }
			#admin-menu-manager-edit:hover .menu-top,
			#admin-menu-manager-edit:hover div.wp-menu-image:before { color: $current !important; }
			";
			wp_add_inline_style( 'admin-menu-manager', $inline_css );
		}

		wp_register_script(
			'backbone-undo',
			$this->get_url() . 'js/vendor/backbone.undo.min.js',
			array( 'backbone' ),
			self::VERSION
		);

		wp_enqueue_script(
			'admin-menu-manager',
			$this->get_url() . 'js/admin-menu-manager' . $suffix . '.js',
			array(
				'jquery-ui-sortable',
				'backbone',
				'backbone-undo'
			),
			self::VERSION
		);

		wp_localize_script( 'admin-menu-manager', 'AdminMenuManager', array(
			'templates' => array(
				'editButton' => array(
					'label'       => __( 'Edit Menu', 'admin-menu-manager' ),
					'labelSaving' => __( 'Saving&hellip;', 'admin-menu-manager' ),
					'labelSaved'  => __( 'Saved!', 'admin-menu-manager' ),
					'ays'         => __( 'Are you sure? This will reset the whole menu!', 'admin-menu-manager' ),
					'options'     => array(
						'save'  => __( 'Save changes', 'admin-menu-manager' ),
						'add'   => __( 'Add new item', 'admin-menu-manager' ),
						'undo'  => __( 'Undo change', 'admin-menu-manager' ),
						'redo'  => __( 'Redo change', 'admin-menu-manager' ),
						'reset' => __( 'Reset menu', 'admin-menu-manager' ),
					)
				)
			),
			'menu'      => self::get_admin_menu(),
			'trash'     => self::get_admin_menu_trash(),
		) );
	}

	/**
	 * Grab a list of all registered admin pages.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_admin_menu() {
		global $menu, $submenu;

		if ( null === $menu ) {
			$menu = array();
		}

		$menu_items = array();

		foreach ( $menu as $menu_item ) {
			if ( ! empty( $submenu[ $menu_item[2] ] ) ) {
				$menu_item['children'] = array_values( $submenu[ $menu_item[2] ] );
			}

			$menu_items[] = $menu_item;
		}

		return $menu_items;
	}

	/**
	 * Grab a list of all trashed admin menu items.
	 *
	 * @return array
	 */
	public function get_admin_menu_trash() {
		$menu    = get_option( 'amm_trash_menu', array() );
		$submenu = get_option( 'amm_trash_submenu', array() );

		if ( null === $menu ) {
			$menu = array();
		}

		$menu_items = array();

		foreach ( $menu as $menu_item ) {
			if ( ! empty( $submenu[ $menu_item[2] ] ) ) {
				foreach ( $submenu[ $menu_item[2] ] as $key => &$value ) {
					if ( '' === $key && '' === $value[0] ) {
						unset( $submenu[ $menu_item[2] ][ $key ] );
						continue;
					}
					$value[] = $key;
				}
				$menu_item['children'] = array_values( $submenu[ $menu_item[2] ] );
			}

			$menu_items[] = $menu_item;
		}

		return $menu_items;
	}

	/**
	 * Ajax Handler to update the menu.
	 *
	 * The passed array is splitted up in a menu and submenu array,
	 * just like WordPress uses it in the backend.
	 */
	public function update_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$_REQUEST['trash'] = isset( $_REQUEST['trash'] ) ? $_REQUEST['trash'] : array();

		$menu  = $this->update_menu_loop( $_REQUEST['menu'] );
		$trash = $this->update_menu_loop( $_REQUEST['trash'] );

		// Note: The third autoload parameter was introduced in WordPress 4.2.0
		update_option( 'amm_menu', $menu['menu'], false );
		update_option( 'amm_submenu', $menu['submenu'], false );
		update_option( 'amm_trash_menu', $trash['menu'], false );
		update_option( 'amm_trash_submenu', $trash['submenu'], false );

		die( 1 );
	}

	/**
	 * Loop through all menu items to update the menu.
	 *
	 * @param array $menu
	 *
	 * @return array An array containing top level and sub level menu items.
	 */
	protected function update_menu_loop( $menu ) {
		$items   = array();
		$submenu = array();

		$separatorIndex = 1;
		$lastSeparator  = null;

		foreach ( $menu as $item ) {
			$item = array(
				wp_unslash( $item['label'] ),
				$item['capability'],
				$item['href'],
				$item['pageTitle'],
				$item['classes'],
				$item['id'],
				$item['icon'],
				isset( $item['children'] ) ? $item['children'] : array(),
			);

			if ( ! empty( $item[7] ) ) {
				$submenu[ $item[2] ] = array();
				foreach ( $item[7] as $subitem ) {
					$subitem = array(
						wp_unslash( $subitem['label'] ),
						$subitem['capability'],
						$subitem['href'],
						$subitem['pageTitle'],
						$subitem['classes'],
					);

					$submenu[ $item[2] ][] = $subitem;
				}
				unset( $item[7] );
			}

			// Store separators in correct order
			if ( false !== strpos( $item[2], 'separator' ) ) {
				$item[2]       = 'separator' . $separatorIndex ++;
				$item[4]       = 'wp-menu-separator';
				$lastSeparator = count( $items );
			}

			$items[] = $item;
		}

		if ( null !== $lastSeparator ) {
			$items[ $lastSeparator ][2] = 'separator-last';
		}

		return array(
			'menu'    => $items,
			'submenu' => $submenu
		);
	}

	/**
	 * Ajax Handler to reset the menu.
	 */
	public function reset_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( 'amm_menu' );
		delete_option( 'amm_submenu' );
		delete_option( 'amm_trash_menu' );
		delete_option( 'amm_trash_submenu' );

		die( 1 );
	}

	/**
	 * Here's where the magic happens!
	 *
	 * Compare our menu structure with the original.
	 * Essentially it uses the new order but with the original values,
	 * so translated strings and icons still work.
	 *
	 * 0 = menu_title, 1 = capability, 2 = menu_slug, 3 = page_title, 4 = classes
	 */
	public function alter_admin_menu() {
		$amm_menu          = get_option( 'amm_menu', array() );
		$amm_submenu       = get_option( 'amm_submenu', array() );
		$amm_trash_menu    = get_option( 'amm_trash_menu', array() );
		$amm_trash_submenu = get_option( 'amm_trash_submenu', array() );

		if ( empty( $amm_menu ) || empty( $amm_submenu ) ) {
			return;
		}

		global $menu, $submenu, $admin_page_hooks;

		$temp_menu             = $menu;
		$temp_submenu          = $submenu;
		$temp_admin_page_hooks = $admin_page_hooks;

		$menu    = null;
		$submenu = null;

		// Iterate on the top level items
		foreach ( $amm_menu as $priority => &$item ) {
			// It was originally a top level item as well. It's a match!
			foreach ( $temp_menu as $key => $m_item ) {
				if ( str_replace( 'admin.php?page=', '', $item[2] ) === $m_item[2] ) {
					if ( 'wp-menu-separator' == $m_item[4] ) {
						$menu[ $priority ] = $m_item;
					} else {
						add_menu_page(
							$m_item[3], // Page title
							$m_item[0], // Menu title
							$m_item[1], // Capability
							$m_item[2], // Slug
							'', // Function
							$m_item[6], // Icon
							$priority // Position
						);
					}

					unset( $temp_menu[ $key ] );
					continue 2;
				}
			}

			// It must be a submenu item moved to the top level
			foreach ( $temp_submenu as $key => &$parent ) {
				foreach ( $parent as $sub_key => &$sub_item ) {
					if ( str_replace( 'admin.php?page=', '', $item[2] ) === $sub_item[2] ) {
						$hook_name = get_plugin_page_hookname( $sub_item[2], $key );

						if ( ! isset( $sub_item[3] ) ) {
							$sub_item[3] = $sub_item[0];
						}

						$new_page = add_menu_page(
							$sub_item[3], // Page title
							$sub_item[0], // Menu title
							$sub_item[1], // Capability
							$sub_item[2], // Slug
							'', // Function
							$item[6], // Icon
							$priority // Position
						);

						// Add hook name of the former parent as CSS class to the new item
						$menu[ $priority ][4] .= ' ' . get_plugin_page_hookname( $key, $key );

						$this->switch_menu_item_filters( $hook_name, $new_page );

						unset( $temp_submenu[ $key ][ $sub_key ] );

						continue 3;
					}
				}
			}

			// Still no match, menu item must have been removed.
			unset( $temp_menu[ $priority ] );
		}

		// Iterate on all our submenu items
		foreach ( $amm_submenu as $parent_page => &$page ) {
			foreach ( $page as $priority => &$item ) {
				// Iterate on original submenu items
				foreach ( $temp_submenu as $s_parent_page => &$s_page ) {
					foreach ( $s_page as $s_priority => &$s_item ) {
						if (
							str_replace( 'admin.php?page=', '', $item[2] ) === $s_item[2] &&
							str_replace( 'admin.php?page=', '', $parent_page ) == $s_parent_page
						) {
							add_submenu_page(
								$s_parent_page, // Parent Slug
								isset( $s_item[3] ) ? $s_item[3] : $s_item[0], // Page title
								$s_item[0], // Menu title
								$s_item[1], // Capability
								$s_item[2] // SLug
							);

							unset( $temp_submenu[ $s_parent_page ][ $s_priority ] );

							continue 2;
						}
					}
				}

				// It must be a top level item moved to submenu
				foreach ( $temp_menu as $m_key => &$m_item ) {
					if ( str_replace( 'admin.php?page=', '', $item[2] ) === $m_item[2] ) {
						$hook_name = get_plugin_page_hookname( $m_item[2], $parent_page );

						$new_page = add_submenu_page(
							$parent_page, // Parent Slug
							$m_item[0], // Page title
							$m_item[0], // Menu title
							$m_item[1], // Capability
							$m_item[2] // Slug
						);

						$this->switch_menu_item_filters( $hook_name, $new_page );

						unset( $temp_menu[ $m_key ] );

						continue 2;
					}
				}
			}
		}

		// Remove trashed items
		foreach ( $amm_trash_menu as $priority => &$item ) {
			// It was originally a top level item as well. It's a match!
			foreach ( $temp_menu as $key => $m_item ) {
				if ( $item[2] === $m_item[2] ) {
					unset( $temp_menu[ $key ] );
					continue 2;
				}
			}

			// It must be a submenu item moved to the top level
			foreach ( $temp_submenu as $key => &$parent ) {
				foreach ( $parent as $sub_key => &$sub_item ) {
					if ( $item[2] === $sub_item[2] ) {
						unset( $temp_submenu[ $key ][ $sub_key ] );
						continue 3;
					}
				}
			}

			unset( $temp_menu[ $priority ] );
		}

		foreach ( $amm_trash_submenu as $parent_page => &$page ) {
			foreach ( $page as $priority => &$item ) {
				// Iterate on original submenu items
				foreach ( $temp_submenu as $s_parent_page => &$s_page ) {
					foreach ( $s_page as $s_priority => &$s_item ) {
						if ( $item[2] === $s_item[2] && $parent_page == $s_parent_page ) {
							unset( $temp_submenu[ $s_parent_page ][ $s_priority ] );
							continue 2;
						}
					}
				}

				// It must be a top level item moved to submenu
				foreach ( $temp_menu as $m_key => &$m_item ) {
					if ( $item[2] === $m_item[2] ) {
						unset( $temp_menu[ $m_key ] );
						continue 2;
					}
				}
			}
		}

		/**
		 * Append elements that haven't been added to a menu yet.
		 *
		 * This happens when installing a new plugin for example.
		 */
		$menu = array_merge( $menu, $temp_menu );

		foreach ( $temp_submenu as $parent => $item ) {
			if ( '' === $parent || empty( $item ) || ! is_array( $item ) ) {
				continue;
			}

			if ( isset( $submenu[ $parent ] ) ) {
				$submenu[ $parent ] = array_merge( $submenu[ $parent ], $item );
			} else {
				$submenu[ $parent ] = $item;
			}
		}

		/**
		 * Loop through admin page hooks.
		 *
		 * We want to keep the original, untranslated values.
		 */
		foreach ( $admin_page_hooks as $key => &$value ) {
			if ( isset( $temp_admin_page_hooks[ $key ] ) ) {
				$value = $temp_admin_page_hooks[ $key ];
			}
		}
	}

	/**
	 * Get all the filters hooked to an admin menu page.
	 *
	 * @param string $hook_name The plugin page hook name.
	 *
	 * @return array
	 */
	protected function get_menu_item_filters( $hook_name ) {
		global $wp_filter;

		$old_filters = array();

		foreach ( $wp_filter as $filter => $value ) {
			if ( false !== strpos( $filter, $hook_name ) ) {
				$old_filters[ $filter ] = $value;
				unset( $wp_filter[ $filter ] );
			}
		}

		return $old_filters;
	}

	protected function switch_menu_item_filters( $old_hook, $new_hook ) {
		global $wp_filter;

		foreach ( $this->get_menu_item_filters( $old_hook ) as $filter => $value ) {
			$wp_filter[ str_replace( $old_hook, $new_hook, $filter ) ] = $value;
		}
	}

	/**
	 * Make sure our menu order is kept.
	 *
	 * Some plugins (I'm looking at you, Jetpack!) want to always be on top,
	 * let's fix this.
	 *
	 * @param array $menu_order WordPress admin menu order.
	 *
	 * @return array
	 */
	public function alter_admin_menu_order( $menu_order ) {
		global $menu;

		if ( ! get_option( 'amm_menu', false ) ) {
			return $menu_order;
		}

		$new_order = array();
		foreach ( $menu as $item ) {
			$new_order[] = $item[2];
		}

		return $new_order;
	}

}
