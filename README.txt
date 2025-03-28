=== PropertyHive StuRents ===
Contributors: PropertyHive,BIOSTALL
Tags: sturents, propertyhive, property hive, property, real estate, software, estate agents, estate agent
Requires at least: 3.8
Tested up to: 6.6.1
Stable tag: trunk
Version: 1.0.18
Homepage: https://wp-property-hive.com/addons/sturents-wordpress-import-export/

This add on for Property Hive imports and exports properties from the StuRents website

== Description ==

This add on for Property Hive imports and exports properties from the StuRents website.

== Installation ==

= Manual installation =

The manual installation method involves downloading the Property Hive StuRents Add-on plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Updating should work like a charm; as always though, ensure you backup your site just in case.

== Changelog ==

= 1.0.18 =
* New filter to continue removing properties, even if no properties
* Remove property ID from log that's not property specific
* Declared support for WordPress 6.6.1

= 1.0.17 =
* Correct issues with vars getting overwritten so properties aren't removed
* Declared support for WordPress 6.5.5

= 1.0.16 =
* Don't import incomplete properties
* Added logs to see what's happening during imports
* Declared support for WordPress 6.5.4

= 1.0.15 =
* Cater for multiple contracts being provided and use lowest price
* Ensure that the cron is scheduled and re-set it up if not found for whatever reason
* Declared support for WordPress 6.4.2

= 1.0.14 =
* Automatically set 'Location' if matching 'city' found when importing properties. Locations must be created manually under 'Property Hive > Settings > Custom Fields > Locations'

= 1.0.13 =
* Corrected hook name fired when cron runs

= 1.0.12 =
* Updated to use v1.2 of the StuRents API
* Fix undefined variable so 'Show Import Data' works
* Declared support for WordPress 6.2.2

= 1.0.11 =
* Added 'Show Import Data' link to property record to see exactly what was sent by StuRents
* Corrected issue with floorplans not importing
* Declared support for WordPress 6.1

= 1.0.10 =
* Cater for custom departments based on residential lettings and include these
* Added option to send data if different from last time a successful request was made
* Declared support for WordPress 5.8.2

= 1.0.9 =
* Allow filtering of properties by those active on StuRents
* Added ability to batch activate/deactivate properties on StuRents from main properties list
* Add timeout to cURL requests
* Declared support for WordPress 5.8.1

= 1.0.8 =
* Added number of active properties to feed list for exports
* Properties on market but with StuRents box unticked will now be sent as incomplete to remove them
* Output error should property fail to send on save

= 1.0.7 =
* Changed deposit amount_per value to be based on price amount_per instead of defaulting to per person
* Added settings link to plugins page
* Declared support for WordPress 5.4.1

= 1.0.6 =
* Added ability to select whether individual properties can be chosen to be exported to StuRents. If enabled, properties can be selected under the 'Marketing' tab
* Declared support for WordPress 4.9.8

= 1.0.5 =
* Added new setting to exports to specify if prices should be sent as per person or per property as StuRents divide the price by bedrooms if sent as per property
* Declared support for WordPress 4.7.3

= 1.0.4 =
* Added new 'Push All Properties' button to send all properties at once
* Set lower priority on save_post action to ensure it's called last. Previously bulk editing properties wouldn't take effect in push to StuRents
* Declared support for WordPress 4.7.2

= 1.0.3 =
* Declared support for WordPress 4.7.1

= 1.0.2 =
* Correct issue with API returning false because at least one eligibility wasn't set when sending properties
* Declare compatibility with WordPress 4.6.1

= 1.0.1 =
* Correction to wrong field being sent for address street
* Correction to fix EPCs not being sent

= 1.0.0 =
* First working release of the add on