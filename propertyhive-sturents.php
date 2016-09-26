<?php
/**
 * Plugin Name: Property Hive StuRents Add On
 * Plugin Uri: http://wp-property-hive.com/addons/sturents-wordpress-import-export/
 * Description: Add on for Property Hive which imports and exports properties from the StuRents website
 * Version: 1.0.2
 * Author: PropertyHive
 * Author URI: http://wp-property-hive.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_StuRents' ) ) :

final class PH_StuRents {

    /**
     * @var string
     */
    public $version = '1.0.2';

    /**
     * @var PropertyHive The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main PropertyHive Radial Search Instance
     *
     * Ensures only one instance of Property Hive Radial Search is loaded or can be loaded.
     *
     * @static
     * @return PropertyHive Radial Search - Main instance
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

        add_action( 'init', array( $this, 'run_custom_sturents_property_import_cron') );

        add_action( 'admin_notices', array( $this, 'sturents_error_notices') );

        add_filter( 'propertyhive_settings_tabs_array', array( $this, 'add_settings_tab' ), 19 );
        add_action( 'propertyhive_settings_' . $this->id, array( $this, 'output' ) );
        add_action( 'propertyhive_settings_save_' . $this->id, array( $this, 'save' ) );

        add_action( 'propertyhive_admin_field_sturents_existing_feeds', array( $this, 'sturents_existing_feeds' ) );

        add_action( 'save_post', array( $this, 'sturents_save_property' ) );

        add_action( 'phsturentspropertyimportcronhook', array( $this, 'sturents_property_import_execute_feed' ) );
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
        if( isset($_GET['custom_sturents_property_import_cron']) )
        {
            do_action($_GET['custom_sturents_property_import_cron']);
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
                default:
                {
                    $hide_save_button = true;
                    propertyhive_admin_fields( self::get_sturents_settings() );
                }
            }
        }
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
                                        API Key: ' . $feed['api_key'];
                                    }
                                    echo '</td>';
                                        echo '<td class="actions">';
                                        if ( $feed['type'] == 'import' )
                                        {
                                            echo '<a class="button" onclick="jQuery(this).text(\'' . __( 'Running', 'propertyhive' ) . '...\'); jQuery(\'a.button\').attr(\'disabled\', \'disabled\');" href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents&custom_sturents_property_import_cron=phsturentspropertyimportcronhook&id=' . $i ) . '">' . __( 'Run Now', 'propertyhive' ) . '</a>&nbsp;';
                                        }
                                        echo '<a class="button" href="' . admin_url( 'admin.php?page=ph-settings&tab=sturents&section=edit' . $feed['type'] . '&id=' . $i ) . '">' . __( 'Edit', 'propertyhive' ) . '</a>
                                        </td>';
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
        $data['property']['contract']['deposit']['amount_per'] = 'person';

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
        if ( get_post_meta( $post_id, '_on_market', TRUE ) == 'yes' )
        {
            $incomplete = FALSE;
        }
        $data['property']['incomplete'] = $incomplete;

        $data = $data['property'];

        $current_sturents_options = get_option( 'propertyhive_sturents' );
        if ( isset($current_sturents_options['feeds']) && !empty($current_sturents_options['feeds']) )
        {
            foreach ( $current_sturents_options['feeds'] as $i => $feed)
            {
                if ( $feed['mode'] == 'live' && $feed['type'] == 'export' ) { }else{ continue; }

                $new_data = $data;

                $new_data['landlord'] = $feed['landlord_id'];
                $new_data = apply_filters( 'ph_sturents_send_request_data', $new_data, $post_id );

                $json = json_encode($new_data);

                $ch = curl_init();
                         
                $options = array( 
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_SSL_VERIFYPEER => false,

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
                $response = json_decode($output, TRUE);
                
                if ( $output === FALSE )
                {
                    //$this->log_error($portal['portal_id'], 1, "Error sending cURL request: " . curl_getinfo($ch) . " - " .curl_errno($ch) . " - " . curl_error($ch), $request_data, '', $post_id);
                
                    //return false;
                }
                else
                {
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

                //return $response;
            }
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