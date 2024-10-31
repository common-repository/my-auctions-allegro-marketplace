<?php
/*
 * Plugin Name: My Auctions Allegro Marketplace
 * Version: 1.1.0
 * Description: Plug-in give possibilities to import products for vendor
 * Author: Grojan Team
 * Author URI: https://www.grojanteam.pl
 * Text Domain: my-auctions-allegro-marketplace
 * Requires PHP: 7.4
 * WC Requires at least: 4.0
 * WC Tested up to: 5.9.2
 */

defined('ABSPATH') or die();

class GJMAA_Marketplace
{

    protected $isActive = false;

	private $connectedApplications = [];

	private $allowedActions = [
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_auctiontemplate',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_product',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_product_additionalservicesgroup',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_product_aftersalesservices',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_product_commands',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_product_export',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_product_shippingrates',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_product_sizetables',
		'gjwa_pro_before_set_capabilities_for_gjwa_pro_template_description',
		'gjmaa_before_set_capabilities_for_gjmaa_profiles',
		'gjmaa_before_set_capabilities_for_gjmaa_auctions'
	];

    public function __construct()
    {
        $this->checkRequirements();
        
        if ($this->isActive()) {
            add_action('init', [
                $this,
                'addRequiredColumnToDatabase'
            ]);
            add_action('gjmaa_service_woocommerce_assign_additional_data',[
                $this,
                'assignAdditionalData'
            ], 10, 2);
            add_filter('gjmaa_get_columns_settings_filter',[
                $this,
                'addRequiredColumnToFilter'
            ], 20, 1);
            add_filter('gjmaa_helper_setting_fields', [
                $this,
                'addVendorField'
            ], 20, 1);
			foreach($this->allowedActions as $allowed_action) {
				add_filter($allowed_action, [
					$this,
					'addAccessIfCanEditProduct'
				], 10, 1);
			}
			add_filter('gjmaa_filter_options_gjmaa_source_settings', [
				$this,
				'limitConnectedApplications'
			], 10, 1);
        }
        
        if($this->isMyAuctionsAllegroActive()) {
            register_deactivation_hook ( __FILE__, [$this,'uninstall'] );
        }
    }

    public function isActive()
    {
        return $this->isActive;
    }

    public function setActivePlugin()
    {
        $this->isActive = true;
    }
    
    public function addVendorField($fields)
    {
        $vendors = $this->getVendorsOption();
        
        $fields += [
            'setting_marketplace_vendor' => [
                'id' => 'setting_marketplace_vendor',
                'type' => 'select',
                'name' => 'setting_marketplace_vendor',
                'label' => __('Vendor', 'my-auctions-allegro-marketplace'),
                'options' => $vendors,
                'help' => __('Choose vendor from list', 'my-auctions-allegro-marketplace'),
                'disabled' => false,
                'required' => false
            ]
        ];
        
        return $fields;
    }
    
    public function getVendors($userId = null)
    {
        global $wpdb;
        
        $roles_in_array = array('dc_vendor');
        $suspended_check = array(
            'relation' => 'AND',
            0 => array(
                'key' => '_vendor_turn_off',
                'value' => '',
                'compare' => 'NOT EXISTS'
            )
        );
        
        $args = array(
            'role__in' => $roles_in_array,
        );
        
        if(isset($suspended_check)) $args['meta_query'] = $suspended_check;
        
        // Create the WP_User_Query object
        $wp_user_query = new WP_User_Query( $args );
        
        // Get the results
        $users = $wp_user_query->get_results();
        
        if(!$userId) {
            return $users;
        }
        
        foreach($users as $user) {
            if($user->data->ID == $userId) {
                return $user;
            }
        }
        
        return null;
    }
    
    public function getVendorsOption() 
    {
        $users = $this->getVendors();
        
        $user_list = ['' => __('Choose')];
        foreach($users as $user) {            
            $user_list[$user->data->ID] = $user->data->display_name;
        }
        
        return $user_list;
    }
    
    public function isPluginActive($pluginName) 
    {
        if (function_exists('is_plugin_active')) {
            return is_plugin_active($pluginName);
        } else {
            include_once (ABSPATH . 'wp-admin/includes/plugin.php');
            return is_plugin_active($pluginName);
        }
        
        return false;
    }
    
    public function isWooCommerceActive() 
    {
        return $this->isPluginActive('woocommerce/woocommerce.php');
    }
    
    public function isMyAuctionsAllegroActive() 
    {
        return $this->isPluginActive('my-auctions-allegro-free-edition/my-auctions-allegro-free-edition.php'); 
    }
    
    public function isWooCommerceMarketplaceActive()
    {
        return $this->isPluginActive('dc-woocommerce-multi-vendor/dc_product_vendor.php');
    }
    
    public function checkRequirements()
    {
        if($this->isWooCommerceActive() && $this->isMyAuctionsAllegroActive() && $this->isWooCommerceMarketplaceActive()) {
            $this->setActivePlugin();
        }
    }
    
    public function addRequiredColumnToDatabase()
    {
        $settings = GJMAA::getModel('settings');
        if($settings->existColumn($settings->getTable(), 'setting_marketplace_vendor')) {
            return;
        }
        
        $settings->addColumn(
            $settings->getTable(), 
            'setting_marketplace_vendor',
            [
                'INT',
                'NULL'
            ]
        );
    }
    
