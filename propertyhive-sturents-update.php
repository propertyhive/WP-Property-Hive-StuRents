<?php

/*
*  properthive_sturents_update
*
*  this class will connect with and download updates from the PropertyHive website
*
*  @type	class
*  @date	01/09/2016
*
*/

class properthive_sturents_update
{
	var $settings;
	
	
	/*
	*  Constructor
	*
	*  @description: 
	*  @since 1.0.0
	*  @created: 01/09/16
	*/
	
	function __construct()
	{
		// vars
		$this->settings = array(
			'version'	=>	'',
			'remote'	=>	'http://wp-property-hive.com/add-on-store/propertyhive-sturents-v1hsan83z/update-info.php',
			'basename'	=>	plugin_basename( str_replace('-update.php', '.php', __FILE__) ),
			'slug'		=>	dirname( plugin_basename( str_replace('-update.php', '.php', __FILE__) ) )
		);
		
		$license = get_option( 'propertyhive_license_key_details', array() );

		if ( !is_array( $license ) ) { $license = array(); }

	    if ( isset($license['active']) && $license['active'] == '1' && isset($license['expires_at']) && $license['expires_at'] != '' && strtotime($license['expires_at']) > time() )
	    {
	        // Active and current license

			// filters
			add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
			add_filter('plugins_api', array($this, 'check_info'), 10, 3);
		}
	}
	
	
	/*
	*  get_remote
	*
	*  @description: 
	*  @since: 3.6
	*  @created: 31/01/13
	*/
	
	function get_remote()
	{
		 // vars
        $info = false;
        
		// Get the remote info
        $request = wp_remote_post( $this->settings['remote'] );
        if( !is_wp_error($request) || wp_remote_retrieve_response_code($request) === 200)
        {
            $info = @unserialize($request['body']);
            $info->slug = $this->settings['slug'];
        }
        
        
        return $info;
	}
	
	
	/*
	*  check_update
	*
	*  @description: 
	*  @since: 3.6
	*  @created: 31/01/13
	*/
	
	function check_update( $transient )
	{
	    if( empty($transient->checked) )
	    {
            return $transient;
        }

        
        // vars
        $info = $this->get_remote();
        
        // validate
        if( !$info )
        {
	        return $transient;
        }
        
        // compare versions
        if( version_compare($info->version, $this->get_version(), '<=') )
        {
        	return $transient;
        }

        
        // create new object for update
        $obj = new stdClass();
        $obj->slug = $info->slug;
        $obj->new_version = $info->version;
        $obj->url = $info->homepage;
        $obj->package = $info->download_link;
        
        
        // add to transient
        $transient->response[ $this->settings['basename'] ] = $obj;
        
        
        return $transient;
	}
	
	
	/*
	*  check_info
	*
	*  @description: 
	*  @since: 3.6
	*  @created: 31/01/13
	*/
	
    function check_info( $false, $action, $arg )
    {
    	// validate
    	if( !isset($arg->slug) || $arg->slug != $this->settings['slug'] )
    	{
	    	return $false;
    	}
    	
    	
    	if( $action == 'plugin_information' )
    	{
	    	$false = $this->get_remote();
    	}
    	
    	        
        return $false;
    }
    
    
    /*
    *  get_version
    *
    *  This function will return the current version of this add-on 
    *
    *  @type	function
    *  @date	27/08/13
    *
    *  @param	N/A
    *  @return	(string)
    */
    
    function get_version()
    {
    	// populate only once
    	if( !$this->settings['version'] )
    	{
	    	$plugin_data = get_plugin_data( str_replace('-update.php', '.php', __FILE__) );
	    	
	    	$this->settings['version'] = $plugin_data['Version'];
    	}
    	
    	// return
    	return $this->settings['version'];
	}
}


// instantiate
if( is_admin() )
{
	new properthive_sturents_update();
}

?>
