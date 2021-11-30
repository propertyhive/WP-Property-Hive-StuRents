<?php
error_reporting( 0 );
set_time_limit( 0 );
ini_set('memory_limit','20000M');

global $wpdb, $post;

// Check PropertyHive Plugin is active as we'll need this
if( in_array( 'propertyhive/propertyhive.php', (array) get_option( 'active_plugins', array() ) ) )
{
    $sturents_options = get_option( 'propertyhive_sturents' );
    if ( isset($sturents_options['feeds']) && is_array($sturents_options['feeds']) && !empty($sturents_options['feeds']) )
    {
        if ( !function_exists('media_handle_upload') ) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        // Get primary office in the event office mappings weren't set
        $primary_office_id = '';
        $args = array(
            'post_type' => 'office',
            'nopaging' => true
        );
        $office_query = new WP_Query($args);

        if ($office_query->have_posts())
        {
            while ($office_query->have_posts())
            {
                $office_query->the_post();

                if (get_post_meta(get_the_ID(), 'primary', TRUE) == '1')
                {
                    $primary_office_id = get_the_ID();
                }
            }
        }
        $office_query->reset_postdata();

        foreach ( $sturents_options['feeds'] as $i => $feed )
        {
            if ( isset($_GET['id']) && $_GET['id'] != $i ) { continue; }

            if ( $feed['type'] == 'import' && $feed['mode'] == 'live' ) {  }else{ continue; }

            if ( !isset($feed['api_version']) || $feed['api_version'] != '2' ) { continue; }

            $ok_to_run_import = true;

            if ( $ok_to_run_import )
            {
                $properties_to_import = array();
                $page = 1;
                $process_next_page = true;
                $uri = 'https://sturents.com/api/properties';

                while ( $process_next_page === true )
                {
                    $timestamp = time();
                    $auth_token = hash_hmac('sha256', $timestamp, $feed['public_key']);

                    $params = array(
                        'landlord' => $feed['landlord_id'],
                        'timestamp' => $timestamp,
                        'auth' => $auth_token,
                        'version' => 2,
                        'page' => (int)$page,
                    );
                    $query = $uri . '?' . http_build_query($params);

                    $json = file_get_contents($query);
                    $data = json_decode($json, true);

                    if ( $data !== FALSE && isset($data['pagination'], $data['properties']) )
                    {
                        if ( is_array($data['properties']) && !empty($data['properties']) )
                        {
                            $properties_to_import = array_merge($properties_to_import, $data['properties']);
                        }

                        if ( isset($data['pagination']['next']) && is_numeric($data['pagination']['next']) && $data['pagination']['next'] <> $page )
                        {
                            $page = $data['pagination']['next'];
                        }
                        else
                        {
                            $process_next_page = false;
                        }
                    }
                    else
                    {
                        $process_next_page = false;
                    }
                }

                if ( count($properties_to_import) > 0 )
                {
                    $imported_ref_key = '_imported_ref_' . $i;
                    $import_refs = array();

                    foreach ($properties_to_import as $property)
                    {
                        $inserted_updated = false;

                        $display_address = '';
                        if ( isset($property['address']['road_name']) && trim($property['address']['road_name']) != '' )
                        {
                            $display_address .= trim($property['address']['road_name']);
                        }
                        if ( isset($property['address']['city']) && trim($property['address']['city']) != '' )
                        {
                            if ( $display_address != '' ) { $display_address .= ', '; }
                            $display_address .= trim($property['address']['city']);
                        }

                        $args = array(
                            'post_type' => 'property',
                            'posts_per_page' => 1,
                            'post_status' => 'any',
                            'meta_query' => array(
                                array(
                                    'key' => $imported_ref_key,
                                    'value' => $property['property_id']
                                )
                            )
                        );
                        $property_query = new WP_Query($args);

                        if ($property_query->have_posts())
                        {
                            // We've imported this property before
                            while ($property_query->have_posts())
                            {
                                $property_query->the_post();

                                $post_id = get_the_ID();

                                $my_post = array(
                                    'ID'          	 => $post_id,
                                    'post_title'     => wp_strip_all_tags( $display_address ),
                                    'post_excerpt'   => $property['description'],
                                    'post_content' 	 => '',
                                    'post_status'    => 'publish',
                                );

                                // Update the post into the database
                                $post_id = wp_update_post( $my_post );

                                if ( !is_wp_error( $post_id ) )
                                {
                                    $inserted_updated = true;
                                }
                            }
                        }
                        else
                        {
                            // We've not imported this property before
                            $postdata = array(
                                'post_excerpt'   => $property['description'],
                                'post_content' 	 => '',
                                'post_title'     => wp_strip_all_tags( $display_address ),
                                'post_status'    => 'publish',
                                'post_type'      => 'property',
                                'comment_status' => 'closed',
                            );

                            $post_id = wp_insert_post( $postdata, true );

                            if ( !is_wp_error( $post_id ) )
                            {
                                $inserted_updated = true;
                            }
                        }
                        $property_query->reset_postdata();

                        if ( $inserted_updated )
                        {
                            // Need to check title and excerpt and see if they've gone in as blank but weren't blank in the feed
                            // If they are, then do the encoding
                            $inserted_post = get_post( $post_id );
                            if (
                                $inserted_post &&
                                $inserted_post->post_title == '' && $inserted_post->post_excerpt == '' &&
                                ($display_address != '' || $property['description'] != '')
                            )
                            {
                                $my_post = array(
                                    'ID'          	 => $post_id,
                                    'post_title'     => htmlentities(mb_convert_encoding(wp_strip_all_tags( $display_address ), 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
                                    'post_excerpt'   => htmlentities(mb_convert_encoding($property['description'], 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
                                    'post_content' 	 => '',
                                    'post_name' 	 => sanitize_title($display_address),
                                    'post_status'    => 'publish',
                                );

                                // Update the post into the database
                                wp_update_post( $my_post );
                            }

                            // Inserted property ok. Continue
                            update_post_meta( $post_id, $imported_ref_key, $property['property_id'] );
                            $import_refs[] = $property['property_id'];

                            // Address
                            update_post_meta( $post_id, '_reference_number', '' ); // Unsure on whether we should use the reference from StuRents here.
                            update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['address']['property_name']) ) ? $property['address']['property_name'] : '' ) . ' ' . ( ( isset($property['address']['property_number']) ) ? $property['address']['property_number'] : '' ) ) );
                            update_post_meta( $post_id, '_address_street', ( ( isset($property['address']['road_name']) ) ? $property['address']['road_name'] : '' ) );
                            update_post_meta( $post_id, '_address_two', '' );
                            update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['city']) ) ? $property['address']['city'] : '' ) );
                            update_post_meta( $post_id, '_address_four', '' );
                            update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address']['postcode']) ) ? $property['address']['postcode'] : '' ) );
                            update_post_meta( $post_id, '_address_country', 'GB' );

                            // Coordinates
                            update_post_meta( $post_id, '_latitude', ( ( isset($property['coordinates']['lat']) ) ? $property['coordinates']['lat'] : '' ) );
                            update_post_meta( $post_id, '_longitude', ( ( isset($property['coordinates']['lng']) ) ? $property['coordinates']['lng'] : '' ) );

                            // Owner
                            update_post_meta( $post_id, '_owner_contact_id', '' );

                            // Record Details
                            update_post_meta( $post_id, '_negotiator_id', get_current_user_id() );
                            $office_id = $primary_office_id;
                            update_post_meta( $post_id, '_office_id', $office_id );

                            // Residential Details
                            update_post_meta( $post_id, '_department', 'residential-lettings' );
                            update_post_meta( $post_id, '_bedrooms', ( ( isset($property['room_details']) && is_array($property['room_details']) ) ? count($property['room_details']) : '' ) );
                            update_post_meta( $post_id, '_bathrooms', ( ( isset($property['bathrooms']) ) ? $property['bathrooms'] : '' ) );
                            update_post_meta( $post_id, '_reception_rooms', '' );

                            // Residential Lettings Details
                            $price = isset($property['contracts'][0]['prices'][0]['price_per_person_per_week']) ?
                                    round(preg_replace("/[^0-9.]/", 2, $property['contracts'][0]['prices'][0]['price_per_person_per_week'])) :
                                    '';

                            update_post_meta( $post_id, '_rent', $price );

                            update_post_meta( $post_id, '_rent_frequency', 'pppw' );
                            update_post_meta( $post_id, '_price_actual', ($price * 52) / 12 );

                            update_post_meta( $post_id, '_poa', '' );

                            $deposit = isset($property['contracts'][0]['prices'][0]['deposit_per_person']) ?
                                    round(preg_replace("/[^0-9.]/", 2, $property['contracts'][0]['prices'][0]['deposit_per_person'])) :
                                    '';
                            update_post_meta( $post_id, '_deposit', $deposit );

                            $available_date = isset($property['contracts'][0]['end_date']) ? $property['contracts'][0]['end_date'] : '';
                            update_post_meta( $post_id, '_available_date', $available_date );

                            // Marketing
                            update_post_meta( $post_id, '_on_market', ( $property['disabled'] == 'yes' ? '' : 'yes' ) );
                            update_post_meta( $post_id, '_featured', '' );

                            // Features
                            $features = array();
                            if ( isset($property['facilities']) && !empty($property['facilities']) )
                            {
                                foreach ( $property['facilities'] as $facility )
                                {
                                    if ( trim($facility) != '' )
                                    {
                                        $features[] = trim($facility);
                                    }
                                }
                            }

                            update_post_meta( $post_id, '_features', count( $features ) );

                            $i = 0;
                            foreach ( $features as $feature )
                            {
                                update_post_meta( $post_id, '_feature_' . $i, $feature );
                                ++$i;
                            }

                            // Media - Images
                            $media_ids = array();
                            $previous_media_ids = get_post_meta( $post_id, '_photos', TRUE );

                            if (isset($property['media']['photos']) && !empty($property['media']['photos']))
                            {
                                foreach ($property['media']['photos'] as $image)
                                {
                                    if (
                                        substr( strtolower($image['photo']), 0, 2 ) == '//' ||
                                        substr( strtolower($image['photo']), 0, 4 ) == 'http'
                                    )
                                    {
                                        // This is a URL
                                        $url = $image['photo'];
                                        $description = $image['caption'];

                                        $filename = basename( $url );

                                        // Check, based on the URL, whether we have previously imported this media
                                        $imported_previously = false;
                                        $imported_previously_id = '';
                                        if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                                        {
                                            foreach ( $previous_media_ids as $previous_media_id )
                                            {
                                                if ( get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url )
                                                {
                                                    $imported_previously = true;
                                                    $imported_previously_id = $previous_media_id;
                                                    break;
                                                }
                                            }
                                        }

                                        if ($imported_previously)
                                        {
                                            $media_ids[] = $imported_previously_id;
                                        }
                                        else
                                        {
                                            $tmp = download_url( $url );
                                            $file_array = array(
                                                'name' => basename( $url ),
                                                'tmp_name' => $tmp
                                            );

                                            // Check for download errors
                                            if ( is_wp_error( $tmp ) )
                                            {
                                                @unlink( $file_array[ 'tmp_name' ] );

                                                //$this->add_error( 'An error occured whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->propertyID );
                                            }
                                            else
                                            {
                                                $id = media_handle_sideload( $file_array, $post_id, $description, array('post_title' => $filename) );

                                                // Check for handle sideload errors.
                                                if ( is_wp_error( $id ) )
                                                {
                                                    @unlink( $file_array['tmp_name'] );

                                                    //$this->add_error( 'ERROR: An error occured whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->propertyID );
                                                }
                                                else
                                                {
                                                    $media_ids[] = $id;

                                                    update_post_meta( $id, '_imported_url', $url);
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            update_post_meta( $post_id, '_photos', $media_ids );

                            // Media - Floorplans
                            $media_ids = array();
                            $previous_media_ids = get_post_meta( $post_id, '_floorplans', TRUE );
                            if (isset($property['media']['floorplans']) && !empty($property['media']['floorplans']))
                            {
                                foreach ($property['media']['floorplans'] as $floorplan_url)
                                {
                                    if (
                                        substr( strtolower($floorplan_url), 0, 2 ) == '//' ||
                                        substr( strtolower($floorplan_url), 0, 4 ) == 'http'
                                    )
                                    {
                                        // This is a URL
                                        $url = $floorplan_url;
                                        $description = '';

                                        $filename = basename( $url );

                                        // Check, based on the URL, whether we have previously imported this media
                                        $imported_previously = false;
                                        $imported_previously_id = '';
                                        if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                                        {
                                            foreach ( $previous_media_ids as $previous_media_id )
                                            {
                                                if ( get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url )
                                                {
                                                    $imported_previously = true;
                                                    $imported_previously_id = $previous_media_id;
                                                    break;
                                                }
                                            }
                                        }

                                        if ($imported_previously)
                                        {
                                            $media_ids[] = $imported_previously_id;
                                        }
                                        else
                                        {
                                            $tmp = download_url( $url );
                                            $file_array = array(
                                                'name' => $filename,
                                                'tmp_name' => $tmp
                                            );

                                            // Check for download errors
                                            if ( is_wp_error( $tmp ) )
                                            {
                                                @unlink( $file_array[ 'tmp_name' ] );

                                                //$this->add_error( 'An error occured whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->propertyID );
                                            }
                                            else
                                            {
                                                $id = media_handle_sideload( $file_array, $post_id, $description, array('post_title' => $filename) );

                                                // Check for handle sideload errors.
                                                if ( is_wp_error( $id ) )
                                                {
                                                    @unlink( $file_array['tmp_name'] );

                                                    //$this->add_error( 'An error occured whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->propertyID );
                                                }
                                                else
                                                {
                                                    $media_ids[] = $id;

                                                    update_post_meta( $id, '_imported_url', $url);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            update_post_meta( $post_id, '_floorplans', $media_ids );

                            // Media - EPCs
                            $media_ids = array();
                            $previous_media_ids = get_post_meta( $post_id, '_epcs', TRUE );
                            if (isset($property['energy_performance']['epc_certificate']) && !empty($property['energy_performance']['epc_certificate']))
                            {
                                if (
                                    substr( strtolower($property['energy_performance']['epc_certificate']), 0, 2 ) == '//' ||
                                    substr( strtolower($property['energy_performance']['epc_certificate']), 0, 4 ) == 'http'
                                )
                                {
                                    // This is a URL
                                    $url = $property['energy_performance']['epc_certificate'];
                                    $description = 'EPC';

                                    $filename = basename( $url );

                                    // Check, based on the URL, whether we have previously imported this media
                                    $imported_previously = false;
                                    $imported_previously_id = '';
                                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                                    {
                                        foreach ( $previous_media_ids as $previous_media_id )
                                        {
                                            if ( get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url )
                                            {
                                                $imported_previously = true;
                                                $imported_previously_id = $previous_media_id;
                                                break;
                                            }
                                        }
                                    }

                                    if ($imported_previously)
                                    {
                                        $media_ids[] = $imported_previously_id;
                                    }
                                    else
                                    {
                                        $tmp = download_url( $url );
                                        $file_array = array(
                                            'name' => $filename,
                                            'tmp_name' => $tmp
                                        );

                                        // Check for download errors
                                        if ( is_wp_error( $tmp ) )
                                        {
                                            @unlink( $file_array[ 'tmp_name' ] );

                                            //$this->add_error( 'An error occured whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->propertyID );
                                        }
                                        else
                                        {
                                            $id = media_handle_sideload( $file_array, $post_id, $description, array('post_title' => $filename) );

                                            // Check for handle sideload errors.
                                            if ( is_wp_error( $id ) )
                                            {
                                                @unlink( $file_array['tmp_name'] );

                                                //$this->add_error( 'An error occured whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->propertyID );
                                            }
                                            else
                                            {
                                                $media_ids[] = $id;

                                                update_post_meta( $id, '_imported_url', $url);
                                            }
                                        }
                                    }
                                }
                            }
                            update_post_meta( $post_id, '_epcs', $media_ids );

                            // Media - Virtual Tours
                            $virtual_tours = array();
                            if (isset($property['media']['videos']) && !empty($property['media']['videos']))
                            {
                                foreach ($property['media']['videos'] as $virtual_tour)
                                {
                                    $virtual_tours[] = $virtual_tour;
                                }
                            }

                            update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                            foreach ($virtual_tours as $i => $virtual_tour)
                            {
                                update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                            }

                            do_action( "propertyhive_sturents_v2_property_imported", $post_id, $property );
                        }
                    }

                    $args = array(
                        'post_type' => 'property',
                        'nopaging' => true,
                        'meta_query' => array(
                            'relation' => 'AND',
                            array(
                                'key'     => $imported_ref_key,
                                'value'   => $import_refs,
                                'compare' => 'NOT IN',
                            ),
                        ),
                    );
                    $property_query = new WP_Query( $args );
                    if ( $property_query->have_posts() )
                    {
                        while ( $property_query->have_posts() )
                        {
                            $property_query->the_post();

                            update_post_meta( $post->ID, '_on_market', '' );

                            do_action( "propertyhive_sturents_v2_property_removed", $post->ID );
                        }
                    }
                    wp_reset_postdata();

                    unset($import_refs);
                }
            }
        }
    }
}