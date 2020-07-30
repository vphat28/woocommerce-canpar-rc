<?php
/*
Plugin Name: Canpar Rate Calculator
Description: Rate shipments via the Canpar rate calculator
Version:	 1.1.4
Author:	  Canpar Courier
Author URI:  http://www.canpar.com
License:	 GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

TODO: Fail over rates
TODO: Ship to available countries (use the service selection for the time being)
TODO: Max box dimensions per box (like max weight)
TODO: Allow DG shipping. Custom field? Shipping class? Attribute?

Note: When modifying this plugin, please modify the $this->version string (defined in __construct)
		with an indicator that it was modified. For example "1.0.0 (Custom)".
		This will assist any future technical support providers, by letting them know if this is vanilla.
*/

//Block direct access to the plugin
defined('ABSPATH') or die();



/**
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	
	/**
	 *
	 */
	function canpar_rate_calculator_init() {
		if ( ! class_exists( 'WC_Canpar_Rate_Calculator' ) ) {
			class WC_Canpar_Rate_Calculator extends WC_Shipping_Method {
				/**
				* Constructor for Canpar Rate Calculator class
				*
				* @access public
				* @return void
				*/
				public function __construct( $instance_id = 0 ) {
					$this->id				 = 'canpar_rate_calculator'; // Id for your shipping method. Should be unique.
                    $this->instance_id = absint( $instance_id );
					$this->method_title	   = __( 'Canpar Rate Calculator' );  // Title shown in admin
					$this->method_description = __( 'Calculate shipping rates using the Canpar rate calculator' ); // Description shown in admin
					$this->title			  = "Canpar";
					$this->version		= "1.1.4";
                    $this->supports = array(
                                'shipping-zones',
                                'instance-settings',
                                'instance-settings-modal',
                            );
					$this->init();
					add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
				}

				/**
				* Init the Canpar Rate Calculator settings
				*
				* @access public
				* @return void
				*/
				function init() {
					// Load the settings API
					$this->init_settings(); // This is part of the settings API. Loads settings you previously init.


					// Define the defaults - note, this is done here instead of init_form_fields, in case this plugin gets updated with new settings defaults, and the user does not access the administrative area.
					if (count($this->settings) > 0) {
						$this->settings['origin_postal_code'] = preg_replace("/[^A-Za-z0-9]/", '', $this->settings['origin_postal_code']);

						if ( (float) $this->settings['default_weight'] <= 0)
						{$this->settings['default_weight'] = 1;}

						if ( (float) $this->settings['maximum_weight'] <= 0)
						{
							if (substr(get_option('woocommerce_weight_unit'), 0, 1) == "l") {
								$this->settings['maximum_weight'] = 50;
							}
							else {
								$this->settings['maximum_weight'] = 20;
							}
						}

						if ((int) $this->settings['lead_time'] < 0)
						{$this->settings['lead_time'] = 0;}

						$this->settings['handling_fee_amount'] = (float) $this->settings['handling_fee_amount'];

						if ( ! in_array($this->settings['debug'], array('yes', 'no')))
						{$this->settings['debug'] = 'yes';}
					}

					if ( ! isset($this->settings['service_prefix']))
					{$this->settings['service_prefix'] = "Canpar";}


					// Connect to the SOAP client
					if (isset( $this->settings['rating_url'] )) {
						$this->canpar = $this->soap_connect( $this->settings['rating_url'] );
					}
					else {
						$this->canpar = new StdClass(); //Create a dummy object
					}

					// Set the shipping method names
					$this->services = array(
						'1' => trim($this->settings['service_prefix'] . " Ground"),
						'2' => trim($this->settings['service_prefix'] . " USA Ground"),
						'3' => trim($this->settings['service_prefix'] . " Select Letter"),
						'4' => trim($this->settings['service_prefix'] . " Select Pak"),
						'5' => trim($this->settings['service_prefix'] . " Select Parcel"),
						'C' => trim($this->settings['service_prefix'] . " Express Letter"),
						'D' => trim($this->settings['service_prefix'] . " Express Pak"),
						'E' => trim($this->settings['service_prefix'] . " Express Parcel"),
						'F' => trim($this->settings['service_prefix'] . " USA Select Letter"),
						'G' => trim($this->settings['service_prefix'] . " USA Select Pak"),
						'H' => trim($this->settings['service_prefix'] . " USA Select Parcel"),
						'I' => trim($this->settings['service_prefix'] . " International")
					);

					// Output the forms for the settings
					$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings

					// Save settings in admin
					add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
				}

				/**
				* Initialise Canpar Rate Calculator Settings Form Fields
				*
				* @access public
				* @return void
				*/
				function init_form_fields() {
					$this->instance_form_fields = array(
						'enabled' => array(
							'title' => 'Enabled',
							'type' => 'select',
							'description' => 'Enable the Canpar shipping method.',
							'default' => 'no',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
						'user_id' => array(
							'title' => 'API User ID',
							'type' => 'text',
							'description' => 'The user ID (email address) for the web services communication.<br />This is the same login you use for logging into www.canpar.com or the Canship software.',
							'default' => ''
						),
						'password' => array(
							'title' => 'API Password',
							'type' => 'text',
							'description' => 'The password for the web services communication.',
							'default' => ''
						),
						'shipper_num' => array(
							'title' => 'Canpar Account Number',
							'type' => 'text',
							'default' => ''
						),
						'origin_postal_code' => array(
							'title' => 'Origin Postal Code',
							'type' => 'text',
							'description' => 'Enter the postal code that the shipments will be sent from.',
							'default' => ''
						),
						'origin_province' => array(
							'title' => 'Origin Province',
							'type' => 'select',
							'description' => 'Select the province that the shipments will be sent from.',
							'default' => '',
							'options' => array(
								"AB" => "Alberta",
								"BC" => "British Columbia",
								"MB" => "Manitoba",
								"NB" => "New Brunswick",
								"NL" => "Newfoundland and Labrador",
								"NT" => "Northwest Territories",
								"NS" => "Nova Scotia",
								"NU" => "Nunavut",
								"ON" => "Ontario",
								"PE" => "Prince Edward Island",
								"QC" => "Quebec",
								"SK" => "Saskatchewan",
								"YT" => "Yukon"
							)
						),
						'services' => array(
							'title' => 'Services',
							'type' => 'multiselect',
							'description' => 'Select the services that you would like to allow. Hold ctrl to select multiple services.<br />Warning: ensure that if you disable US or International service, that you have another shipping method available for them. Otherwise your customers will not get a rate calculation if they are in the US or "international".',
							'default' => array('1', '2', '5', 'E', 'H', 'I'),
							'css' => 'height: ' . (count($this->services) + 5) . 'em;',
							'options' => $this->services
						),
						'maximum_weight' => array(
							'title' => 'Maximum Weight/Box ('.get_option('woocommerce_weight_unit').')',
							'type' => 'text',
							'description' => 'The maximum weight your shipping boxes can hold. This is so the rate calculator can determine how many packages will be shipped.<br />For example, if this value is set to "20", and your customer\'s order weighs "50", then it will be calculated as a 3 piece shipment.<br />Maximum is 150lbs / 68kgs.<br />Note that this also includes dimensional weight.',
							'default' => '50'
						),
						'default_weight' => array(
							'title' => 'Default Weight ('.get_option('woocommerce_weight_unit').')',
							'type' => 'text',
							'description' => 'If a product does not have the weight value defined, what weight would you like to default to?',
							'default' => '1'
						),
						'handling_fee_amount' => array(
							'title' => 'Handling Fee Amount',
							'type' => 'text',
							'description' => 'The amount of handling to apply to each order.',
							'default' => '0'
						),
						'handling_fee_type' => array(
							'title' => 'Handling Fee Type',
							'type' => 'select',
							'description' => 'Select the method to apply the handling fee to the shipping charge. Set the handling fee amount (below) to 0 if you do not want handling applied.',
							'default' => '%',
							'options' => array(
								'%' => '%', '$' => '$'
							)
						),
						'service_prefix' => array(
							'title' => 'Service Name Prefix',
							'type' => 'text',
							'description' => 'The prefix for the service names. For example, if you enter "Canpar", then the shipping services your customer will see will be "Canpar Ground", "Canpar Select", etc. This can be set to blank as well.',
							'default' => 'Canpar'
						),
						'display_eta' => array(
							'title' => 'Display Expected Delivery Date',
							'type' => 'select',
							'description' => 'Enable this to display the expected delivery date for each shipping method.<br />Note: this only displays for domestic addresses.',
							'default' => 'yes',
							'options' => array(
								'yes' => 'Yes', 'no' => 'No'
							)
						),
						'lead_time' => array(
							'title' => 'Lead Time for Order Processing',
							'type' => 'text',
							'description' => 'Enter the number of days to push the expected delivery date back, for if you do not ship out your orders on the day they are received.',
							'default' => '1'
						),
						/*
						'enable_dg' => array(
							'title' => 'Enable DG',
							'type' => 'select',
							'description' => 'Enable this to add a Dangerous Goods charge on any product with an Attribute of "DG" with a value of "yes". Contact Canpar for more information if required.',
							'default' => 'yes',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
						*/
						'apply_discounts' => array(
							'title' => 'Apply Invoice Discounts',
							'type' => 'select',
							'description' => 'Enable this to return rates with your Canpar account invoice discounts applied (if applicable).<br />Note that "source" discounts will still be displayed. Speak with your Canpar sales representative for clarification on the types of discounts.<br />It is recommended that this remain disabled, as "source" discounts are generally meant to be displayed to your customer, while "invoice" discounts are not.',
							'default' => 'no',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
						'enable_dv' => array(
							'title' => 'Enable DV',
							'type' => 'select',
							'description' => 'Enable this to include the Declared Value of the products in the rate calculation. The DV value will be set to the total value of the products in the shopping cart.<br />DV represents the liability value if a package is lost or damaged.',
							'default' => 'yes',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
						'rating_url' => array(
							'title' => 'Rating URL',
							'type' => 'text',
							'description' => 'The WS URL for communication via the rating WS. This does not need to be modified.<br />Default: https://canship.canpar.com/canshipws/services/CanparRatingService?wsdl',
							'default' => 'https://canship.canpar.com/canshipws/services/CanparRatingService?wsdl'
						),
						'debug' => array(
							'title' => 'Debug Mode',
							'type' => 'select',
							'description' => 'Enable this to log all rating communication with Canpar, as well as display errors on the screen <i>when you are logged in as the wordpress administrator</i>.<br />Warning: this will create log files for every shipment calculation, which may accumulate large file sizes over time, and should thus be left disabled.',
							'default' => 'no',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
						'output_logs' => array(
							'title' => 'Output Logs',
							'type' => 'select',
							'description' => 'Enable this to output log data at the top of the View Cart page. This will only be displayed to users who are logged in as admins, and your customers will not be able to view this.<br />Note 1: This needs to be enabled alongside debug mode.<br />Note 2: Rating data gets cached, and only updates when the plugin settings change, or when the shopping cart changes. If debug data is not being displayed, just click "save" at the bottom of this settings page to force a rate refresh.',
							'default' => 'no',
							'options' => array(
								'yes'=>"Yes", 'no' => 'No'
							)
						),
					);
				}


				/**
				* Initialise Canpar Rate Calculator Settings Form Fields
				*
				* @access public
				* @return void
				*/
				function admin_options() {
					?>
					<h2><?php _e('Canpar Rate Calculator','woocommerce'); ?></h2>
					<table class="form-table">
					<?php $this->generate_settings_html(); ?>
					</table> <?php
				}

				/**
				* calculate_shipping function.
				*
				* @access public
				* @param mixed $package
				* @return void
				*/
				public function calculate_shipping( $package = array() ) {
				    if ( count($this->instance_settings) > 0 ) {
				        $this->settings = $this->instance_settings;
				    }
					// Only calculate the shipping if the "enabled" option is true
					if ($this->settings['enabled'] == "no") {
						return;
					}

					// Prep logging
					$load_time_start = microtime(true);
					$soap_log_name = date('His') . "-RatingSOAP";

					//Output the log if needed.
					$output_log = false;
					if ($this->settings['debug'] == "yes" && $this->settings['output_logs'] == "yes" && current_user_can('edit_plugins') === true) {
						$output_log = true;
						?>
						<div class="woocommerce-message">
							<b>Log file output:</b><br />
							<a href="#" onclick="document.getElementById('canpar_output_logs_display').style.display='inline'; return false;">
								&bull; Click here to display the log data and error messages
							</a>
							<br />
							<a href="#" onclick="var e = document.getElementById('canpar_output_logs_textbox'); e.innerHTML = document.getElementById('canpar_output_logs_display').textContent.trim(); e.style.display='inline'; e.select(); return false;">
								&bull; Click here to select the log file contents (for copying)
							</a>
							<br /><br />
							<span style="font-size: 0.8em;">
							You are seeing this because the <b>Output Logs</b> setting is enabled in your Canpar Rate Calculator for WooCommerce settings.
						</span>
						</div>
						<textarea id="canpar_output_logs_textbox" style="width: 100%; display: none;" onfocus="this.select();"></textarea>
						<div id="canpar_output_logs_display" style="display: none;">
						<?php
					}

					// Output the plugin settings
					$this->canpar_log($soap_log_name, "Plugin version:\n" . print_r($this->version, 1), "append");
					$this->canpar_log($soap_log_name, "Plugin settings:\n" . print_r($this->settings, 1), "append");
					$this->canpar_log($soap_log_name, "Woo Weight Unit:\n" . get_option('woocommerce_weight_unit'), "append");
					$this->canpar_log($soap_log_name, "Woo Dimension Unit:\n" . get_option('woocommerce_dimension_unit'), "append");


					// Check to see if the WS URL exists. If it does not, then rating cannot take place.
					if (method_exists($this->canpar, "__getFunctions") === false) {
						if ($output_log == true)  // Close the log catching div (do this whenever ending this method)
						{print '</div>';}

						return;
					}

					// Make sure the Measurement units are in kg/lb and cm/in
					if ( ! in_array(strtolower(get_option('woocommerce_weight_unit')), array("kg", "lb", "lbs", "kgs")) || ! in_array(strtolower(get_option('woocommerce_dimension_unit')), array("cm", "in", "cms", "ins")) ){
						$this->canpar_log($soap_log_name, "Error: Weight Unit and/or Dimension unit are not kg/lb and cm/in. They are:\n" . get_option('woocommerce_weight_unit') . " / " . get_option('woocommerce_dimension_unit'), "append");
						$this->output_error("Error: Weight Unit and/or Dimension unit are not kg/lb and cm/in. Ensure they are set in the WooCommerce Settings, under the Products tab.", __LINE__);

						if ($output_log == true)  // Close the log catching div (do this whenever ending this method)
						{print '</div>';}

						return;
					}


					// Begin the counter to how many rate methods are being displayed.
					$rates_displayed = 0;

					// Filter the postal code
					$package['destination']['postcode'] = preg_replace("/[^A-Za-z0-9]/", '', $package['destination']['postcode']);
					//Define the shipping date
					$shipping_date = $this->get_shipping_date();

					// Get the available services
					$request = array(
						'delivery_country' => $package['destination']['country'],
						'delivery_postal_code' => $package['destination']['postcode'],
						'password' => $this->settings['password'],
						'pickup_postal_code' => $this->settings['origin_postal_code'],
						'shipper_num' => $this->settings['shipper_num'],
						'shipping_date' => $shipping_date,
						'user_id' => $this->settings['user_id']
					);

					// Execute the request
					$available_services = $this->canpar->getAvailableServices(array('request'=>$request));

					// Log the request
					$this->canpar_log($soap_log_name, "getAvailableServices Request:\n" . $this->canpar->__getLastRequest(), "append");
					$this->canpar_log($soap_log_name, "getAvailableServices Response:\n" . $this->canpar->__getLastResponse(), "append");

					//Check for errors
					$error = $this->get_error($available_services);
					if ($error != "") {
						$this->output_error($error, __LINE__);
						return;
					}

					$available_services = $available_services->return->getAvailableServicesResult;

					if (is_null($available_services))
					{$available_services = array();} // Just make it a blank array to avoid errors about "invalid argument" for the following foreach()

					// Get the rate for each service
					foreach ($available_services AS $service) {
						// Determine if this service is allowed
						if ( ! in_array($service->type, $this->settings['services']) )
						{continue;}

						// Get the rate
						$pieces = $this->generate_pieces($package, $service->type);
						$request = $this->build_shipment_request($package, $pieces['pieces'], $service->type, $shipping_date, $pieces['units']);
						$rate = $this->canpar->rateShipment(array('request'=>$request));

						// Log the request
						$this->canpar_log($soap_log_name, "rateShipment Request - Service Type: {$this->services[$service->type]}\n" . $this->canpar->__getLastRequest(), "append");
						$this->canpar_log($soap_log_name, "rateShipment Response - Service Type: {$this->services[$service->type]}\n" . $this->canpar->__getLastResponse(), "append");

						// Check for errors, and stop processing if there are any found
						$error = $this->get_error($rate);

						if ($error != "") {
							$this->output_error("Service: {$this->services[$service->type]} - $error", __LINE__);
							continue;
						}

						$rate = $rate->return->processShipmentResult->shipment;

						// Convert the rate object to an array for easy cycling
						$rate = json_decode(json_encode($rate), true);

						// Determine the label name (service type name, and the ETA)
						$label = $this->services[$service->type];
						if ($this->settings['display_eta'] == "yes" && $service->estimated_delivery_date != "") {
							$label .= " - " . date('M d', strtotime($service->estimated_delivery_date));
						}

						// Calculate the totals
						$total_charge = (float)$rate['total_with_handling'];

						// Add handling to the charge (not the taxes)
						if ($this->settings['handling_fee_amount'] != 0)
						{
							$log_total = $total_charge; //This is only used to update the log file with the original charge before handling

							if ($this->settings['handling_fee_type'] == "$")
							{$total_charge += (float) $this->settings['handling_fee_amount'];}

							if ($this->settings['handling_fee_type'] == "%") {
								//Convert to a percent
								$handling = (float) $this->settings['handling_fee_amount'];
								$handling /= 100;
								$handling += 1;

								$total_charge *= $handling;
							}

							$this->canpar_log($soap_log_name, "Handling charge of {$this->settings['handling_fee_amount']} ({$this->settings['handling_fee_type']}) applied. From \${$log_total} to \${$total_charge}", "append");
						}

						// Add the rate to the available service methods
						$taxes = array();
						foreach (array('tax_charge_1', 'tax_charge_2') as $index)
						{
							$tax = (float) $rate[$index];
							if ($tax > 0)
							{$taxes[] = $tax;}
						}

						$rate_output = array(
							'id' => $rate['service_type'],
							'label' => $label,
							'cost' => $total_charge,
						);

						$this->canpar_log($soap_log_name, "Rate output for {$this->services[$service->type]}:\n" . print_r($rate_output, 1), "append");

						// Register the rate
						$this->add_rate( $rate_output );
						$rates_displayed ++;
					}

					//Output the time it took to load the page
					$load_time = microtime(true) - $load_time_start;
					$this->canpar_log($soap_log_name, "Rates generated in:\n" . round($load_time, 4) . " seconds", "append");

					//Determine if any rates displayed
					if ($rates_displayed == 0) {
						$this->canpar_log($soap_log_name, "Note: No rating methods were displayed", "append");
						$this->output_error("Warning: No rating methods were returned for Canpar shipping. If you are expecting to see Canpar shipping methods for the shipping destination, make sure there are no other errors, and that the appropriate services are selected in the <b>services</b> section of the Canpar Rate Calculator settings.", __LINE__);
					}

					//Close the log file output div
					if ($output_log == true) {
						print '</div>';
					}
				}

				function getConfiguredWeightUnit()
				{
				    return strtoupper(substr(get_option('woocommerce_weight_unit'), 0, 1)) ;
				}

				/**
				 * Write a log file with the soap requests
				 *
				 * @param mixed $package Package object passed from woo commerce
				 * @param array $pieces Array of the pieces
				 * @param string $service Service ID
				 * @param string $shipping_date Date for the shipment to go out (affected by lead time setting)
				 * @param array $units Pass an array with "dim" and "wgt" for the dim and wgt units (in/cm and lb/kg)
				 *
				 * @access public
				 * @return mixed The array to feed into the SOAP request for rating a shipment
				 */
				function build_shipment_request ( $package, $pieces, $service, $shipping_date, $units = array('dim'=>'', 'wgt'=>'') ) {
					// Determine if the discount should be applied
					if ($this->settings['apply_discounts'] == "yes") {
						$apply_discounts = "1";
					}
					else {
						$apply_discounts = "0";
					}

					// Build the pickup address
					$pickup_address = array(
						'address_line_1' => 'WOO FILLER',
						'city' => 'WOO FILLER',
						'country' => "CA",
						'name' => 'WOO FILLER',
						'postal_code' => $this->settings['origin_postal_code'],
						'province' => $this->settings['origin_province']
					);

					// Build the delivery address
					$delivery_address = array(
						'address_line_1' => 'WOO FILLER',
						'city' => 'WOO FILLER',
						'country' => $package['destination']['country'],
						'name' => 'WOO FILLER',
						'postal_code' => $package['destination']['postcode'],
						'province' => $package['destination']['state']
					);

					// Build the packages
					// Note: this is now passed as $pieces parameter

					// Build the shipment
					$shipment = array(
						'delivery_address' => $delivery_address,
						'dg' => false,
						'dimention_unit' => (in_array($units['dim'], array('C', 'I'))) ? $units['wgt'] : get_option('woocommerce_dimension_unit'),
						'handling' => $this->settings['handling_fee_amount'],
						'handling_type' => $this->settings['handling_fee_type'],
						'packages' => $pieces,
						'pickup_address' => $pickup_address,
						'reported_weight_unit' => 'L',
						'service_type' => $service,
						'shipper_num' => $this->settings['shipper_num'],
						'shipping_date' => $shipping_date,
						'user_id' => $this->settings['user_id']
					);

					// Build the request
					$request = array(
						'apply_association_discount' => $apply_discounts,
						'apply_individual_discount' => $apply_discounts,
						'apply_invoice_discount' => $apply_discounts,
						'password' => $this->settings['password'],
						'user_id' => $this->settings['user_id'],
						'shipment' => $shipment
					);
					return $request;
				}

				/**
				 * Calculate the number of pieces in the shipment, based on the weight and dimensions
				 *
				 * @param mixed $package
				 * @param string $service Service code for the shipment
				 *
				 * @access public
				 * @return array
				 */
				function generate_pieces ( $package, $service ) {
					$total_weight = 0;
					$total_xc_weight = 0;
					$units = array('dim'=>'', 'wgt'=>'');
					$dv = round(WC()->cart->cart_contents_total, 2); // Declared Value

					// Prep to log
					if ( ! isset($this->gen_pcs_log) )
					{$this->gen_pcs_log = date('His') . "-generate_pieces";}
					$gen_pcs_log = $this->gen_pcs_log;
					$this->canpar_log($gen_pcs_log, "Generate Pieces for service: {$this->services[$service]} - DV: " . $dv, "append");

					/*
					 * Get the weight of each product.
					 * Use the web services to calculate the dimensional weight,
					 * as well as the appropriate billed weight and XC
					 */

					$weights = array();
					$i = 1;
					foreach ($package['contents'] AS $product_data) {
						$product = $product_data['data'];

						// Log
						$this->canpar_log($gen_pcs_log, "Calculating product {$i}: {$product->post->post_title} (Product ID: {$product_data['product_id']})", "append");

						// Get the dimensions
						$length = (float) $product->length;
						$width = (float) $product->width;
						$height = (float) $product->height;
						$weight = (float) $product->weight;
						$quantity = (int) $product_data['quantity'];

						// Make sure the weight is defined
						if ($weight == 0)
						{$weight = (float) $this->settings['default_weight'];}

						/* Determine the dim weight and unit conversion via a web service call */

						// Check if a value for these same dims and weight has already been determined for this service
						if (isset($weights["{$length}-{$width}-{$height}"])){
							$billed_weight = $weights["{$length}-{$width}-{$height}"];

							$this->canpar_log($gen_pcs_log, "The weight was determined from the cached value. No WS call was made for this product.", "append");
						}
						// Check if the units are set and the same as the WooCommerce settings, as well as if dims are 0 (if this is the case, then no WS call is required)
						elseif ( strtoupper(substr(get_option('woocommerce_weight_unit'), 0, 1)) == $units['wgt'] && ($length == 0 || $width == 0 || $height == 0) ) {
							$billed_weight = $weight;

							$this->canpar_log($gen_pcs_log, "Dimensions were not set, and the calculated weight unit was already determined, so no WS call was required", "append");
						}
						else { //Make the WS call to calculate the dim weight, determine the weight unit, and convert the weight if needed
								$billed_weight = $weight;

						}

						// Add the weight of the product to the total weight
						$total_weight += $quantity * $billed_weight;


						// Log the product
						$log_output = array();
						$log_output[] = "Product {$i}: {$product->post->post_title} (Product ID: {$product_data['product_id']})";
						$log_output[] = ((float) $product->weight == 0) ? "Weight: {$weight} (defaulted from 0)" : "Weight: {$weight}";
						$log_output[] = "Dims: {$length} x {$width} x {$height}";
						$log_output[] = "Price (subtotal): " . $product_data['line_subtotal'];
						$log_output[] = "Calculated weight: {$billed_weight} {$units['wgt']}";
						$log_output[] = "Quantity: {$quantity}";
						$this->canpar_log($gen_pcs_log, implode("\n", $log_output), "append");
						$i ++;
					}

					// If the above population of $total_weight fails (eg WS fails), set it to the cart weight as a fall back
					if ($total_weight == 0) {
						$total_weight = WC()->cart->cart_contents_weight;
					}

					// Determine how many pieces are in the shipment
					// First, does the weight need to be converted to conform with the Canpar rate calculator settings?
					$units['wgt'] = strtoupper($units['wgt']); // Convert to upper case
					if ($this->getConfiguredWeightUnit() !== 'L') {
						$converted_weight = $total_weight;
						
						// Convert to lbs
						if ($this->getConfiguredWeightUnit() == "K") {
							$converted_weight *= 2.2;
							$total_xc_weight *= 2.2;
						}
						
						//Determine the number of pieces
						$total_pieces = ceil($converted_weight / $this->settings['maximum_weight']);
						
						// Get the ratio of how much "weight" is XC
						$xc_ratio = $total_xc_weight / $converted_weight; 
						
						//Log
						$this->canpar_log($gen_pcs_log, "Total weight of {$total_weight} was converted to {$converted_weight} for determining the number of pieces", "append");
						$total_weight = $converted_weight;
					}
					else { // If it does not need converted
						// Get the number of pieces
						$total_pieces = ceil($total_weight / $this->settings['maximum_weight']);
						
						// Get the ratio of how much "weight" is XC
						$xc_ratio = $total_xc_weight / $total_weight; 
					}
					
					// Determine the number of XC pieces (based on weight ratio)
					if ($total_xc_weight > 0) {
						$xc_pieces = round($total_pieces * $xc_ratio); // use that weight ratio to determine how many pieces are XC (rounded)
						
						// Make sure that at least one piece is XC (it may be 0, due to rounding)
						if ($xc_pieces == 0) {
							$xc_pieces = 1;
						}
					}
					
					
					$this->canpar_log($gen_pcs_log, "DV: {$dv}\nMax weight: {$this->settings['maximum_weight']}\nTotal weight: {$total_weight}\nPieces: {$total_pieces}\nXC pieces: {$xc_pieces}", "append");
					
					// Generate the pieces
					$pieces = array();
					for ($i=0; $i<$total_pieces; $i++) {
						$pieces[$i] = array(
							'height' => 0,
							'width' => 0,
							'length' => 0,
							'declared_value' => ($this->settings['enable_dv'] == "yes") ? round(($dv / $total_pieces), 2) : 0,
							'reported_weight' => round(($total_weight / $total_pieces), 2)
						);
						
						// Add the XC piece if required
						if ($i < $xc_pieces) {
							$pieces[$i]['xc'] = true;
						}
					}
					
					//Output the values
					$output = array("pieces" => $pieces, "units"=>$units);
					$this->canpar_log($gen_pcs_log, "Final piece values for service {$this->services[$service]}:\n" . print_r($output, 1), "append");
					return $output;
				}

				/**
				 * Retrieve the error value, if it exists
				 *
				 * @param mixed $response The WS response to check.
				 *
				 * @access public
				 * @return string
				 */
				function get_error ( $response ) {
					//Check if a response was successfully returned
					if ( ! property_exists($response, "return") ) {
						return "No valid web service request was returned. Check the log files to make sure a request was sent, and a response recieved.<br /><br />Make sure the Rating URL value in the Canpar Rate Calculator settings is set correctly.";
					}
					
					//Check if the error is stored in <errors> instead of <error>
					if (property_exists($response->return, "errors"))
					{$error = $response->return->errors;}
					else
					{$error = $response->return->error;}

					$error = explode("|", $error); // Split English and French. Only En will return

					return $error[0];
				}

				/**
				 * Write a log file with the soap requests
				 *
				 * @param string $name The name of the log file to write to.
				 * @param string $output The text to be put into the log.
				 * @param string $append Whether or not to append to the file, or create a new file. values "new" and "append"
				 *
				 * @access public
				 * @return void
				 */
				function canpar_log ( $name, $output, $append="new" ) {
					// Only write to the log file if the plugin's debug is enabled
					if ($this->instance_settings['debug'] == "no") {
						return;
					}
					
					// Make the log directory if it doesn't exist
					$dir = realpath(dirname(__FILE__, 3)) . "/uploads/logs";
					
					if ( !file_exists($dir) ) {
						mkdir($dir, 0744);
					}
					
					/*
					 * Always ensure the logs are not visible.
					 * A user may change dir permissions, or clear the log directory (including the .htaccess).
					 * Thus, make sure that any sensitive information written to the logs is hidden
					 */
					if ( ! file_exists("{$dir}/.htaccess") ) {
						file_put_contents("{$dir}/.htaccess", "Order deny,allow\nDeny from all");
					}
					
					// Determine the file name. If the file is to be appended, then do not include the time.
    					if ($append == "new") {
						$file_name = date('Ymd-His') . "-{$name}.log";
					}
					else {
						$file_name = date('Ymd') . "-{$name}.log";
					}
					
					// Put the timestamp at the beginning of the output, if required
					if ( file_exists($file_name) || $append == "append" ) {
						$output = "==========\n" . date('F d, Y - H:i:s') . "\n==========\n{$output}\n\n";
					}
					
					// Output
					file_put_contents($dir . '/' . $file_name, $output, FILE_APPEND);
					
					// Output log files to the page
					if ($this->settings['output_logs'] == "yes" && ! is_admin()) // Don't show the logs in an admin page
					{
						// Only output the logs to admins. Note that logs contain sensitive login information for the Canpar API.
						if (current_user_can('edit_plugins') === true)
						{
							print "<pre><b>{$file_name}</b>\n" . htmlentities($output) . '</pre>';
						}
					}
				}

				/**
				 * Get the shipping date, with the lead time, in the correct format
				 *
				 * @access public
				 * @return string
				 */
				function get_shipping_date () {
					// Get the current date, plus lead time specified
					$time = strtotime("+" . ($this->settings['lead_time']) . " weekdays");
					// Return the date in the correct format
					return date('Y-m-d\T00:00:00.000\Z', $time);
				}
				
				/**
				 * Output an error on screen
				 *
				 * @param string $error Error message
				 * @param string $line Line number
				 *
				 * @access public
				 * @return void
				 */
				function output_error ( $error, $line ) {
					// Only output the error if the plugin's debug is enabled
					if ($this->settings['debug'] == "no") {
						return;
					}
					
					// Only output the error messages if you are logged in as the admin
					if (current_user_can('edit_plugins') !== true) {
						return;
					}
					
					// Do not display errors in the settings page
					if (is_admin() === true) {
						return;
					}
					
					// Do not display errors about over weight packages for "letter" or "pak" services
					if ( (stripos($error, "letter") !== false || stripos($error, "pak") !== false) && stripos($error, "Package reported weight cannot exceed") !== false ) {
						return;
					}
					?>
					<div class="woocommerce-error">
						<b>Canpar rate calculator error reported from line <?php print $line; ?> (<?php print basename(__FILE__); ?>)</b><br />
						<?php print $error; ?><br /><br />
						<span style="font-size: 0.8em;">
							Disable debugging in the Canpar rate calculator plugin settings to hide error messages.<br />
							For support, please contact the Canpar service desk at: servicedesk@canpar.com
						</span>
					</div>
					<?php
				}
				
				/**
				 * Create a SOAP connection
				 *
				 * @param string $url the URL to connect to
				 *
				 * @access public
				 * @return mixed
				 */
				function soap_connect ( $url ) {
					// Don't bother connecting in the admin panel
					if (is_admin()) {
						return new StdClass(); // Return an empty class
					}

					// Check if $url can connect to the wsdl:

					// Try cURL first:
					if (function_exists('curl_version')) {
						$ch = @curl_init($url);
						curl_setopt($ch, CURLOPT_NOBODY, true);
						curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
						curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
						curl_exec($ch);
						$response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
						curl_close($ch);


						//Check if the URL was able to be connected to
						if ($response_code >= 200 && $response_code < 400) { // The URL exists
							$connect = true;
						}
						else {
							$connect = false;
							$this->canpar_log( (date('His') . "-curl_connect"), "curl_init({$url}):\n{$response_code}", "append");
						}
					}
					elseif (@get_headers($url, 1) !== false) { // If cURL fails, try get_headers() as a backup (cURL might be disabled)
						$response_code = get_headers($url, 1);

						if (strpos($response_code[0], '200') || strpos($response_code[1], '200')) {
							$connect = true;
						}
						else {
							$connect = false;
							$this->canpar_log( (date('His') . "-get_headers"), "get_headers({$url}, 1):\n" . print_r(get_headers($url, 1), 1), "append");
						}
					}
					else {
						$connect = false;
						$this->canpar_log( (date('His') . "-connect_error"), "get_headers({$url}):\n" . print_r(error_get_last(), 1), "append");
					}


					// Now connect to the WSDL
					if ($connect == true) {
						// Setup the SOAP client
						$SOAP_OPTIONS = array(
							'soap_version' => SOAP_1_2,
							'exceptions' => false,
							'trace' => 1,
							'cache_wsdl' => WSDL_CACHE_NONE,
							'features' => SOAP_SINGLE_ELEMENT_ARRAYS
						);

						// Connect
						$soap_client = new SoapClient($url, $SOAP_OPTIONS);
					}
					else {
						$this->output_error("Unable to connect to the Rating URL:<br /><br />{$url}<br /><br />Make sure this property in your settings is correct", __LINE__);
						$soap_client = new StdClass(); //Create a dummy object
					}
					
					return $soap_client;
				}
			}
		}
	}
 
	add_action( 'woocommerce_shipping_init', 'canpar_rate_calculator_init' );
 
	function add_canpar_shipping_method( $methods ) {
		$methods['canpar_rate_calculator'] = 'WC_Canpar_Rate_Calculator';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'add_canpar_shipping_method' );
}
