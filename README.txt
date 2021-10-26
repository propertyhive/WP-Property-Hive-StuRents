=== PropertyHive StuRents ===
Contributors: PropertyHive,BIOSTALL
Tags: blm, propertyhive, property hive, property, real estate, software, estate agents, estate agent, sturents
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=N68UHATHAEDLN&lc=GB&item_name=BIOSTALL&no_note=0&cn=Add%20special%20instructions%20to%20the%20seller%3a&no_shipping=1&currency_code=GBP&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted
Requires at least: 3.8
Tested up to: 5.8.1
Stable tag: trunk
Version: 1.0.9
Homepage: http://wp-property-hive.com/addons/sturents-wordpress-import-export/

This add on for Property Hive imports and exports properties from the StuRents website

== Description ==

This add on for Property Hive imports and exports properties from the StuRents website.

== Installation ==

= Manual installation =

The manual installation method involves downloading the Property Hive StuRents Add-on plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Updating should work like a charm; as always though, ensure you backup your site just in case.

== Changelog ==

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