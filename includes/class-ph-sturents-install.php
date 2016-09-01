<?php
/**
 * Installation related functions and actions.
 *
 * @author 		PropertyHive
 * @category 	Admin
 * @package 	PropertyHive/Classes
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_Sturents_Install' ) ) :

/**
 * PH_Sturents_Install Class
 */
class PH_Sturents_Install {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		register_activation_hook( PH_STURENTS_PLUGIN_FILE, array( $this, 'install' ) );
		register_deactivation_hook( PH_STURENTS_PLUGIN_FILE, array( $this, 'deactivate' ) );
		register_uninstall_hook( PH_STURENTS_PLUGIN_FILE, array( 'PH_Sturents_Install', 'uninstall' ) );

		add_action( 'admin_init', array( $this, 'install_actions' ) );
		add_action( 'admin_init', array( $this, 'check_version' ), 5 );
	}

	/**
	 * check_version function.
	 *
	 * @access public
	 * @return void
	 */
	public function check_version() {
	    if ( 
	    	! defined( 'IFRAME_REQUEST' ) && 
	    	( get_option( 'propertyhive_sturents_version' ) != PHStuRents()->version || get_option( 'propertyhive_sturents_db_version' ) != PHStuRents()->version ) 
	    ) {
			$this->install();
		}
	}

	/**
	 * Install actions
	 */
	public function install_actions() {



	}

	/**
	 * Install Property Hive Property Import Add-On
	 */
	public function install() {
        
		$this->create_options();
		$this->create_cron();
		$this->create_tables();

		$current_version = get_option( 'propertyhive_sturents_version', null );
		$current_db_version = get_option( 'propertyhive_sturents_db_version', null );
        
        update_option( 'propertyhive_sturents_db_version', PHStuRents()->version );

        // Update version
        update_option( 'propertyhive_sturents_version', PHStuRents()->version );
	}

	/**
	 * Deactivate Property Hive StuRents Add-On
	 */
	public function deactivate() {

		$timestamp = wp_next_scheduled( 'phsturentsimportcronhook' );
        wp_unschedule_event($timestamp, 'phsturentsimportcronhook' );
        wp_clear_scheduled_hook('phsturentsimportcronhook');

	}

	/**
	 * Uninstall Property Hive StuRents Add-On
	 */
	public function uninstall() {

		$timestamp = wp_next_scheduled( 'phsturentsimportcronhook' );
        wp_unschedule_event($timestamp, 'phsturentsimportcronhook' );
        wp_clear_scheduled_hook('phsturentsimportcronhook');

        delete_option( 'propertyhive_sturents' );

        $this->delete_tables();
	}

	public function delete_tables() {

		global $wpdb;

		$wpdb->hide_errors();

		//$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_logs_instance" );
		//$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_logs_instance_log" );
	}

	/**
	 * Default options
	 *
	 * Sets up the default options used on the settings page
	 *
	 * @access public
	 */
	public function create_options() {
	    
        //add_option( 'option_name', 'yes', '', 'yes' );

    }

    /**
	 * Creates the scheduled event to run hourly
	 *
	 * @access public
	 */
    public function create_cron() {
        $timestamp = wp_next_scheduled( 'phsturentsimportcronhook' );
        wp_unschedule_event($timestamp, 'phsturentsimportcronhook' );
        wp_clear_scheduled_hook('phsturentsimportcronhook');
        
        $next_schedule = time() - 60;
        wp_schedule_event( $next_schedule, 'hourly', 'phsturentsimportcronhook' );
    }

    /**
	 * Set up the database tables which the plugin needs to function.
	 *
	 * Tables:
	 *		ph_propertyimport_logs_instance - Table description
	 *		ph_propertyimport_logs_instance_log - Table description
	 *
	 * @access public
	 * @return void
	 */
	private function create_tables() {

		global $wpdb;

		/*$wpdb->hide_errors();

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( ! empty($wpdb->charset ) ) {
				$collate .= "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( ! empty($wpdb->collate ) ) {
				$collate .= " COLLATE $wpdb->collate";
			}
		}

		// Create table to record individual feeds being ran
	   	$table_name = $wpdb->prefix . "ph_propertyimport_logs_instance";
	      
	   	$sql = "CREATE TABLE $table_name (
					id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					import_id bigint(20) UNSIGNED NOT NULL,
					start_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
					end_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				  	PRIMARY KEY  (id)
	    		) $collate;";
		
		$table_name = $wpdb->prefix . "ph_propertyimport_logs_instance_log";
		
		$sql .= "CREATE TABLE $table_name (
					id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					instance_id bigint(20) UNSIGNED NOT NULL,
					severity tinyint(1) UNSIGNED NOT NULL,
					entry varchar(255) NOT NULL,
					log_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				  	PRIMARY KEY  (id)
	    		) $collate;";
		
		dbDelta( $sql );*/

	}

}

endif;

return new PH_Sturents_Install();