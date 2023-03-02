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

    		$ok_to_run_import = true;

    		// Potential to do any checks here pre-run

    		if ( $ok_to_run_import )
    		{
    			$current_page = 1;
				$total_pages = 1;
				$more_properties = true;

				while ( $more_properties )
				{
	    			$uri = 'https://sturents.com/api/houses';
					$params = array( 'landlord' => $feed['landlord_id'], 'public' => $feed['public_key'], 'version' => '1.2', 'page' => $current_page );
					$query = $uri . '?' . http_build_query($params);

					$json = file_get_contents($query); // TO DO: Also make cURL request as fall back
					
					$data = json_decode($json, true);

					$imported_ref_key = '_imported_ref_' . $i;
					$import_refs = array();

					if ( $data !== FALSE )
					{	
						if ( 
							isset($data['pagination']) && is_array($data['pagination']) && !empty($data['pagination']) &&
							isset($data['pagination']['pages']) && isset($data['pagination']['current'])
						)
						{
							$total_pages = $data['pagination']['pages']; 

							if ( $total_pages == $current_page )
							{
								$more_properties = false;
							}
						}
						else
						{
							$more_properties = false;
						}

						if ( isset($data['branches']) && is_array($data['branches']) && !empty($data['branches']) )
						{
							foreach ($data['branches'] as $branch) 
							{
						    	if ( isset($branch['properties']) && is_array($branch['properties']) && !empty($branch['properties']) )
								{
									foreach ($branch['properties'] as $property) 
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
									            	'value' => $property['reference']
									            )
								            )
								        );
								        $property_query = new WP_Query($args);
								        
								        if ($property_query->have_posts())
								        {
								        	//$this->add_log( 'This property has been imported before. Updating it', (string)$property->propertyID );

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

											    if ( is_wp_error( $post_id ) ) 
												{
													//$this->add_error( 'ERROR: Failed to update post. The error was as follows: ' . $post_id->get_error_message(), (string)$property->propertyID );
												}
												else
												{
													$inserted_updated = true;
												}
								            }
								        }
								        else
								        {
								        	//$this->add_log( 'This property hasn\'t been imported before. Inserting it', (string)$property->propertyID );

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

											if ( is_wp_error( $post_id ) ) 
											{
												//$this->add_error( 'Failed to insert post. The error was as follows: ' . $post_id->get_error_message(), (string)$property->propertyID );
											}
											else
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

											//$this->add_log( 'Successfully added post. The post ID is ' . $post_id, (string)$property->propertyID );

											update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

											update_post_meta( $post_id, $imported_ref_key, $property['reference'] );
											$import_refs[] = $property['reference'];

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
											/*if ( isset($_POST['mapped_office'][(string)$property->branchID]) && $_POST['mapped_office'][(string)$property->branchID] != '' )
											{
												$office_id = $_POST['mapped_office'][(string)$property->branchID];
											}
											elseif ( isset($options['offices']) && is_array($options['offices']) && !empty($options['offices']) )
											{
												foreach ( $options['offices'] as $ph_office_id => $branch_code )
												{
													if ( $branch_code == (string)$property->branchID )
													{
														$office_id = $ph_office_id;
														break;
													}
												}
											}*/
											update_post_meta( $post_id, '_office_id', $office_id );

											// Residential Details
											update_post_meta( $post_id, '_department', 'residential-lettings' );
											update_post_meta( $post_id, '_bedrooms', ( ( isset($property['beds_total']) ) ? $property['beds_total'] : '' ) );
											update_post_meta( $post_id, '_bathrooms', ( ( isset($property['bathrooms']) ) ? $property['bathrooms'] : '' ) );
											update_post_meta( $post_id, '_reception_rooms', '' );

											$contract = ( isset($property['contracts'][0]) && !empty($property['contracts'][0]) ) ? $property['contracts'][0] : array();

											// Residential Lettings Details
											// Clean price
											$price = isset($contract['price']['amount']) ? round(preg_replace("/[^0-9.]/", '', $contract['price']['amount'])) : '';

											update_post_meta( $post_id, '_rent', $price );

											$rent_frequency = 'pcm';
											$price_actual = $price;
											if (isset($contract['price']['amount_per']))
											{
												if ( $contract['price']['amount_per'] == 'person' )
												{
													switch ($contract['price']['time_period'])
													{
														case "week":
														{ 
															$rent_frequency = 'pppw';
															break;
														}
														case "month":
														{
															$rent_frequency = 'pppw';
															$price = ($price * 12) / 52;
															break;
														}
														case "quarter":
														{ 
															$rent_frequency = 'pppw';
															$price = ($price * 4) / 52;
															break;
														}
														case "year":
														{ 
															$rent_frequency = 'pppw';
															$price = $price / 52;
															break;
														}
													}
													$price_actual = ($price * 52) / 12;
												}
												else
												{
													switch ($contract['price']['time_period'])
													{
														case "week":
														{ 
															$rent_frequency = 'pw';
															$price_actual = ($price * 52) / 12;
															break;
														}
														case "month":
														{
															$rent_frequency = 'pcm';
															$price_actual = $price;
															break;
														}
														case "quarter":
														{ 
															$rent_frequency = 'pq';
															$price_actual = ($price * 4) / 12;
															break;
														}
														case "year":
														{ 
															$rent_frequency = 'pa';
															$price_actual = $price / 12;
															break;
														}
													}
												}
											}
											update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
											update_post_meta( $post_id, '_price_actual', $price_actual );
											
											update_post_meta( $post_id, '_poa', '' );

											update_post_meta( $post_id, '_deposit', isset($contract['price']['amount']) ? $contract['deposit']['amount'] : '' );

											$available = ( isset($property['available']) && !empty($property['available']) ) ? $property['available'][0] : array();
											$available_date = '';
											if ( isset($available['start_date']) && $available['start_date'] != '' )
											{
												$available_date = $available['start_date'];
											}
						            		update_post_meta( $post_id, '_available_date', $available_date );

						            		// Marketing
											update_post_meta( $post_id, '_on_market', 'yes' );
											update_post_meta( $post_id, '_featured', '' );

											// Availability

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

									        // Rooms

									        // Media - Images
											if ( get_option('propertyhive_images_stored_as', '') == 'urls' )
							    			{
							    				$media_urls = array();
							    				if (isset($property['media']['photos']) && !empty($property['media']['photos']))
								                {
								                    foreach ($property['media']['photos'] as $image)
								                    {
								                    	if ( 
															$image['type'] == 'url'
														)
														{
															// This is a URL
															$url = $image['photo'];

															$media_urls[] = array('url' => $url);
														}
													}
												}
												update_post_meta( $post_id, '_photo_urls', $media_urls );
											}
							    			else
							    			{
												$media_ids = array();
												$previous_media_ids = get_post_meta( $post_id, '_photos', TRUE );

												if (isset($property['media']['photos']) && !empty($property['media']['photos']))
								                {
								                    foreach ($property['media']['photos'] as $image)
								                    {
								                    	if ( 
															$image['type'] == 'url'
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
								            }

											//$this->add_log( 'Successfully imported ' . count($media_ids) . ' photos', (string)$property->propertyID );

											// Media - Floorplans
											if ( get_option('propertyhive_floorplans_stored_as', '') == 'urls' )
							    			{
							    				$media_urls = array();
							    				if (isset($property['media']['floorplans']) && !empty($property['media']['floorplans']))
								                {
								                    foreach ($property['media']['floorplans'] as $floorplan)
								                    {
														if ( 
															substr( strtolower($floorplan), 0, 2 ) == '//' || 
															substr( strtolower($floorplan), 0, 4 ) == 'http'
														)
														{
															// This is a URL
															$url = $floorplan;

															$media_urls[] = array('url' => $url);
														}
													}
												}
												update_post_meta( $post_id, '_floorplan_urls', $media_urls );
											}
							    			else
							    			{
												$media_ids = array();
												$previous_media_ids = get_post_meta( $post_id, '_floorplans', TRUE );
												if (isset($property['media']['floorplans']) && !empty($property['media']['floorplans']))
								                {
								                    foreach ($property['media']['floorplans'] as $floorplan)
								                    {
														if ( 
															substr( strtolower($floorplan), 0, 2 ) == '//' || 
															substr( strtolower($floorplan), 0, 4 ) == 'http'
														)
														{
															// This is a URL
															$url = $floorplan;
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
											}

											// Media - EPCs
											if ( get_option('propertyhive_epcs_stored_as', '') == 'urls' )
							    			{
							    				$media_urls = array();

							    				if ( 
													substr( strtolower($property['energy_performance']['epc_certificate']), 0, 2 ) == '//' || 
													substr( strtolower($property['energy_performance']['epc_certificate']), 0, 4 ) == 'http'
												)
												{
													// This is a URL
													$url = $property['energy_performance']['epc_certificate'];

													$media_urls[] = array('url' => $url);
												}

												update_post_meta( $post_id, '_epc_urls', $media_urls );
											}
							    			else
							    			{
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
											}

											// Media - Virtual Tours
											//$this->add_log( 'Importing virtual tours', (string)$property->propertyID );

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

											//$this->add_log( 'Successfully imported ' . count($virtual_tours) . ' virtual tours', (string)$property->propertyID );

											do_action( "propertyhive_sturents_property_imported", $post_id, $property );
										}
									}
								}
								else
								{
									// No properties for this branch
									$more_properties = false;
								}
							}
						}
						else
						{
							// No branches
							$more_properties = false;
						}
					}
					else
					{
						// Failed to decode JSON response
						$more_properties = false;
					}

					++$current_page;

				} // end while

				if ( !empty($import_refs) )
				{
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
						'orderby' => 'rand'
					);
					$property_query = new WP_Query( $args );
					if ( $property_query->have_posts() )
					{
						while ( $property_query->have_posts() )
						{
							$property_query->the_post();

							update_post_meta( $post->ID, '_on_market', '' );

							//$this->add_log( 'Property marked as not on market', get_post_meta($post->ID, $imported_ref_key, TRUE) );

							do_action( "propertyhive_sturents_property_removed", $post->ID );
						}
					}
					wp_reset_postdata();

					unset($import_refs);
				}
				
    		}
    	}
    }
}