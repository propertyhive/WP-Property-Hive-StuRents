<?php
/**
 * Plugin Name: Property Hive StuRents Add On
 * Plugin Uri: https://wp-property-hive.com/addons/sturents-wordpress-import-export/
 * Description: Add on for Property Hive which imports and exports properties from the StuRents website
 * Version: 1.0.18
 * Author: PropertyHive
 * Author URI: https://wp-property-hive.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_StuRents' ) ) :

final class PH_StuRents {

    /**
     * @var string
     */
    public $version = '1.0.18';

    /**
     * @var PropertyHive The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main PropertyHive StuRents Instance
     *
     * Ensures only one instance of Property Hive StuRents is loaded or can be loaded.
     *
     * @static
     * @return PropertyHive StuRents - Main instance
     */
    public static function instance() 
    {
        if ( is_null( self::$_instance ) ) 
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {

        $this->id    = 'sturents';
        $this->label = __( 'StuRents', 'propertyhive' );

        // Define constants
        $this->define_constants();

        // Include required files
        $this->includes();

        add_action( 'admin_init', array( $this, 'run_custom_sturents_property_import_cron') );

        add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), array( $this, 'plugin_add_settings_link' ) );

        add_action( 'admin_notices', array( $this, 'sturents_error_notices') );
        add_action( 'admin_init', array( $this, 'check_sturents_feed_is_scheduled'), 99 );

        add_filter( 'propertyhive_settings_tabs_array', array( $this, 'add_settings_tab' ), 19 );
        add_action( 'propertyhive_settings_' . $this->id, array( $this, 'output' ) );
        add_action( 'propertyhive_settings_save_' . $this->id, array( $this, 'save' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

        add_action( 'propertyhive_admin_field_sturents_existing_feeds', array( $this, 'sturents_existing_feeds' ) );

        add_action( 'propertyhive_property_marketing_fields', array( $this, 'add_sturents_checkboxes' ) );
        add_action( 'propertyhive_save_property_marketing', array( $this, 'save_sturents_checkboxes' ), 10, 1 );

        add_filter( 'propertyhive_property_filter_marketing_options', array( $this, 'sturents_property_filter_marketing_options' ) );
        add_filter( 'propertyhive_property_filter_query', array( $this, 'sturents_property_filter_query' ), 10, 2 );

        add_action( 'propertyhive_property_bulk_edit_end', array( $this, 'sturents_bulk_edit_options' ) );
        add_action( 'propertyhive_property_bulk_edit_save', array( $this, 'sturents_bulk_edit_save' ), 10, 1 );

        add_action( 'manage_property_posts_custom_column', array( $this, 'custom_property_columns' ), 5 );

        add_action( 'save_post', array( $this, 'sturents_save_property' ), 99 );

        add_action( 'phsturentsimportcronhook', array( $this, 'sturents_property_import_execute_feed' ) );

        add_filter( 'propertyhive_price_output', array( $this, 'prefix_with_from' ), 10, 5 );
    }

    public function check_sturents_feed_is_scheduled()
    {
        $schedule = wp_get_schedule( 'phsturentsimportcronhook' );

        if ( $schedule === FALSE )
        {
            // Hmm... cron job not found. Let's set it up
            $timestamp = wp_next_scheduled( 'phsturentsimportcronhook' );
            wp_unschedule_event($timestamp, 'phsturentsimportcronhook' );
            wp_clear_scheduled_hook('phsturentsimportcronhook');
            
            $next_schedule = time() - 60;
            wp_schedule_event( $next_schedule, 'hourly', 'phsturentsimportcronhook' );
        }
    }

    public function prefix_with_from( $return, $property, $currency, $prefix, $suffix )
    {
        if ( boolval( $property->multiple_prices ) === true )
        {
            $return = 'From ' . $return;
        }

        return $return;
    }

    /**
     * Enqueue scripts
     */
    public function admin_scripts() {

        $suffix       = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

        $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', __FILE__ ) ) ) . '/assets/';

        // Register scripts
        wp_register_script( 'propertyhive_sturents_admin', $assets_path . 'js/admin' . /*$suffix .*/ '.js', array( 'jquery' ), PH_STURENTS_VERSION );
    }

    public function plugin_add_settings_link( $links )
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=ph-settings&tab=sturents') . '">' . __( 'Settings' ) . '</a>';
        array_push( $links, $settings_link );
        return $links;
    }

    /**
     * Define PH StuRents Constants
     */
    private function define_constants() 
    {
        define( 'PH_STURENTS_PLUGIN_FILE', __FILE__ );
        define( 'PH_STURENTS_VERSION', $this->version );
    }

    private function includes()
    {
        include_once( dirname( __FILE__ ) . "/includes/class-ph-sturents-install.php" );
    }

    public function sturents_property_import_execute_feed() 
    {
        wp_suspend_cache_invalidation( true );

        wp_defer_term_counting( true );
        wp_defer_comment_counting( true );

        require( __DIR__ . '/cron.php' );

        wp_cache_flush();

        wp_suspend_cache_invalidation( false );

        wp_defer_term_counting( false );
        wp_defer_comment_counting( false );
    }

    public function run_custom_sturents_property_import_cron() 
    {
        if ( isset($_GET['custom_sturents_property_import_cron']) )
        {
            do_action($_GET['custom_sturents_property_import_cron']);
        }

        if ( isset($_GET['action']) && $_GET['action'] == 'sturentspushall' && isset($_GET['id']) && $_GET['id'] != '' )
        {
            $lettings_departments = $this->ph_get_all_lettings_departments();

            // Get all properties
            $args = array(
                'post_type' => 'property',
                'nopaging' => true,
                'meta_query' => array(
                    array(
                        'key' => '_on_market',
                        'value' => 'yes'
                    ),
                    array(
                        'key' => '_department',
                        'value' => $lettings_departments,
                        'compare' => 'IN'
                    ),
                )
            );
            $property_query = new WP_Query( $args );

            if ($property_query->have_posts())
            {
                while ($property_query->have_posts())
                {
                    $property_query->the_post();

                    $data = $this->generate_property_export_data( get_the_ID() );

                    $this->do_property_export_request( $data, get_the_ID(), $_GET['id'] );
                }
            }
        }
    }

    /**
     * Output error message if core Property Hive plugin isn't active
     */
    public function sturents_error_notices() 
    {
        if (!is_plugin_active('propertyhive/propertyhive.php'))
        {
            $message = "The Property Hive plugin must be installed and activated before you can use the Property Hive StuRents add-on";
            echo"<div class=\"error\"> <p>$message</p></div>";
        }
        else
        {
            global $post;

            if ( isset($post->ID) && $error = get_transient( "sturents_save_error_" . $post->ID ) )
            {
                echo '<div class="error"><p><strong>' . $error . '</strong></p></div>';

                delete_transient("sturents_save_error_" . $post->ID);
            }

            $screen = get_current_screen();
            if ( $screen->id == 'property' && isset($_GET['post']) && get_post_type($_GET['post']) == 'property' )
            {
                // Check if this property was imported from somewhere and warn if it was
                $post_meta = get_post_meta($_GET['post']);

                $imported = false;

                foreach ($post_meta as $key => $val )
                {
                    if ( strpos($key, '_imported_ref_') !== FALSE )
                    {
                        echo '<div class="notice notice-info"><p>';
                        
                        echo __( '<strong>It looks like this property was imported automatically. Please note that any changes made manually might get overwritten the next time an import runs.</strong><br><br><em>Import Details: ' . $key . ': ' . $val[0] . '</em>', 'propertyhive' );

                        $import_data = get_post_meta($_GET['post'], '_property_import_data', true);
                        if( !empty($import_data) )
                        {
                            if ( !wp_script_is('propertyhive_sturents_admin', 'enqueued') )
                            {
                                wp_enqueue_script( 'propertyhive_sturents_admin' );
                            }

                            echo '<br><strong><a href="" id="toggle_sturents_import_data_div">Show Import Data</a>
                              <br><div id="sturents_import_data_div" style="display:none;"><textarea readonly rows="20" cols="120">' . $import_data . '</textarea></div></strong>';
                        }

                        echo '</p></div>';
                        break;
                    }
                }
            }
        }
    }

    /**
     * Add a new settings tab to the Property Hive settings tabs array.
     *
     * @param array $settings_tabs Array of Property Hive setting tabs & their labels.
     * @return array $settings_tabs Array of Property Hive setting tabs & their labels.
     */
    public function add_settings_tab( $settings_tabs ) {
        $settings_tabs['sturents'] = __( 'StuRents', 'propertyhive' );
        return $settings_tabs;
    }

    /**
     * Uses the Property Hive admin fields API to output settings.
     *
     * @uses propertyhive_admin_fields()
     * @uses self::get_settings()
     */
    public function output() {

        global $current_section, $hide_save_button;

        if (strpos($current_section, 'mapping_') !== FALSE)
        {
            // Doing custom field mapping
            propertyhive_admin_fields( self::get_customfields_settings() );
        }
        else
        {
            switch ($current_section)
            {
                case "addimport":
                case "editimport":
                {
                    propertyhive_admin_fields( self::get_import_settings() );
                    break;
                }
                case "addexport":
                case "editexport":
                {
                    propertyhive_admin_fields( self::get_export_settings() );
                    break;
                }
                case "logs":
                {
                    $hide_save_button = true;
                    $this->output_logs();
                    break;
                }
                case "log":
                {
                    $hide_save_button = true;
                    $this->output_log();
                    break;
                }
                default:
                {
                    $hide_save_button = true;
                    propertyhive_admin_fields( self::get_sturents_settings() );
                }
            }
        }
    }

    public function output_logs()
    {
        global $wpdb;

        echo '<h3>Logs</h3>';

        $import_id = (int)$_GET['id'];

        $logs = $wpdb->get_results( 
            "
            SELECT * 
            FROM " . $wpdb->prefix . "ph_sturents_logs_instance
            INNER JOIN 
                " . $wpdb->prefix . "ph_sturents_logs_instance_log ON  " . $wpdb->prefix . "ph_sturents_logs_instance.id = " . $wpdb->prefix . "ph_sturents_logs_instance_log.instance_id
            WHERE 
                import_id = '" . $import_id . "'
            GROUP BY " . $wpdb->prefix . "ph_sturents_logs_instance.id
            ORDER BY start_date ASC
            "
        );

        if ( $logs )
        {
            foreach ( $logs as $log ) 
            {
                $log_start_date = get_date_from_gmt( $log->start_date, "H:i jS F Y" );

                $duration = '-';
                if ( $log->end_date != '0000-00-00 00:00:00' )
                {
                    $duration = '';

                    $diff_secs = strtotime($log->end_date) - strtotime($log->start_date);

                    if ( $diff_secs >= 60 )
                    {
                        $diff_mins = floor( $diff_secs / 60 );
                        $duration = $diff_mins . ' minutes, ';
                        $diff_secs = $diff_secs - ( $diff_mins * 60 );
                    }

                    $duration .= $diff_secs . ' seconds';
                }

                echo '<a href="' . admin_url('admin.php?page=ph-settings&tab=sturents&section=log&id=' . $import_id . '&log=' . $log->instance_id) . '">' . $log_start_date . '</a> (' . $duration . ')<br>';
            }
        }
        else
        {
            $keep_logs_days = (string)apply_filters( 'propertyhive_sturents_keep_logs_days', '7' );

            // Revert back to 7 days if anything other than numbers has been passed
            // This prevent SQL injection and errors
            if ( !preg_match("/^\d+$/", $keep_logs_days) )
            {
                $keep_logs_days = '7';
            }

            echo '<p>No logs found. The import may not have ran, or hasn\'t ran in the past ' . $keep_logs_days . ' days</p>';
        }
        ?>
        <br>
        <a href="<?php echo admin_url('admin.php?page=ph-settings&tab=sturents'); ?>" class="button">Back</a>
        <?php
    }

    public function output_log()
    {
        global $wpdb;

        $import_id = (int)$_GET['id'];
        $instance_id = (int)$_GET['log'];

        echo '<h3>Log</h3>';

        $buttons = array();

    $buttons[] = '<a href="' . admin_url('admin.php?page=ph-settings&tab=sturents&section=logs&id=' . (int)$_GET['id']) . '" class="button">Back To Logs</a>';

    $logs = $wpdb->get_results( 
        "
        SELECT * 
        FROM " . $wpdb->prefix . "ph_sturents_logs_instance
        INNER JOIN 
            " . $wpdb->prefix . "ph_sturents_logs_instance_log ON  " . $wpdb->prefix . "ph_sturents_logs_instance.id = " . $wpdb->prefix . "ph_sturents_logs_instance_log.instance_id
        WHERE 
            import_id = '" . (int)$_GET['id'] . "'
        AND
            instance_id < '" . $instance_id . "'
        GROUP BY " . $wpdb->prefix . "ph_sturents_logs_instance.id
        ORDER BY start_date DESC
        LIMIT 1
        "
    );

    if ( $logs )
    {
        foreach ( $logs as $log ) 
        {
            $buttons[] = '<a href="' . admin_url('admin.php?page=ph-settings&tab=sturents&section=log&id=' . (int)$_GET['id'] . '&log=' . $log->instance_id) . '" class="button">&lt; Previous Log</a>';
        }
    }

    $logs = $wpdb->get_results( 
        "
        SELECT * 
        FROM " . $wpdb->prefix . "ph_sturents_logs_instance
        INNER JOIN 
            " . $wpdb->prefix . "ph_sturents_logs_instance_log ON  " . $wpdb->prefix . "ph_sturents_logs_instance.id = " . $wpdb->prefix . "ph_sturents_logs_instance_log.instance_id
        WHERE 
            import_id = '" . (int)$_GET['id'] . "'
        AND
            instance_id > '" . $instance_id . "'
        GROUP BY " . $wpdb->prefix . "ph_sturents_logs_instance.id
        ORDER BY start_date ASC
        LIMIT 1
        "
    );

    if ( $logs )
    {
        foreach ( $logs as $log ) 
        {
            $buttons[] = '<a href="' . admin_url('admin.php?page=ph-settings&tab=sturents&section=log&id=' . (int)$_GET['id'] . '&log=' . $log->instance_id) . '" class="button">Next Log &gt;</a>';
        }
    }
?>

<?php
    echo implode(" ", $buttons);

        
        ?>
<pre style="overflow:auto; max-height:450px; background:#FFF; border-top:1px solid #CCC; border-bottom:1px solid #CCC"><?php
    
    $logs = $wpdb->get_results( 
        "
        SELECT *
        FROM " . $wpdb->prefix . "ph_sturents_logs_instance
        INNER JOIN 
            " . $wpdb->prefix . "ph_sturents_logs_instance_log ON  " . $wpdb->prefix . "ph_sturents_logs_instance.id = " . $wpdb->prefix . "ph_sturents_logs_instance_log.instance_id
        WHERE 
            instance_id = '" . $instance_id . "'
        ORDER BY " . $wpdb->prefix . "ph_sturents_logs_instance_log.id ASC
        "
    );

    $import_id = '';
    foreach ( $logs as $log ) 
    {
        $log_entry = htmlentities($log->entry);
        if ( strpos($log_entry, 'post ID is ') !== FALSE )
        {
            $explode_log = explode(" ", $log_entry);
            $post_id = $explode_log[count($explode_log)-1];
            $link = $post_id;
            if ( !empty(get_edit_post_link($post_id)) )
            {
                $link = '<a href="' . get_edit_post_link($post_id) . '" target="_blank">' . $post_id . '</a>';
            }
            $log_entry = str_replace($post_id, $link, $log_entry);
        }
        $log_date = get_date_from_gmt( $log->log_date, "H:i:s jS F Y" );
        echo $log_date . ' - ' . $log_entry;
        
        if ( $log->severity != 0 && !empty($log->received_data) )
        {
            echo ': ';
            echo htmlentities($log->received_data);
        }

        echo "\n";

        $import_id = $log->import_id;
    }

?></pre>
        <?php
        echo implode(" ", $buttons);
    }

    /**
     * Get all the main settings for this plugin
     *
     * @return array Array of settings
     */
    public function get_sturents_settings() 
    {
        $settings = array(

            array( 'title' => __( 'Existing Imports / Exports', 'propertyhive' ), 'type' => 'title', 'desc' => '', 'id' => 'feeds' ),

            array(
                'type'      => 'sturents_existing_feeds',
            ),

            array( 'type' => 'sectionend', 'id' => 'feeds'),

        );

        return apply_filters( 'ph_settings_sturents_settings', $settings );
    }

    /**
     * Get the settings for adding/editing import from StuRents
     *
     * @return array Array of settings
     */
    public function get_import_settings()
    {
        global $current_section, $post;

        $current_id = ( !isset( $_REQUEST['id'] ) ) ? '' : sanitize_title( $_REQUEST['id'] );

        $feed_details = array();

        if ($current_id != '')
        {
            // We're editing one

            $current_sturents_options = get_option( 'propertyhive_sturents' );

            $feeds = $current_sturents_options['feeds'];

            if (isset($feeds[$current_id]))
            {
                $feed_details = $feeds[$current_id];
            }
        }

        $settings = array(

            array( 'title' => __( ( $current_section == 'addimport' ? 'Add Import' : 'Edit Import' ), 'propertyhive' ), 'type' => 'title', 'desc' => '', 'id' => 'importsettings' ),

            array(
                'title' => __( 'Status', 'propertyhive' ),
                'id'        => 'mode',
                'type'      => 'select',
                'options'   => array(
                    'off' => 'Inactive',
                    'live' => 'Active'
                ),
                'default' => ( (isset($feed_details['mode'])) ? $feed_details['mode'] : ''),
                'desc_tip'  =>  false,
            ),

            array(
                'title' => __( 'Landlord ID', 'propertyhive' ),
                'id'        => 'landlord_id',
                'default'   => ( (isset($feed_details['landlord_id'])) ? $feed_details['landlord_id'] : ''),
                'type'      => 'text',
                'desc_tip'  =>  false,
            ),

            array(
                'title' => __( 'Public Key', 'propertyhive' ),
                'id'        => 'public_key',
                'default'   => ( (isset($feed_details['public_key'])) ? $feed_details['public_key'] : ''),
                'type'      => 'text',
                'desc_tip'  =>  false,
            ),

            array(
                'type'      => 'html',
                'html'      =>  __( 'The above information can be obtained from visiting <a href="https://sturents.com/software/developer" target="_blank">https://sturents.com/software/developer</a>.', 'propertyhive' ),
            ),

            array( 'type' => 'sectionend', 'id' => 'importsettings'),

        );

        return apply_filters( 'ph_settings_sturents_import_settings', $settings );
    }

    /**
     * Get the settings for adding/editing export from StuRents
     *
     * @return array Array of settings
     */
    public function get_export_settings()
    {
        global $current_section, $post;

        $current_id = ( !isset( $_REQUEST['id'] ) ) ? '' : sanitize_title( $_REQUEST['id'] );

        $feed_details = array();

        if ($current_id != '')
        {
            // We're editing one

            $current_sturents_options = get_option( 'propertyhive_sturents' );

            $feeds = $current_sturents_options['feeds'];

            if (isset($feeds[$current_id]))
            {
                $feed_details = $feeds[$current_id];
            }
        }

        $settings = array(

            array( 'title' => __( ( $current_section == 'addexport' ? 'Add Export' : 'Edit Export' ), 'propertyhive' ), 'type' => 'title', 'desc' => '', 'id' => 'exportsettings' ),

            array(
                'title' => __( 'Status', 'propertyhive' ),
                'id'        => 'mode',
                'type'      => 'select',
                'options'   => array(
                    'off' => 'Inactive',
                    'live' => 'Active'
                ),
                'default' => ( (isset($feed_details['mode'])) ? $feed_details['mode'] : ''),
                'desc_tip'  =>  false,
            ),

            array(
                'title' => __( 'Landlord ID', 'propertyhive' ),
                'id'        => 'landlord_id',
                'default'   => ( (isset($feed_details['landlord_id'])) ? $feed_details['landlord_id'] : ''),
                'type'      => 'text',
                'desc_tip'  =>  false,
            ),

            array(
                'title' => __( 'API Key', 'propertyhive' ),
                'id'        => 'api_key',
                'default'   => ( (isset($feed_details['api_key'])) ? $feed_details['api_key'] : ''),
                'type'      => 'text',
                'desc_tip'  =>  false,
            ),

            array(
                'title' => __( 'Prices Sent As', 'propertyhive' ),
                'id'        => 'price_per',
                'default'   => ( (isset($feed_details['price_per'])) ? $feed_details['price_per'] : ''),
                'type'      => 'select',
                'desc'  => 'If prices are sent as per property, the price will be divided by the number of bedrooms by StuRents to obtain a per person price',
                'options'   => array(
                    'property' => 'Per Property',
                    'person' => 'Per Person',
                )
            ),

            array(
                'title' => __( 'Export Properties', 'propertyhive' ),
                'id'        => 'export',
                'default'   => ( (isset($feed_details['export'])) ? $feed_details['export'] : ''),
                'type'      => 'select',
                'desc'  => 'If \'Select Individual Properties\' is chosen you can select which properties are sent under the \'Marketing\' tab of the property record',
                'options'   => array(
                    '' => 'All On Market Properties',
                    'selected' => 'Select Individual Properties',
                )
            ),

            array(
                'title'   => __( 'Only Send Property If Different From Last Time Sent', 'propertyhive' ),
                'id'      => 'only_send_if_different',
                'type'    => 'checkbox',
                'default' => ( (isset($feed_details['only_send_if_different']) && $feed_details['only_send_if_different'] == '1') ? 'yes' : ''),
                'desc'    => __( 'By default a property will be sent to StuRents each time it is saved. Most of the time the data might remain unchanged, thus causing unnecessary requests to be sent. Select this option if we should only send the property if the data has changed since last time it was sent. Especially applicable if importing properties AND sending them to StuRents, otherwise you can probably leave this unticked.', 'propertyhive' ),
            ),

            array(
                'type'      => 'html',
                'html'      =>  __( 'The above information can be obtained from visiting <a href="https://sturents.com/software/developer" target="_blank">https://sturents.com/software/developer</a>.', 'propertyhive' ),
            ),

            array( 'type' => 'sectionend', 'id' => 'exportsettings'),

        );

        return apply_filters( 'ph_settings_sturents_export_settings', $settings );
    }

    /**
     * Output list of existing integrations
     *
     * @access public
     * @return void
     */
    public function sturents_existing_feeds() {
        global $wpdb, $post;
        
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                &nbsp;
            </th>
            <td class="forminp forminp-button">
                <a href="<?php echo admin_url( 'admin.php?page=ph-settings&tab=sturents&section=addexport' ); ?>" class="button alignright" style="margin-left:3px;"><?php echo __( 'Add New Export', 'propertyhive' ); ?></a>
                <a href="<?php echo admin_url( 'admin.php?page=ph-settings&tab=sturents&section=addimport' ); ?>" class="button alignright"><?php echo __( 'Add New Import', 'propertyhive' ); ?></a>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php _e( 'Existing Imports / Exports', 'propertyhive' ) ?></th>
            <td class="forminp">
                <table class="ph_portals widefat" cellspacing="0">
                    <thead>
                        <tr>
                            <th class="active"><?php _e( 'Active', 'propertyhive' ); ?></th>
                            <th class="type"><?php _e( 'Type', 'propertyhive' ); ?></th>
                            <th class="details"><?php _e( 'Details', 'propertyhive' ); ?></th>
                            <th class="actions">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php

                            $current_sturents_options = get_option( 'propertyhive_sturents' );
                            $portals = array();
                            if ($current_sturents_options !== FALSE)
                            {
                                if (isset($current_sturents_options['feeds']))
                                {
                                    $feeds = $current_sturents_options['feeds'];
                                }
                            }

                            if (!empty($feeds))
                            {
                                foreach ($feeds as $i => $feed)
                                {
                                    echo '<tr>';
                                        echo '<td width="3%" class="active">' . ucwords($feed['mode']) . '</td>';
                                        echo '<td class="type">' . ucwords($feed['type']) . '</td>';
                                        echo '<td class="details">';
                                    if ( $feed['type'] == 'import' )
                                    {
                                        echo 'Landlord ID: ' . $feed['landlord_id'] . '<br>
                                        Public Key: ' . $feed['public_key'];
                                    }
                                    if ( $feed['type'] == 'export' )
                                    {
                                        echo 'Landlord ID: ' . $feed['landlord_id'] . '<br>
                                        API Key: ' . $feed['api_key'] . '<br>
                                        Export: ' . ( isset($feed['export']) && $feed['export'] == 'selected' ? 'Selected Properties' : 'All On Market Properties' ) . '<br>
                                        Active Properties: ';

                                        $args = array(
                                            'post_type' => 'property',
                                            'nopaging' => true,
                                            'fields' => 'ids',
                                        );

                                        $lettings_departments = $this->ph_get_all_lettings_departments();

                                        $meta_query = array(
                                            array(
                                                'key' => '_on_market',
                                                'value' => 'yes'
                                            ),
                                            array(
                                                'key' => '_department',
                                                'value' => $lettings_departments,
                                                'compare' => 'IN'
                                            ),
                                        );

                                        if ( isset($feed['export']) && $feed['export'] == 'selected' )
                                        {
                                            $meta_query[] = array(
                                                'key' => '_sturents_portal_' . $i,
                                                'value' => 'yes'
                                            );
                                        }

                                        $args['meta_query'] = $meta_query;

                                        $property_query = new WP_Query( $args );
                                        
                                        echo number_format($property_query->found_posts);
                                    }
                                    echo '</td>';
                                    echo '<td class="actions">
                                        <a class="button" href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents&section=edit' . $feed['type'] . '&id=' . $i ) . '">' . __( 'Edit', 'propertyhive' ) . '</a>';
                                        if ( $feed['type'] == 'import')
                                        {
                                            echo '&nbsp;<a class="button" href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents&section=logs&id=' . $i ) . '">' . __( 'Logs', 'propertyhive' ) . '</a>';
                                        }
                                        if ( $feed['type'] == 'import' && $feed['mode'] == 'live' )
                                        {
                                            echo '&nbsp;<a class="button" onclick="jQuery(this).text(\'' . __( 'Running', 'propertyhive' ) . '...\'); jQuery(\'a.button\').attr(\'disabled\', \'disabled\');" href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents&custom_sturents_property_import_cron=phsturentsimportcronhook&id=' . $i ) . '">' . __( 'Run Now', 'propertyhive' ) . '</a>';
                                        }
                                        if ( $feed['type'] == 'export' && $feed['mode'] == 'live' )
                                        {
                                            echo '&nbsp;<a class="button" onclick="alert(\'This will push all properties to StuRents that are on the market and have been selected to be sent to StuRents. Please be patient as this may take a few minutes.\'); jQuery(this).text(\'' . __( 'Pushing', 'propertyhive' ) . '...\'); jQuery(\'a.button\').attr(\'disabled\', \'disabled\');" href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents&action=sturentspushall&id=' . $i ) . '">' . __( 'Push All Properties', 'propertyhive' ) . '</a>';
                                        }
                                        echo '</td>';
                                    echo '</tr>';
                                }
                            }
                            else
                            {
                                echo '<tr>';
                                    echo '<td align="center" colspan=2"">' . __( 'No existing StuRents integrations exist', 'propertyhive' ) . '</td>';
                                echo '</tr>';
                            }
                        ?>
                    </tbody>
                </table>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row" class="titledesc">
                &nbsp;
            </th>
            <td class="forminp forminp-button">
                <a href="<?php echo admin_url( 'admin.php?page=ph-settings&tab=sturents&section=addexport' ); ?>" class="button alignright" style="margin-left:3px;"><?php echo __( 'Add New Export', 'propertyhive' ); ?></a>
                <a href="<?php echo admin_url( 'admin.php?page=ph-settings&tab=sturents&section=addimport' ); ?>" class="button alignright"><?php echo __( 'Add New Import', 'propertyhive' ); ?></a>
            </td>
        </tr>
        <?php
    }

    /**
     * Uses the PropertyHive options API to save settings.
     *
     * @uses propertyhive_update_options()
     * @uses self::get_settings()
     */
    public function save() {
        global $current_section, $post;

        if (strpos($current_section, 'mapping_') !== FALSE)
        {
            
        }
        else
        {
            switch ($current_section)
            {
                case 'addimport': 
                {
                    // TODO: Validate
                    $error = '';

                    if ($error == '')
                    {
                        $current_sturents_options = get_option( 'propertyhive_sturents' );
                        
                        if ($current_sturents_options === FALSE)
                        {
                            // This is a new option
                            $new_sturents_options = array();
                            $new_sturents_options['feeds'] = array();
                        }
                        else
                        {
                            $new_sturents_options = $current_sturents_options;
                        }

                        $feed = array(
                            'type' => 'import',
                            'mode' => $_POST['mode'],
                            'landlord_id' => wp_strip_all_tags( $_POST['landlord_id'] ),
                            'public_key' => wp_strip_all_tags( $_POST['public_key'] ),
                        );

                        $new_sturents_options['feeds'][] = $feed;

                        update_option( 'propertyhive_sturents', $new_sturents_options );

                        PH_Admin_Settings::add_message( __( 'Import details saved successfully', 'propertyhive' ) . ' ' . '<a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }
                    else
                    {
                        PH_Admin_Settings::add_error( __( 'Error: ', 'propertyhive' ) . ' ' . $error . ' <a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }

                    break;
                }
                case 'editimport': 
                {
                    $current_id = ( !isset( $_REQUEST['id'] ) ) ? '' : sanitize_title( $_REQUEST['id'] );
                    $current_sturents_options = get_option( 'propertyhive_sturents' );

                    // TODO: Validate
                    $error = '';

                    if ($error == '')
                    {
                        $new_sturents_options = $current_sturents_options;

                        $feed = array(
                            'type' => 'import',
                            'mode' => $_POST['mode'],
                            'landlord_id' => wp_strip_all_tags( $_POST['landlord_id'] ),
                            'public_key' => wp_strip_all_tags( $_POST['public_key'] ),
                        );

                        $new_sturents_options['feeds'][$current_id] = $feed;

                        update_option( 'propertyhive_sturents', $new_sturents_options );
                        
                        PH_Admin_Settings::add_message( __( 'Import details saved successfully', 'propertyhive' ) . ' ' . '<a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }
                    else
                    {
                        PH_Admin_Settings::add_error( __( 'Error: ', 'propertyhive' ) . ' ' . $error . ' <a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }

                    break;
                }
                case 'addexport': 
                {
                    // TODO: Validate
                    $error = '';

                    if ($error == '')
                    {
                        $current_sturents_options = get_option( 'propertyhive_sturents' );
                        
                        if ($current_sturents_options === FALSE)
                        {
                            // This is a new option
                            $new_sturents_options = array();
                            $new_sturents_options['feeds'] = array();
                        }
                        else
                        {
                            $new_sturents_options = $current_sturents_options;
                        }

                        $feed = array(
                            'type' => 'export',
                            'mode' => $_POST['mode'],
                            'landlord_id' => wp_strip_all_tags( $_POST['landlord_id'] ),
                            'api_key' => wp_strip_all_tags( $_POST['api_key'] ),
                            'price_per' => wp_strip_all_tags( $_POST['price_per'] ),
                            'export' => wp_strip_all_tags( $_POST['export'] ),
                            'only_send_if_different' => wp_strip_all_tags( $_POST['only_send_if_different'] ),
                        );

                        $new_sturents_options['feeds'][] = $feed;

                        update_option( 'propertyhive_sturents', $new_sturents_options );

                        PH_Admin_Settings::add_message( __( 'Export details saved successfully', 'propertyhive' ) . ' ' . '<a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }
                    else
                    {
                        PH_Admin_Settings::add_error( __( 'Error: ', 'propertyhive' ) . ' ' . $error . ' <a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }

                    break;
                }
                case 'editexport': 
                {
                    $current_id = ( !isset( $_REQUEST['id'] ) ) ? '' : sanitize_title( $_REQUEST['id'] );
                    $current_sturents_options = get_option( 'propertyhive_sturents' );

                    // TODO: Validate
                    $error = '';

                    if ($error == '')
                    {
                        $new_sturents_options = $current_sturents_options;

                        $feed = array(
                            'type' => 'export',
                            'mode' => $_POST['mode'],
                            'landlord_id' => wp_strip_all_tags( $_POST['landlord_id'] ),
                            'api_key' => wp_strip_all_tags( $_POST['api_key'] ),
                            'price_per' => wp_strip_all_tags( $_POST['price_per'] ),
                            'export' => wp_strip_all_tags( $_POST['export'] ),
                            'only_send_if_different' => wp_strip_all_tags( $_POST['only_send_if_different'] ),
                        );

                        $new_sturents_options['feeds'][$current_id] = $feed;

                        update_option( 'propertyhive_sturents', $new_sturents_options );
                        
                        PH_Admin_Settings::add_message( __( 'Export details saved successfully', 'propertyhive' ) . ' ' . '<a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }
                    else
                    {
                        PH_Admin_Settings::add_error( __( 'Error: ', 'propertyhive' ) . ' ' . $error . ' <a href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents' ) . '">' . __( 'Return to StuRents Options', 'propertyhive' ) . '</a>' );
                    }

                    break;
                }
            }
        }
    }

    public function add_sturents_checkboxes()
    {        
        $current_sturents_options = get_option( 'propertyhive_sturents', array() );

        $individual = false;
        if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']))
        {
            foreach ($current_sturents_options['feeds'] as $i => $portal)
            {
                if ( $portal['type'] == 'export' && isset($portal['export']) && $portal['export'] == 'selected' && $portal['mode'] == 'live' )
                {
                    $individual = true;
                }
            }

            if ( $individual )
            {
                echo '<p class="form-field"><label><strong>' . __( 'Send to StuRents', 'propertyhive' ) . ':</strong></label></p>';

                foreach ($current_sturents_options['feeds'] as $i => $portal)
                {
                    if ( $portal['type'] == 'export' && isset($portal['export']) && $portal['export'] == 'selected' && $portal['mode'] == 'live' )
                    {
                        propertyhive_wp_checkbox( array( 
                            'id' => '_sturents_portal_' . $i, 
                            'label' => 'StuRents', 
                            //'desc_tip' => true,
                            //'description' => __( 'Setting the property to be on the market enables it to be displayed on the website and in applicant matches', 'propertyhive' ), 
                        ) );
                    }
                }
            }
        }
    }

    public function save_sturents_checkboxes( $post_id )
    {
        $current_sturents_options = get_option( 'propertyhive_sturents', array() );
            
        if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']))
        {
            foreach ($current_sturents_options['feeds'] as $i => $portal)
            {
                if ( $portal['type'] == 'export' && isset($portal['export']) && $portal['export'] == 'selected' && $portal['mode'] == 'live' )
                {
                    update_post_meta($post_id, '_sturents_portal_' . $i, ( isset($_POST['_sturents_portal_' . $i]) ? $_POST['_sturents_portal_' . $i] : '' ) );
                }
            }
        }
    }

    public function sturents_property_filter_query( $vars, $typenow )
    {
        if ( 'property' === $typenow )
        {
            if ( ! empty( $_GET['_marketing'] ) && substr($_GET['_marketing'], 0, 16) == 'sturents_portal_' )
            {
                $portal_id = sanitize_text_field( str_replace("sturents_portal_", "", $_GET['_marketing']) );

                $vars['meta_query'][] = array(
                    'key' => '_on_market',
                    'value' => 'yes'
                );

                $vars['meta_query'][] = array(
                    'key' => '_sturents_portal_' . $portal_id,
                    'value' => 'yes'
                );
            }
        }

        return $vars;
    }

    public function sturents_property_filter_marketing_options( $options )
    {
        $current_sturents_options = get_option( 'propertyhive_sturents' );

        if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']))
        {
            foreach ($current_sturents_options['feeds'] as $i => $portal)
            {
                if ( $portal['type'] == 'export' && isset($portal['export']) && $portal['export'] == 'selected' && $portal['mode'] == 'live' )
                {
                    $options['sturents_portal_' . $i] = 'Active On Sturents';
                }
            }
        }
        return $options;
    }

    public function sturents_bulk_edit_save( $property )
    {
        $current_sturents_options = get_option( 'propertyhive_sturents' );

        if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']))
        {
            foreach ($current_sturents_options['feeds'] as $i => $portal)
            {
                if ( $portal['type'] == 'export' && isset($portal['export']) && $portal['export'] == 'selected' && $portal['mode'] == 'live' )
                {
                    if ( isset($_REQUEST['_sturents_portal_' . $i]) && $_REQUEST['_sturents_portal_' . $i] == 'yes' )
                    {
                        update_post_meta( $property->id, '_sturents_portal_' . $i, 'yes' );
                    }
                    elseif ( isset($_REQUEST['_sturents_portal_' . $i]) && $_REQUEST['_sturents_portal_' . $i] == 'no' )
                    {
                        update_post_meta( $property->id, '_sturents_portal_' . $i, '' );
                    }
                }
            }
        }
    }

    public function sturents_bulk_edit_options()
    {
        $current_sturents_options = get_option( 'propertyhive_sturents' );

        if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']))
        {
            foreach ($current_sturents_options['feeds'] as $i => $portal)
            {
                if ( $portal['type'] == 'export' && isset($portal['export']) && $portal['export'] == 'selected' && $portal['mode'] == 'live' )
                {
                ?>
                    <div class="inline-edit-group">
                        <label class="alignleft">
                            <span class="title"><?php _e( 'Active On Sturents', 'propertyhive' ); ?></span>
                            <span class="input-text-wrap">
                                <select class="sturents_portal_<?php echo $i; ?>" name="_sturents_portal_<?php echo $i; ?>">
                                <?php
                                    $options = array(
                                        ''  => __( '— No Change —', 'propertyhive' ),
                                        'yes' => __( 'Yes', 'propertyhive' ),
                                        'no' => __( 'No', 'propertyhive' ),
                                    );
                                    foreach ($options as $key => $value) {
                                        echo '<option value="' . esc_attr( $key ) . '">' . $value . '</option>';
                                    }
                                ?>
                                </select>
                            </span>
                        </label>
                    </div>
                <?php
                }
            }
        }
    }

    public function custom_property_columns( $column )
    {
        global $post, $propertyhive, $the_property;

        if ( empty( $the_property ) || $the_property->ID != $post->ID ) 
        {
            $the_property = new PH_Property( $post->ID );
        }

        switch ( $column ) 
        {
            case 'status' :
            {
                $current_sturents_options = get_option( 'propertyhive_sturents', array() );

                $lettings_departments = $this->ph_get_all_lettings_departments();

                if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']))
                {
                    foreach ( $current_sturents_options['feeds'] as $portal_id => $portal )
                    {
                        if ( $portal['mode'] == 'live' && $portal['type'] == 'export' && isset($portal['export']) && $portal['export'] == 'selected' && $the_property->_on_market == 'yes' && in_array( $the_property->_department, $lettings_departments ) && $the_property->{'_sturents_portal_' . $portal_id} == 'yes' )
                        {
                            echo '<br>StuRents';
                        }
                    }
                }

                break;
            }
        }
    }

    public function sturents_save_property( $post_id )
    {
        global $wpdb;
        
        if ( $post_id == null )
            return;

        if ( get_post_type($post_id) != 'property' )  
            return; 

        // If this is just a revision, don't make request.
        if ( wp_is_post_revision( $post_id ) )
            return;

        if ( get_post_status( $post_id ) == 'auto-draft' )
            return;
        
        $data = $this->generate_property_export_data( $post_id );

        $this->do_property_export_request( $data, $post_id );
    }

    private function generate_property_export_data( $post_id )
    {
        $data = array();

        // Property object
        $data['property'] = array();
        $data['property']['reference'] = $post_id;
        $available_from = '';
        $available_date = get_post_meta( $post_id, '_available_date', TRUE );
        if ( $available_date !== FALSE && $available_date != '' && $available_date != '0000-00-00' )
        {
            $available_from = date("d/m/Y", strtotime($available_date));
        }
        $data['property']['available_from'] = $available_from;
        $bedrooms = get_post_meta( $post_id, '_bedrooms', TRUE );
        $data['property']['beds'] = ( (!empty($bedrooms) && is_numeric($bedrooms)) ? (int)$bedrooms : '' );
        $data['property']['rooms_let_individually'] = TRUE;
        $data['property']['quantity'] = 1;
        $data['property']['property_type'] = 'Residential';
        $data['property']['facilities'] = array();
        $data['property']['eligibility'] = array(
            'undergraduate_student' => true,
            'postgraduate_student' => true,
            'professional' => true,
            'trainee' => true,
            'dss' => false,
            'pets_permitted' => false,
            'smoking_permitted' => false,
        );
        $data['property']['description'] = get_the_excerpt( $post_id );

        $data['property']['address'] = array();
        $data['property']['address']['property_name'] = '';
        $data['property']['address']['property_number'] = get_post_meta( $post_id, '_address_name_number', TRUE );
        $data['property']['address']['road_name'] = get_post_meta( $post_id, '_address_street', TRUE );
        $city = '';
        $address_2 = get_post_meta( $post_id, '_address_2', TRUE );
        $address_3 = get_post_meta( $post_id, '_address_3', TRUE );
        $address_4 = get_post_meta( $post_id, '_address_4', TRUE );
        if ( $address_3 !== FALSE && $address_3 != '' )
        {
            $city = $address_3;
        }
        elseif ( $address_4 !== FALSE && $address_4 != '' )
        {
            $city = $address_4;
        }
        elseif ( $address_2 !== FALSE && $address_2 != '' )
        {
            $city = $address_2;
        }
        $data['property']['address']['city'] = $city;
        $data['property']['address']['postcode'] = get_post_meta( $post_id, '_address_postcode', TRUE );
        $data['property']['address']['uprn'] = '';

        $data['property']['coordinates'] = array();
        $data['property']['coordinates']['lat'] = get_post_meta( $post_id, '_latitude', TRUE );
        $data['property']['coordinates']['lng'] = get_post_meta( $post_id, '_longitude', TRUE );

        $data['property']['contract'] = array();
        $data['property']['contract']['price'] = array();
        $price = get_post_meta( $post_id, '_rent', TRUE );
        $data['property']['contract']['price']['amount'] = ( (!empty($price) && is_numeric($price)) ? (int)$price : 0 );
        $amount_per = 'property';
        $time_period = '';
        switch (get_post_meta( $post_id, '_rent_frequency', TRUE ))
        {
            case "pppw": { $time_period = 'week'; $amount_per = 'person'; break; }
            case "pw": { $time_period = 'week'; break; }
            case "pcm": { $time_period = 'month'; break; }
            case "pq": { $time_period = 'quarter'; break; }
            case "pa": { $time_period = 'year'; break; }
        }
        $data['property']['contract']['price']['amount_per'] = $amount_per;
        $data['property']['contract']['price']['time_period'] = $time_period;
        $data['property']['contract']['price']['utilities'] = array(
            'water' => FALSE,
            'gas' => FALSE,
            'electricity' => FALSE,
            'broadband' => FALSE,
            'phone' => FALSE,
            'contents_insurance' => FALSE
        );
        $data['property']['contract']['deposit'] = array();
        $deposit = get_post_meta( $post_id, '_deposit', TRUE );
        $data['property']['contract']['deposit']['amount'] = ( (!empty($deposit) && is_numeric($deposit)) ? (int)$deposit : 0 );
        $data['property']['contract']['deposit']['amount_per'] = $amount_per;

        $data['property']['contract']['min_contract_weeks'] = '52';

        // Media
        $data['property']['media'] = array();
        $data['property']['media']['photos'] = array();
        $data['property']['media']['videos'] = array();
        $data['property']['media']['floorplans'] = array();

        // IMAGES
        $attachment_ids = get_post_meta( $post_id, '_photos', TRUE );
        if ( is_array($attachment_ids) && !empty($attachment_ids) )
        {
            foreach ($attachment_ids as $attachment_id)
            {
                $url = wp_get_attachment_image_src( $attachment_id, 'large' );
                if ($url !== FALSE)
                {
                    $thumb_url = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );

                    $media = array(
                        'type' => 'url',
                        'photo' => $url[0],
                        'thumb' => $thumb_url[0],
                    );

                    $data['property']['media']['photos'][] = $media;
                }
            }
        }

        // VIDEOS
        $num_property_virtual_tours = get_post_meta( $post_id, '_virtual_tours', TRUE );
        if ($num_property_virtual_tours == '') { $num_property_virtual_tours = 0; }

        for ($i = 0; $i < $num_property_virtual_tours; ++$i)
        {
            $data['property']['media']['videos'][] = get_post_meta( $post_id, '_virtual_tour_' . $i, TRUE );
        }

        // FLOORPLANS
        $attachment_ids = get_post_meta( $post_id, '_floorplans', TRUE );
        if ( is_array($attachment_ids) && !empty($attachment_ids) )
        {
            foreach ($attachment_ids as $attachment_id)
            {
                $url = wp_get_attachment_image_src( $attachment_id, 'large' );
                if ($url !== FALSE)
                {
                    $data['property']['media']['floorplans'][] = $url[0];
                }
            }
        }

        $data['property']['energy_performance'] = array();
        $data['property']['energy_performance']['epc_reference'] = '';
        $attachment_ids = get_post_meta( $post_id, '_epcs', TRUE );
        $epc_certificate = '';
        if ( is_array($attachment_ids) && !empty($attachment_ids) )
        {
            $url = wp_get_attachment_image_src( $attachment_ids[0], 'large' );
            if ( $url !== FALSE )
            {
                $epc_certificate = $url[0];
            }
        }
        $data['property']['energy_performance']['epc_certificate'] = $epc_certificate;
        $data['property']['energy_performance']['eef_current'] = '';
        $data['property']['energy_performance']['eef_potential'] = '';
        $data['property']['energy_performance']['co2_current'] = '';
        $data['property']['energy_performance']['co2_potential'] = '';

        $data['property']['accreditations'] = array();

        $incomplete = TRUE;
        if ( get_post_meta( $post_id, '_on_market', TRUE ) == 'yes' && get_post_status( $post_id ) == 'publish' )
        {
            $incomplete = FALSE;
        }
        $data['property']['incomplete'] = $incomplete;

        $data = $data['property'];

        return $data;
    }

    private function do_property_export_request( $data, $post_id, $feed_id = '' )
    {
        $current_sturents_options = get_option( 'propertyhive_sturents' );
        if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']) )
        {
            foreach ( $current_sturents_options['feeds'] as $i => $feed)
            {
                if ( $feed['mode'] == 'live' && $feed['type'] == 'export' ) { }else{ continue; }

                if ($feed_id != '')
                {
                    if ( $i != $feed_id ) { continue; }
                }

                if ( isset($feed['export']) && $feed['export'] == 'selected' && get_post_meta($post_id, '_sturents_portal_' . $i, TRUE) != 'yes' )
                {
                    $data['incomplete'] = TRUE;
                }

                // set 'price_per' on data
                if ( isset($feed['price_per']) && ( $feed['price_per'] == 'person' || $feed['price_per'] == 'property' ) )
                {
                    $data['contract']['price']['amount_per'] = $feed['price_per'];
                }

                $new_data = $data;

                $new_data['landlord'] = $feed['landlord_id'];
                $new_data = apply_filters( 'ph_sturents_send_request_data', $new_data, $post_id );

                $json = json_encode($new_data);

                $do_request = true;
                if ( isset($feed['only_send_if_different']) && $feed['only_send_if_different'] == '1' )
                {
                    $previous_hash = get_post_meta( $post_id, '_sturents_sha1_' . $i, TRUE );

                    if ( $previous_hash == sha1($json) )
                    {
                        // Matches the data sent last time. Don't send again
                        $do_request = false;
                    }
                }

                if ( $do_request )
                {
                    $ch = curl_init();

                    $options = array(
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_CONNECTTIMEOUT => 120,

                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $json,

                        CURLOPT_URL => 'https://sturents.com/api/house?landlord=' . $feed['landlord_id'] . '&auth=' . md5($json . $feed['api_key']),

                        CURLOPT_HTTPHEADER => array(
                            'Content-Type: application/json',
                            'Accept: application/json'
                        )
                    );

                    curl_setopt_array($ch , $options);

                    $output = curl_exec($ch);

                    if ( $output === FALSE )
                    {
                        set_transient("sturents_save_error_" . $post_id, "Error sending cURL request: " . curl_getinfo($ch) . " - " .curl_errno($ch) . " - " . curl_error($ch), 30);

                        //$this->log_error($portal['portal_id'], 1, "Error sending cURL request: " . curl_getinfo($ch) . " - " .curl_errno($ch) . " - " . curl_error($ch), $request_data, '', $post_id);

                        //return false;
                    }
                    else
                    {
                        $response = json_decode($output, TRUE);

                        if ( isset($response['success']) && $response['success'] == true )
                        {
                            // Save the SHA-1 hash so we know for next time whether to push it again or not
                            update_post_meta( $post_id, '_sturents_sha1_' . $i, sha1($json) );
                        }
                        else
                        {
                            set_transient("sturents_save_error_" . $post_id, print_r($response, TRUE), 30);
                        }

                        /*if (isset($response['errors']) && !empty($response['errors']))
                        {
                            foreach ($response['errors'] as $error)
                            {
                                //$this->log_error($portal['portal_id'], 1, "Error returned from " . $portal['portal_name'] . " in response: " . $error['error_code'] . " - " .$error['error_description'], $request_data, $output, $post_id);
                            }

                            return false;
                        }
                        else
                        {
                            if ( $log_success )
                            {
                                //$this->log_error($portal['portal_id'], 0, "Request successful", $request_data, $output, $post_id);
                            }
                        }*/
                    }
                }
            }
        }
    }

    private function ph_get_all_lettings_departments()
    {
        $lettings_departments = array( 'residential-lettings' );
        $custom_departments = ph_get_custom_departments();
        if ( !empty($custom_departments) )
        {
            foreach ( $custom_departments as $key => $department )
            {
                if ( isset($department['based_on']) && $department['based_on'] == 'residential-lettings' )
                {
                    $lettings_departments[] = $key;
                }
            }
        }
        return $lettings_departments;
    }

    public function log( $instance_id, $message, $agent_ref = '' )
    {
        if ( $instance_id != '' )
        {
            global $wpdb;

            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
            $current_date = $current_date->format("Y-m-d H:i:s");

            $data = array(
                'instance_id' => $instance_id,
                'severity' => 0,
                'entry' => substr( ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message, 0, 255),
                'log_date' => $current_date
            );
        
            $wpdb->insert( 
                $wpdb->prefix . "ph_sturents_logs_instance_log", 
                $data
            );
        }
    }

    public function log_error( $instance_id, $message, $agent_ref = '' )
    {
        if ( $instance_id != '' )
        {
            global $wpdb;

            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
            $current_date = $current_date->format("Y-m-d H:i:s");

            $data = array(
                'instance_id' => $instance_id,
                'severity' => 1,
                'entry' => substr( ( ( $agent_ref != '' ) ? 'AGENT_REF: ' . $agent_ref . ' - ' : '' ) . $message, 0, 255),
                'log_date' => $current_date
            );
        
            $wpdb->insert( 
                $wpdb->prefix . "ph_sturents_logs_instance_log", 
                $data
            );
        }
    }


}

endif;

/**
 * Returns the main instance of PH_StuRents to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return PH_StuRents
 */
function PHStuRents() {
    return PH_StuRents::instance();
}

PHStuRents();

if( is_admin() && file_exists(  dirname( __FILE__ ) . '/propertyhive-sturents-update.php' ) )
{
    include_once( dirname( __FILE__ ) . '/propertyhive-sturents-update.php' );
}