    public function addRequiredColumnToFilter($columns){
        $columns += [
            'setting_marketplace_vendor' => [
                'schema' => [
                    'INT',
                    'NULL'
                ],
                'format' => '%d'
            ]
        ];
        
        return $columns;
    }
    
    public function assignAdditionalData($productId, $settingId)
    {        
        $settings = GJMAA::getModel('settings');
        $settings->load($settingId);
        
        if(!$settings->getId()) {
            $this->unlinkRelationsBetweenVendorsAndProduct($productId);
            $arg = array(
                'ID' => $productId,
                'post_author' => 0,
            );
            return;
        }
        
        $marketplace_vendor = $settings->getData('setting_marketplace_vendor');
        if(!$marketplace_vendor) {
            $this->unlinkRelationsBetweenVendorsAndProduct($productId);
            $arg = array(
                'ID' => $productId,
                'post_author' => 0,
            );
            return;
        }
        
        $arg = array(
            'ID' => $productId,
            'post_author' => $marketplace_vendor,
        );
        
        wp_update_post( $arg );
        
        $vendorTermTaxonomyId = $this->getVendorTermTaxonomyIdByUserId($marketplace_vendor);
        if(!$vendorTermTaxonomyId) {
            return;
        }
        
        $this->unlinkRelationsBetweenVendorsAndProduct($productId);
        
        $this->addRelationBetweenVendorAndProduct($vendorTermTaxonomyId, $productId);
    }
    
    public function getVendorTermTaxonomyIdByUserId($userId)
    {
        $vendorTermId = get_user_meta($userId,'_vendor_term_id',true);
        
        $term = get_term($vendorTermId,'dc_vendor_shop',ARRAY_A);
        
        if(!$term) {
            return null;
        }
        
        $vendorTermTaxonomyId = $term['term_taxonomy_id'];
        
        return $vendorTermTaxonomyId;
    }
    
    public function unlinkRelationsBetweenVendorsAndProduct($productId)
    {
        $vendors = $this->getVendors();
        
        if(count($vendors) > 0) {
            foreach($vendors as $vendor) {
                $vendorTermTaxonomyId = $this->getVendorTermTaxonomyIdByUserId($vendor->data->ID);
                
                $this->unlinkRelationBetweenVendorAndProduct($vendorTermTaxonomyId, $productId);
            }
        }
    }
    
    public function unlinkRelationBetweenVendorAndProduct($vendorTermTaxonomyId, $productId)
    {
        global $wpdb;
        
        $sql = "DELETE FROM {$wpdb->prefix}term_relationships WHERE object_id = %d AND term_taxonomy_id = %d";
        $sql = $wpdb->prepare($sql, $productId, $vendorTermTaxonomyId);
        $wpdb->query($sql);
    }
    
    public function addRelationBetweenVendorAndProduct($vendorTermTaxonomyId,$productId)
    {
        global $wpdb;
        
        $sql = "INSERT INTO {$wpdb->prefix}term_relationships (object_id,term_taxonomy_id) VALUES (%d,%d) ON DUPLICATE KEY UPDATE term_taxonomy_id = %d";
        $sql = $wpdb->prepare($sql, $productId, $vendorTermTaxonomyId, $vendorTermTaxonomyId);
        $wpdb->query($sql);
    }
    
    public function uninstall()
    {
        $settings = GJMAA::getModel('settings');
        if(!$settings->existColumn($settings->getTable(), 'setting_marketplace_vendor')) {
            return;
        }
        
        $settings->removeColumn(
            $settings->getTable(),
            'setting_marketplace_vendor'
        );
    }

	public function addAccessIfCanEditProduct($cap)
	{
		if(!$this->isQualifiedVendor()) {
			return $cap;
		}

		return 'edit_products';
	}

	private function isQualifiedVendor() : bool
	{
		$user = wp_get_current_user();
		if(!$user instanceof WP_User) {
			return false;
		}

		if(!wc_user_has_role($user, 'dc_vendor')) {
			return false;
		}

		return true;
	}

	private function getConnectedApplications(int $userId) : array
	{
		if(!isset($this->connectedApplications[$userId])) {
			/** @var GJMAA_Model_Settings $settingsModel */
			$settingsModel     = GJMAA::getModel('settings');
			$connectedSettings = $settingsModel->getAllBySearch([
				'WHERE' => sprintf('setting_marketplace_vendor = %d', $userId)
			]);

			$connectedApplications = [];
			foreach ( $connectedSettings as $connected_setting ) {
				$connectedApplications[] = $connected_setting[ 'setting_id' ];
			}

			$this->connectedApplications[$userId] = $connectedApplications;
		}

		return $this->connectedApplications[$userId];
	}

	public function limitConnectedApplications($options)
	{
		if(!$this->isQualifiedVendor()) {
			return $options;
		}

		$connectedApplications = $this->getConnectedApplications(get_current_user_id());

		foreach($options as $optionId => $optionName) {
			if(in_array($optionId, $connectedApplications)) {
				continue;
			}

			unset($options[$optionId]);
		}

		return $options;
	}
}

new GJMAA_Marketplace();
