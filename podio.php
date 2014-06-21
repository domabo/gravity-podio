<?php
/*
Plugin Name: Gravity Forms Podio Add-On
Plugin URI: https://github.com/domabo/gravity-podio-feed
Description: Integrates Gravity Forms with Podio allowing form submissions to be automatically sent to your Podio account
Version: 2.4.1
Author: Domabo
Author URI: https://github.com/domabo

------------------------------------------------------------------------
Copyright 2014 Domabo;  portions copyright Rocket Genius

Forked from SimpleFeedAddon and MailChimp Add On under GPLv2

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFPodio', 'init'));
register_activation_hook( __FILE__, array("GFPodio", "add_permissions"));

class GFPodio {

    private static $path = "gravity-podio-feed/podio.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravityformspodio";
    private static $version = "2.4.1";
    private static $min_gravityforms_version = "1.7.6.11";
    private static $supported_fields = array("checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
        "post_tags", "post_custom_field", "post_content", "post_excerpt");

//Plugin starting point. Will load appropriate files
    public static function init(){


//supports logging
        add_filter("gform_logging_supported", array("GFPodio", "set_logging_supported"));

        if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

//loading translations
            load_plugin_textdomain('gravityformspodio', FALSE, '/gravityformspodio/languages' );

            add_action('after_plugin_row_' . self::$path, array('GFPodio', 'plugin_row') );

        }

        if(!self::is_gravityforms_supported()){
            return;
        }

        if(is_admin()){
//loading translations
            load_plugin_textdomain('gravityformspodio', FALSE, '/gravityformspodio/languages' );

            add_filter("transient_update_plugins", array('GFPodio', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFPodio', 'check_update'));
            add_action('install_plugins_pre_plugin-information', array('GFPodio', 'display_changelog'));
            add_action('gform_after_check_update', array("GFPodio", 'flush_version_info'));

//creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_podio")){
                RGForms::add_settings_page("Podio", array("GFPodio", "settings_page"), self::get_base_url() . "/images/podio_wordpress_icon_32.png");
            }
        }
        else{
// ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFPodio', 'premium_update_push') );
            add_filter( 'mwp_premium_perform_update', array('GFPodio', 'premium_update') );
        }

//integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFPodio", "members_get_capabilities"));

//creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFPodio', 'create_menu'));

        if(self::is_podio_page()){

//enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));

//loading data lib
            require_once(self::get_base_path() . "/data.php");

//loading upgrade lib
            if(!class_exists("GFPodioUpgrade"))
                require_once("plugin-upgrade.php");

//loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GFPodio', 'tooltips'));

//runs the setup when version changes
            self::setup();

        }
        else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

//loading data class
            require_once(self::get_base_path() . "/data.php");

            add_action('wp_ajax_rg_update_feed_active', array('GFPodio', 'update_feed_active'));
            add_action('wp_ajax_gf_select_podio_form', array('GFPodio', 'select_podio_form'));
            add_action('wp_ajax_gf_get_podio_app', array('GFPodio', 'get_podio_app'));
        }
        else{
//handling post submission.
            add_action("gform_after_submission", array('GFPodio', 'export_toPodio'), 10, 2);

        }
    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFPodioData::get_feed($id);
        GFPodioData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

//--------------   Automatic upgrade ---------------------------------------------------

//Integration with ManageWP
    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

//Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
$plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

array_push($premium_update, $plugin_data);
}
return $premium_update;
}

public static function flush_version_info(){
    if(!class_exists("GFPodioUpgrade"))
        require_once("plugin-upgrade.php");

    GFPodioUpgrade::set_version_info(false);
}

public static function plugin_row(){
    if(!class_exists("GFPodioUpgrade"))
        require_once("plugin-upgrade.php");

    if(!self::is_gravityforms_supported()){
        $message = sprintf(__("Gravity Forms " . self::$min_gravityforms_version . " is required. Activate it now or %spurchase it today!%s"), "<a href='http://www.gravityforms.com'>", "</a>");
        GFPodioUpgrade::display_plugin_message($message, true);
    }
    else{
        $version_info = GFPodioUpgrade::get_version_info(self::$slug, self::get_key(), self::$version);

        if(!$version_info["is_valid_key"]){
            $new_version = version_compare(self::$version, $version_info["version"], '<') ? __('There is a new version of Gravity Forms Podio Add-On available.', 'gravityformspodio') .' <a class="thickbox" title="Gravity Forms Podio Add-On" href="plugin-install.php?tab=plugin-information&plugin=' . self::$slug . '&TB_iframe=true&width=640&height=808">'. sprintf(__('View version %s Details', 'gravityformspodio'), $version_info["version"]) . '</a>. ' : '';
            $message = $new_version . sprintf(__('%sRegister%s your copy of Gravity Forms to receive access to automatic upgrades and support. Need a license key? %sPurchase one now%s.', 'gravityformspodio'), '<a href="admin.php?page=gf_settings">', '</a>', '<a href="http://www.gravityforms.com">', '</a>') . '</div></td>';
            GFPodioUpgrade::display_plugin_message($message);
        }
    }
}

//Displays current version details on Plugin's page
public static function display_changelog(){
    if($_REQUEST["plugin"] != self::$slug)
        return;

//loading upgrade lib
    if(!class_exists("GFPodioUpgrade"))
        require_once("plugin-upgrade.php");

    GFPodioUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
}

public static function check_update($update_plugins_option){
    if(!class_exists("GFPodioUpgrade"))
        require_once("plugin-upgrade.php");

    return GFPodioUpgrade::check_update(self::$path, self::$slug, self::$url, self::$slug, self::get_key(), self::$version, $update_plugins_option);
}

private static function get_key(){
    if(self::is_gravityforms_supported())
        return GFCommon::get_key();
    else
        return "";
}
//---------------------------------------------------------------------------------------

//Returns true if the current page is an Feed pages. Returns false if not
private static function is_podio_page(){
    $current_page = trim(strtolower(rgget("page")));
    $podio_pages = array("gf_podio");

    return in_array($current_page, $podio_pages);
}

//Creates or updates database tables. Will only run when version changes
private static function setup(){

    if(get_option("gf_podio_version") != self::$version)
        GFPodioData::update_table();

    update_option("gf_podio_version", self::$version);
}

//Adds feed tooltips to the list of tooltips
public static function tooltips($tooltips){
    $podio_tooltips = array(
        "podio_appid" => "<h6>" . __("Podio App Id", "gravityformspodio") . "</h6>" . __("Enter the Podio app you would like to add your form data to.", "gravityformspodio"),
        "podio_apptoken" => "<h6>" . __("Podio App Token", "gravityformspodio") . "</h6>" . __("Enter the Podio secret token you would like to add your form data to.", "gravityformspodio"),
        "podio_gravity_form" => "<h6>" . __("Gravity Form", "gravityformspodio") . "</h6>" . __("Select the Gravity Form you would like to integrate with Podio. Contacts generated by this form will be automatically added to your Podio account.", "gravityformspodio"),
        "podio_map_fields" => "<h6>" . __("Map Fields", "gravityformspodio") . "</h6>" . __("Associate your Podio merge variables to the appropriate Gravity Form fields by selecting.", "gravityformspodio"),
        "podio_optin_condition" => "<h6>" . __("Opt-In Condition", "gravityformspodio") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to Podio when the condition is met. When disabled all form submissions will be exported.", "gravityformspodio"),
        );
return array_merge($tooltips, $podio_tooltips);
}

//Creates Podio left nav menu under Forms
public static function create_menu($menus){

// Adding submenu if user has access
    $permission = self::has_access("gravityforms_podio");
    if(!empty($permission))
        $menus[] = array("name" => "gf_podio", "label" => __("Podio", "gravityformspodio"), "callback" =>  array("GFPodio", "podio_page"), "permission" => $permission);

    return $menus;
}

public static function settings_page(){

    if(!class_exists("GFPodioUpgrade"))
        require_once("plugin-upgrade.php");

    if(rgpost("uninstall")){
        check_admin_referer("uninstall", "gf_podio_uninstall");
        self::uninstall();

        ?>
        <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Podio Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformspodio")?></div>
        <?php
        return;
    }
    else if(rgpost("gf_podio_submit")){
        check_admin_referer("update", "gf_podio_update");
        $settings = array("apiclientid" => $_POST["gf_podio_apiclientid"], "apiclientsecret" => $_POST["gf_podio_apiclientsecret"]);

        update_option("gf_podio_settings", $settings);
    }
    else{
        $settings = get_option("gf_podio_settings");
    }

//feedback for api keys
    $feedback_image = "";
    $is_valid_apikey = false;
    if(!empty($settings["apiclientid"])){
        $is_valid_apikey = self::is_valid_login($settings["apiclientid"], $settings["apiclientsecret"]);
        $icon = $is_valid_apikey ? self::get_base_url() . "/images/tick.png" : self::get_base_url() . "/images/stop.png";
        $feedback_image = "<img src='{$icon}' />";
    }

    ?>
    <style>
        .valid_credentials{color:green;}
        .invalid_credentials{color:red;}
    </style>

    <form method="post" action="">
        <?php wp_nonce_field("update", "gf_podio_update") ?>
        <h3><?php _e("Podio Account Information", "gravityformspodio") ?></h3>
        <p style="text-align: left;">
            <?php _e(sprintf("is an online work platform with a new take on how everyday work gets done. Use Gravity Forms to collect data and automatically add items to your Podio app. If you don't have a Podio account, you can %ssign up for one here%s", "<a href='http://www.podio.com/' target='_blank'>" , "</a>"), "gravityformspodio") ?>
        </p>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="gf_podio_apiclientid"><?php _e("Podio API Client Id", "gravityformspodio"); ?></label> </th>
                <td>
                    <input type="text" id="gf_podio_apiclientid" name="gf_podio_apiclientid" value="<?php echo empty($settings["apiclientid"]) ? "" : esc_attr($settings["apiclientid"]) ?>" size="50"/>
                    <?php echo $feedback_image?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gf_podio_apiclientsecret"><?php _e("Podio API Client Secret", "gravityformspodio"); ?></label> </th>
                <td>
                    <input type="text" size="80" id="gf_podio_apiclientsecret" name="gf_podio_apiclientsecret" value="<?php echo empty($settings["apiclientsecret"]) ? "" : esc_attr($settings["apiclientsecret"]) ?>" size="50"/>
                    <?php echo $feedback_image?>
                </td>
            </tr>
            <tr>
                <td colspan="2" ><input type="submit" name="gf_podio_submit" class="button-primary" value="<?php _e("Save Settings", "gravityformspodio") ?>" /></td>
            </tr>
        </table>
    </form>

    <form action="" method="post">
        <?php wp_nonce_field("uninstall", "gf_podio_uninstall") ?>
        <?php if(GFCommon::current_user_can_any("gravityforms_podio_uninstall")){ ?>
            <div class="hr-divider"></div>

            <h3><?php _e("Uninstall Podio Add-On", "gravityformspodio") ?></h3>
            <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Podio Feeds.", "gravityformspodio") ?>
                <?php
                $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Podio Add-On", "gravityformspodio") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL Podio Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformspodio") . '\');"/>';
                echo apply_filters("gform_podio_uninstall_button", $uninstall_button);
                ?>
            </div>
            <?php } ?>
        </form>
        <?php
    }

    public static function podio_page(){
        $view = rgar($_GET,"view");
        if($view == "edit")
            self::edit_page($_GET["id"]);
        else
            self::app_page();
    }

//Displays the podio feeds app page
    private static function app_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("Podio Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformspodio"));
        }

        if(rgpost("action") == "delete"){
            check_admin_referer("app_action", "gf_podio_app");

            $id = absint($_POST["action_argument"]);
            GFPodioData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformspodio") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("app_action", "gf_podio_app");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFPodioData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformspodio") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("Podio Feeds", "gravityformspodio") ?>" src="<?php echo self::get_base_url()?>/images/podio_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php _e("Podio Feeds", "gravityformspodio"); ?>
                <a class="button add-new-h2" href="admin.php?page=gf_podio&view=edit&id=0"><?php _e("Add New", "gravityformspodio") ?></a>
            </h2>


            <form id="feed_form" method="post">
                <?php wp_nonce_field('app_action', 'gf_podio_app') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformspodio") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformspodio") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformspodio") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravityformspodio") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravityformspodio") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspodio") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspodio") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Podio App", "gravityformspodio") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspodio") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Podio App", "gravityformspodio") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $settings = GFPodioData::get_feeds();
                        if(is_array($settings) && sizeof($settings) > 0){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravityformspodio") : __("Inactive", "gravityformspodio");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravityformspodio") : __("Inactive", "gravityformspodio");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_podio&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravityformspodio") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a href="admin.php?page=gf_podio&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravityformspodio") ?>"><?php _e("Edit", "gravityformspodio") ?></a>
                                                |
                                            </span>

                                            <span class="trash">
                                                <a title="<?php _e("Delete", "gravityformspodio") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformspodio") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspodio") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravityformspodio")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date"><?php echo $setting["meta"]["podio_spaceid"]. "/" . $setting["meta"]["podio_appname"]; ?></td>
                                </tr>
                                <?php
                            }
                        }
                        else if(self::get_api()){
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("You don't have any Podio feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_podio&view=edit&id=0">', "</a>"), "gravityformspodio"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("To get started, please configure your %sPodio Settings%s.", '<a href="admin.php?page=gf_settings&addon=Podio">', "</a>"), "gravityformspodio"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformspodio") ?>').attr('alt', '<?php _e("Inactive", "gravityformspodio") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformspodio") ?>').attr('alt', '<?php _e("Active", "gravityformspodio") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravityformspodio" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
    }

    private static function is_valid_login($PODIO_CLIENTID, $PODIO_CLIENTSECRET){
        if(!class_exists("Podio")){
            require_once("api-podio/PodioAPI.php");
        }

        Podio::setup($PODIO_CLIENTID, $PODIO_CLIENTSECRET);


        return (!empty($PODIO_CLIENTID) && !empty($PODIO_CLIENTSECRET)) ? true : false;
    }

    private static function get_api(){

//global podio settings
        $settings = get_option("gf_podio_settings");
        $api = null;

        if(!empty($settings["apiclientid"])){
            if(!class_exists("Podio")){
                require_once("api-podio/PodioAPI.php");
            }
            self::log_debug("Retrieving API Info for key " . $settings["apiclientid"]);
            Podio::setup($settings["apiclientid"], $settings["apiclientsecret"]);
            $api=array("clientid"=>$settings["apiclientid"], "clientsecret"=>$settings["apiclientsecret"]);
        } else {
            self::log_debug("API credentials not set");
            return null;
        }

        if(!$api){
            self::log_error("Failed to set up the API");
            return null;
        } 

        self::log_debug("Successful API response received");

        return $api;
    }

    private static function edit_page(){
        ?>
        <style>
            .podio_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold;}
            .podio_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .gfield_required{color:red;}

            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0;}
            .podio_group_condition{padding-bottom:6px; padding-left:20px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <img alt="<?php _e("Podio", "gravityformspodio") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/podio_wordpress_icon_32.png"/>
            <h2><?php _e("Podio Feed", "gravityformspodio") ?></h2>

            <?php

//getting Podio API
            $api = self::get_api();

//ensures valid credentials were entered in the settings page
            if(!$api){
                ?>
                <div><?php echo sprintf(__("We are unable to login to Podio with the provided credentials. Please make sure they are valid in the %sSettings Page%s", "gravityformspodio"), "<a href='?page=gf_settings&addon=Podio'>", "</a>"); ?></div>
                <?php
                return;
            }

//getting setting id (0 when creating a new one)
            $id = !empty($_POST["podio_setting_id"]) ? $_POST["podio_setting_id"] : absint($_GET["id"]);
            $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFPodioData::get_feed($id);

            if(!isset($config["meta"]))
                $config["meta"] = array();

            if(rgpost("gf_podio_submit")){
                $appid = absint($_POST["podio_appid"]);
                $apptoken= $_POST["podio_apptoken"];

                $config["meta"]["podio_appid"] = $appid;
                $config["meta"]["podio_apptoken"] = $apptoken;

                $config["form_id"] = absint($_POST["gf_podio_form"]);

                $is_valid = true;
//getting merge vars from selected app (if one was entered or submitted)
                if (rgempty("podio_appid", $config["meta"]))
                {
                    $merge_vars = array();
                }
                else
                {
                    $merge_vars = self::get_PodioAppMergeVars($config);
                }

                $appname= $config["meta"]["podio_appname"];
                $spaceid= $config["meta"]["podio_spaceid"];

                $field_map = array();
                foreach($merge_vars as $var){
                    $field_name = "podio_map_field_" . $var["tag"];
                    $mapped_field = stripslashes($_POST[$field_name]);
                    if(!empty($mapped_field)){
                        $field_map[$var["tag"]] = $mapped_field;
                    }
                    else{
                        unset($field_map[$var["tag"]]);
                        if($var["req"] == "Y")
                            $is_valid = false;
                    }
                }

                $enabled_groups = rgpost("podio_group");
                $enabled_groupings = array();
                if(is_array($enabled_groups)){
                    foreach($enabled_groups as $enabled_group){
                        $group_info = explode("__",$enabled_group);
                        $grouping_n = $group_info[0];
                        $group_n = $group_info[1];
                        $decision = rgpost("podio_group_". $grouping_n . "_" . $group_n ."_decision");
                        $field_id =  rgpost("podio_group_". $grouping_n . "_" . $group_n ."_field_id");
                        $operator = rgpost("podio_group_". $grouping_n . "_" . $group_n . "_operator");
                        $value = rgpost("podio_group_". $grouping_n . "_" . $group_n . "_value");
                        $grouping_label = rgpost($grouping_n . "_grouping_label");
                        $group_label = rgpost("podio_group_". $group_n . "_label");
                        $enabled_groupings[$grouping_n][$group_n] = array("field_id" => $field_id,"operator" => $operator, "enabled" => "true", "value" => $value, "decision" => $decision, "grouping_label" => $grouping_label, "group_label"=> $group_label);
                    }
                }

                $config["meta"]["groups"] = $enabled_groupings;

                $config["meta"]["field_map"] = $field_map;
                $config["meta"]["optin_enabled"] = rgpost("podio_optin_enable") ? true : false;
                $config["meta"]["optin_field_id"] = $config["meta"]["optin_enabled"] ? rgpost("podio_optin_field_id") : "";
                $config["meta"]["optin_operator"] = $config["meta"]["optin_enabled"] ? rgpost("podio_optin_operator") : "";
                $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? rgpost("podio_optin_value") : "";

                if($is_valid){
                    $id = GFPodioData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                    ?>
                    <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravityformspodio"), "<a href='?page=gf_podio'>", "</a>") ?></div>
                    <input type="hidden" name="podio_setting_id" value="<?php echo $id ?>"/>
                    <?php
                }
                else{
                    ?>
                    <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravityformspodio") ?></div>
                    <?php
                }
            } else
            {

//getting merge vars from selected app (if one was entered or submitted)
                if (rgempty("podio_appid", $config["meta"]))
                {
                    $merge_vars = array();
                    $appid="";
                    $apptoken= "";
                    $spaceid="";
                    $appname= "-";
                }
                else
                {
                    $merge_vars = self::get_PodioAppMergeVars($config);
                    $appid=absint($config["meta"]["podio_appid"]);
                    $apptoken= $config["meta"]["podio_apptoken"];
                    $spaceid=$config["meta"]["podio_spaceid"];
                    $appname= $config["meta"]["podio_appname"];
                }


            }

            ?>
            <form method="post" action="">
                <input type="hidden" name="podio_setting_id" value="<?php echo $id ?>"/>

                <div class="margin_vertical_10">
                    <table>
                        <tr><td><label for="podio_appid" class="left_header"><?php _e("Podio App Id", "gravityformspodio"); ?> <?php gform_tooltip("podio_appid") ?></label></td>
                            <td><input type="text" id="podio_appid" name="podio_appid" onchange="SelectAppSpace(jQuery(this).val(),jQuery('#podio_apptoken').val());" value="<?php echo $appid; ?>"/> <span id="gf_appname"><?php echo $appname; ?></span></td></tr>
                            <tr><td><label for="podio_apptoken" class="left_header"><?php _e("Podio App Token", "gravityformspodio"); ?> <?php gform_tooltip("podio_apptoken") ?></label></td>
                                <td><input size="80" type="text" id="podio_apptoken" name="podio_apptoken" value="<?php echo $apptoken; ?>" onchange="SelectAppSpace(jQuery('#podio_appid').val(), jQuery(this).val());"/></td></tr>
                                <tr><td><label for="podio_spaceid" class="left_header"><?php _e("Podio Workspace Id", "gravityformspodio"); ?> <?php gform_tooltip("podio_spaceid") ?></label></td>
                                    <td><span id="gf_spaceid"><?php echo $spaceid; ?></span></td></tr>
                                </table>
                            </div>

                            <div id="podio_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["podio_appid"]) ? "style='display:none;'" : "" ?>>
                                <label for="gf_podio_form" class="left_header"><?php _e("Gravity Form", "gravityformspodio"); ?> <?php gform_tooltip("podio_gravity_form") ?></label>

                                <select id="gf_podio_form" name="gf_podio_form" onchange="SelectForm(jQuery('#podio_appid').val(),jQuery('#podio_apptoken').val(),jQuery(this).val());">
                                    <option value=""><?php _e("Select a form", "gravityformspodio"); ?> </option>
                                    <?php
                                    $forms = RGFormsModel::get_forms();
                                    foreach($forms as $form){
                                        $selected = absint($form->id) == rgar($config,"form_id") ? "selected='selected'" : "";
                                        ?>
                                        <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                                        <?php
                                    }
                                    ?>
                                </select>
                                &nbsp;&nbsp;
                                <img src="<?php echo GFPodio::get_base_url() ?>/images/loading.gif" id="podio_wait" style="display: none;"/>
                            </div>
                            <div id="podio_field_group" valign="top" <?php echo empty($config["meta"]["podio_appid"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                                <div id="podio_field_container" valign="top" class="margin_vertical_10" >
                                    <label for="podio_fields" class="left_header"><?php _e("Map Fields", "gravityformspodio"); ?> <?php gform_tooltip("podio_map_fields") ?></label>

                                    <div id="podio_field_list">
                                        <?php
                                        if(!empty($config["form_id"])){
//getting field map UI
                                            echo self::get_field_mapping($config, $config["form_id"], $merge_vars);

//getting list of selection fields to be used by the optin
                                            $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                                        }
                                        ?>
                                    </div>
                                </div>

                                <div id="podio_optin_container" valign="top" class="margin_vertical_10">
                                    <label for="podio_optin" class="left_header"><?php _e("Opt-In Condition", "gravityformspodio"); ?> <?php gform_tooltip("podio_optin_condition") ?></label>
                                    <div id="podio_optin">
                                        <table>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" id="podio_optin_enable" name="podio_optin_enable" value="1" onclick="if(this.checked){jQuery('#podio_optin_condition_field_container').show('slow');} else{jQuery('#podio_optin_condition_field_container').hide('slow');}" <?php echo rgar($config["meta"],"optin_enabled") ? "checked='checked'" : ""?>/>
                                                    <label for="podio_optin_enable"><?php _e("Enable", "gravityformspodio"); ?></label>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <div id="podio_optin_condition_field_container" <?php echo !rgar($config["meta"],"optin_enabled") ? "style='display:none'" : ""?>>
                                                        <div id="podio_optin_condition_fields" style="display:none">
                                                            <?php _e("Export to Podio if ", "gravityformspodio") ?>
                                                            <select id="podio_optin_field_id" name="podio_optin_field_id" class='optin_select' onchange='jQuery("#podio_optin_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                                            <select id="podio_optin_operator" name="podio_optin_operator" >
                                                                <option value="is" <?php echo rgar($config["meta"], "optin_operator") == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravityformspodio") ?></option>
                                                                <option value="isnot" <?php echo rgar($config["meta"], "optin_operator") == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravityformspodio") ?></option>
                                                                <option value=">" <?php echo rgar($config['meta'], 'optin_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravityformspodio") ?></option>
                                                                <option value="<" <?php echo rgar($config['meta'], 'optin_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravityformspodio") ?></option>
                                                                <option value="contains" <?php echo rgar($config['meta'], 'optin_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravityformspodio") ?></option>
                                                                <option value="starts_with" <?php echo rgar($config['meta'], 'optin_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravityformspodio") ?></option>
                                                                <option value="ends_with" <?php echo rgar($config['meta'], 'optin_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravityformspodio") ?></option>
                                                            </select>
                                                            <div id="podio_optin_value_container" name="podio_optin_value_container" style="display:inline;"></div>
                                                        </div>
                                                        <div id="podio_optin_condition_message" style="display:none">
                                                            <?php _e("To create an Opt-In condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>


                                    <script type="text/javascript">
                                        <?php
                                        if(!empty($config["form_id"])){
                                            ?>
//creating Javascript form object
form = <?php echo GFCommon::json_encode($form_meta)?> ;

//initializing drop downs
jQuery(document).ready(function(){
    var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
    var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
    SetOptin(selectedField, selectedValue);

});
<?php
}
?>
</script>
</div>

<div id="podio_submit_container" class="margin_vertical_10">
    <input type="submit" name="gf_podio_submit" value="<?php echo empty($id) ? __("Save", "gravityformspodio") : __("Update", "gravityformspodio"); ?>" class="button-primary"/>
    <input type="button" value="<?php _e("Cancel", "gravityformspodio"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_podio'" />
</div>
</div>
</form>
</div>
<script type="text/javascript">

    function SelectAppSpace(appid, apptoken){
        if((appid !="") && (apptoken != "")){
            jQuery("#podio_form_container").slideDown();
        }
        else if ((appid !="") || (apptoken != ""))
        {
            jQuery("#podio_form_container").slideDown();
            EndGetApp("");
            return false;
        }
        else{
            jQuery("#podio_form_container").slideUp();
            EndGetApp("");
            return false;
        }

        var mysack = new sack(ajaxurl);
        mysack.execute = 1;
        mysack.method = 'POST';
        mysack.setVar( "action", "gf_get_podio_app" );
        mysack.setVar( "gf_get_podio_app", "<?php echo wp_create_nonce("gf_get_podio_app") ?>" );
        mysack.setVar( "podio_appid", appid);
        mysack.setVar( "podio_apptoken", apptoken);
        mysack.encVar( "cookie", document.cookie, false );
        mysack.onError = function() {jQuery("#podio_wait").hide(); alert('<?php _e("Ajax error while setting App Id and Token", "gravityformspodio") ?>' )};
        mysack.runAJAX();

        return true;

    }

    function SelectForm(appid, apptoken, formId){
        if(!formId){
            jQuery("#podio_field_group").slideUp();
            return;
        }

        jQuery("#podio_wait").show();
        jQuery("#podio_field_group").slideUp();


        var mysack = new sack(ajaxurl);
        mysack.execute = 1;
        mysack.method = 'POST';
        mysack.setVar( "action", "gf_select_podio_form" );
        mysack.setVar( "gf_select_podio_form", "<?php echo wp_create_nonce("gf_select_podio_form") ?>" );
        mysack.setVar( "podio_appid", appid);
        mysack.setVar( "podio_apptoken", apptoken);
        mysack.setVar( "form_id", formId);
        mysack.encVar( "cookie", document.cookie, false );
        mysack.onError = function() {jQuery("#podio_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravityformspodio") ?>' )};
        mysack.runAJAX();

        return true;
    }

    function SetOptin(selectedField, selectedValue){

//load form fields
jQuery("#podio_optin_field_id").html(GetSelectableFields(selectedField, 20));
var optinConditionField = jQuery("#podio_optin_field_id").val();

if(optinConditionField){
    jQuery("#podio_optin_condition_message").hide();
    jQuery("#podio_optin_condition_fields").show();
    jQuery("#podio_optin_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
    jQuery("#podio_optin_value").val(selectedValue);
}
else{
    jQuery("#podio_optin_condition_message").show();
    jQuery("#podio_optin_condition_fields").hide();
}
}

function EndGetApp(appname, spaceid){

    if(appname!=""){
        jQuery("#gf_appname").html(appname);
        jQuery("#gf_spaceid").html(spaceid);
    }
    else{
        jQuery("#gf_appname").html("");
        jQuery("#gf_spaceid").html("");
    }
    jQuery("#podio_wait").hide();
}

function EndSelectForm(fieldList, form_meta){
//setting global form object
form = form_meta;
if(fieldList){

    SetOptin("","");

    jQuery("#podio_field_list").html(fieldList);

    jQuery("#podio_field_group").slideDown();
}
else{
    jQuery("#podio_field_group").slideUp();
    jQuery("#podio_field_list").html("");
}
jQuery("#podio_wait").hide();
}

function GetFieldValues(fieldId, selectedValue, labelMaxCharacters, inputName){
    if(!inputName){
        inputName = 'podio_optin_value';
    }

    if(!fieldId)
        return "";

    var str = "";
    var field = GetFieldById(fieldId);
    if(!field)
        return "";

    var isAnySelected = false;

    if(field["type"] == "post_category" && field["displayAllCategories"]){
        str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "podio_optin_value", "name"=> "podio_optin_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
    }
    else if(field.choices){
        str += '<select id="' + inputName +'" name="' + inputName +'" class="optin_select">';

        for(var i=0; i<field.choices.length; i++){
            var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
            var isSelected = fieldValue == selectedValue;
            var selected = isSelected ? "selected='selected'" : "";
            if(isSelected)
                isAnySelected = true;

            str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
        }

        if(!isAnySelected && selectedValue){
            str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
        }
        str += "</select>";
    }
    else
    {
        selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
//create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='" + inputName + "' name='" + inputName +"' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
}

return str;
}

function GetFieldById(fieldId){
    for(var i=0; i<form.fields.length; i++){
        if(form.fields[i].id == fieldId)
            return form.fields[i];
    }
    return null;
}

function TruncateMiddle(text, maxCharacters){
    if(text.length <= maxCharacters)
        return text;
    var middle = parseInt(maxCharacters / 2);
    return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
}

function GetSelectableFields(selectedFieldId, labelMaxCharacters){
    var str = "";
    var inputType;

    for(var i=0; i<form.fields.length; i++){
        fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
        inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
        if (IsConditionalLogicField(form.fields[i])) {
            var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
            str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
        }
    }
    return str;
}

function IsConditionalLogicField(field){
    inputType = field.inputType ? field.inputType : field.type;
    var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
    "post_tags", "post_custom_field", "post_content", "post_excerpt"];

    var index = jQuery.inArray(inputType, supported_fields);

    return index >= 0;
}

</script>

<?php

}

public static function get_PodioAppMergeVars(&$config)
{

    $appid=absint($config["meta"]["podio_appid"]);
    $apptoken= $config["meta"]["podio_apptoken"];

    $merge_vars = array();
    try {
        if (!Podio::is_authenticated())
        {
            Podio::authenticate('app', array(
                'app_id' => $appid,
                'app_token' => $apptoken
                ));
        }

        $podioApp=PodioApp::get( $appid, $attributes = array() );
        $config["meta"]["podio_appname"] = $podioApp->config["name"];
        $config["meta"]["podio_spaceid"] = $podioApp->space_id;

        foreach ($podioApp->fields as $field) {
            if ($field->status=="active"){
                $mergefield=array();
                $mergefield["tag"]=$field->external_id;
//   $mergefield["externalid"]=$field->external_id;
                $mergefield["name"]=$field->config["label"];
                $mergefield["req"]=$field->config["required"];
                $mergefield["type"]=$field->type;
                $merge_vars[]=$mergefield;
            }
        }
    }
    catch (PodioError $e) {
        $config["meta"]["podio_appname"]="Error with App Id/Token";
        $config["meta"]["podio_spaceid"]="" . $e->body['error_description'];
    }

    return $merge_vars;
}

public static function add_permissions(){
    global $wp_roles;
    $wp_roles->add_cap("administrator", "gravityforms_podio");
    $wp_roles->add_cap("administrator", "gravityforms_podio_uninstall");
}

public static function selected($selected, $current){
    return $selected === $current ? " selected='selected'" : "";
}

//Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
public static function members_get_capabilities( $caps ) {
    return array_merge($caps, array("gravityforms_podio", "gravityforms_podio_uninstall"));
}

public static function disable_podio(){
    delete_option("gf_podio_settings");
}


public static function get_podio_app(){

    check_ajax_referer("gf_get_podio_app", "gf_get_podio_app");
    $appid = absint(rgpost("podio_appid"));     
    $apptoken= rgpost("podio_apptoken");
    $setting_id =  intval(rgpost("setting_id"));

    $api = self::get_api();
    if(!$api)
        die("EndGetApp();");

//getting configuration
    $config = GFPodioData::get_feed($setting_id);
    $config["meta"]["podio_appid"] = $appid;     
    $config["meta"]["podio_apptoken"] = $apptoken;
    $config["meta"]["podio_appname"] = "";     
    $config["meta"]["podio_spaceid"] = "";

    $merge_vars = self::get_PodioAppMergeVars($config);

//getting list of selection fields to be used by the optin
    $json_appname = GFCommon::json_encode($config["meta"]["podio_appname"] );
    $json_spaceid = GFCommon::json_encode($config["meta"]["podio_spaceid"] );

//fields meta
    die("EndGetApp(" . $json_appname . ", " . $json_spaceid . ");");
}
public static function select_podio_form(){

    check_ajax_referer("gf_select_podio_form", "gf_select_podio_form");
    $form_id =  intval(rgpost("form_id"));
    $appid = absint(rgpost("podio_appid"));     
    $apptoken= rgpost("podio_apptoken");

    $setting_id =  intval(rgpost("setting_id"));

    $api = self::get_api();
    if(!$api)
        die("EndSelectForm();");

//getting configuration
    $config = GFPodioData::get_feed($setting_id);
    $config["meta"]["podio_appid"] = absint(rgpost("podio_appid"));     
    $config["meta"]["podio_apptoken"] = rgpost("podio_apptoken");

    $merge_vars = self::get_PodioAppMergeVars($config);

//getting field map UI
    $str = self::get_field_mapping($config, $form_id, $merge_vars);
    $str_json = json_encode($str);

//getting list of selection fields to be used by the optin
    $form_meta = RGFormsModel::get_form_meta($form_id);
    $form_json = GFCommon::json_encode($form_meta);

    $selection_fields = GFCommon::get_selection_fields($form_meta, rgars($config, "meta/optin_field_id"));
    $selection_fields_json = json_encode($selection_fields);

    $group_condition = array();

//fields meta
    die("EndSelectForm(" . $str_json . ", " . $form_json . ");");
}

private static function get_field_mapping($config, $form_id, $merge_vars){

//getting list of all fields for the selected form
    $form_fields = self::get_form_fields($form_id);

    $str = "<table cellpadding='0' cellspacing='0'><tr><td class='podio_col_heading'>" . __("Podio App Fields&nbsp;&nbsp;", "gravityformspodio") . "</td><td class='podio_col_heading'>" . __("Form Fields", "gravityformspodio") . "</td></tr>";
    if(!isset($config["meta"]))
        $config["meta"] = array("field_map" => "");

    foreach($merge_vars as $var){
        $selected_field = rgar($config["meta"]["field_map"], $var["tag"]);
        $required = $var["req"] == true ? "<span class='gfield_required'>*</span>" : "";
        $error_class = $var["req"] == true && empty($selected_field) && !empty($_POST["gf_podio_submit"]) ? " feeds_validation_error" : "";
        $str .= "<tr class='$error_class'><td class='podio_field_cell'>" . $var["name"]  . " $required</td><td class='podio_field_cell'>" . self::get_mapped_field_list($var["tag"], $selected_field, $form_fields) . "</td></tr>";
    }
    $str .= "</table>";

    return $str;
}

public static function get_form_fields($form_id){
    $form = RGFormsModel::get_form_meta($form_id);
    $fields = array();

//Adding default fields
    array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravityformspodio")));
    array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravityformspodio")));
    array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravityformspodio")));
    array_push($form["fields"],array("id" => "form_title" , "label" => __("Form Title", "gravityformspodio")));
    $form = self::get_entry_meta($form);
    if(is_array($form["fields"])){
        foreach($form["fields"] as $field){
            if(is_array(rgar($field, "inputs"))){

//If this is an address field, add full name to the list
                if(RGFormsModel::get_input_type($field) == "address")
                    $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravityformspodio") . ")");

//If this is a name field, add full name to the list
                if(RGFormsModel::get_input_type($field) == "name")
                    $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravityformspodio") . ")");

                if(RGFormsModel::get_input_type($field) == "checkbox")
                {
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                } else
                {

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
            }
            else if(!rgar($field,"displayOnly")){

                $label =  GFCommon::get_label($field);

                if ((RGFormsModel::get_input_type($field) == "email") || strpos(strtolower($label), "mail") !== false)
                {
                    $fields[] =   array($field["id"], " (" . __("Contact" , "gravityformspodio") . ") " . $label);
                }
                else
                {
                    $fields[] =  array($field["id"], $label);
                }
            }
        }
    }
    return $fields;
}

private static function get_entry_meta($form){
    $entry_meta = GFFormsModel::get_entry_meta($form["id"]);
    $keys = array_keys($entry_meta);
    foreach ($keys as $key){
        array_push($form["fields"],array("id" => $key , "label" => $entry_meta[$key]['label']));
    }
    return $form;
}

public static function get_fb_img($fbId){
    $url = 'http://graph.facebook.com/' . $fbId . '/picture?type=large';
    $headers = get_headers($url,1);

$profileimage = $headers['Location']; //image URL

$ext = pathinfo($profileimage, PATHINFO_EXTENSION);
$filename = sys_get_temp_dir() . "/" . $fbId . "." . $ext;

if (file_exists($filename)) 
{
    return $filename;
} else {

    $ch = curl_init($profileimage);
    $fp = fopen( $filename, "wb");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/525.13 (KHTML, wie z. B. Gecko) Chrome/13.0.782.215 Safari/525.13." );
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return $filename;
}
}



private static function get_address($entry, $field_id){
    $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
    $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
    $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
    $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
    $zip_value = trim($entry[$field_id . ".5"]);
    $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

    $address = $street_value;
    $address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
    $address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
    $address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
    $address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
    $address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

    return $address;
}

private static function get_name($entry, $field_id){

//If field is simple (one input), simply return full content
    $name = rgar($entry,$field_id);
    if(!empty($name))
        return $name;

//Complex field (multiple inputs). Join all pieces and create name
    $prefix = trim(rgar($entry,$field_id . ".2"));
    $first = trim(rgar($entry,$field_id . ".3"));
    $last = trim(rgar($entry,$field_id . ".6"));
    $suffix = trim(rgar($entry,$field_id . ".8"));

    $name = $prefix;
    $name .= !empty($name) && !empty($first) ? " $first" : $first;
    $name .= !empty($name) && !empty($last) ? " $last" : $last;
    $name .= !empty($name) && !empty($suffix) ? " $suffix" : $suffix;
    return $name;
}

public static function get_mapped_field_list($variable_name, $selected_field, $fields){
    $field_name = "podio_map_field_" . $variable_name;
    $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
    foreach($fields as $field){
        $field_id = $field[0];
        $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

        $selected = $field_id == $selected_field ? "selected='selected'" : "";
        $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
    }
    $str .= "</select>";
    return $str;
}

public static function export_toPodio($entry, $form, $is_fulfilled = false){

//Login to Podio
    $api = self::get_api();
    if(!$api)
        return;

//loading data class
    require_once(self::get_base_path() . "/data.php");

//getting all active feeds
    $feeds = GFPodioData::get_feed_by_form($form["id"], true);
    foreach($feeds as $feed){
//only export if user has opted in
        if(self::is_optin($form, $feed, $entry))
        {
            if (self::export_feed_toPodio($entry, $form, $feed, $api))
                gform_update_meta($entry["id"], "podio_is_exported", true);
        }
        else
        {
            self::log_debug("Opt-in condition not met; not subscribing entry " . $entry["id"] . " to list");
        }
    }
}

public static function export_feed_toPodio($entry, $form, $feed, $api)
{
    try
    {
        $appid=absint($feed["meta"]["podio_appid"]);
        $apptoken= $feed["meta"]["podio_apptoken"] . "sasdasd";
        $spaceid= $feed["meta"]["podio_spaceid"];

        $merge_vars = array();
        foreach($feed["meta"]["field_map"] as $var_tag => $field_id)
        {
            $field = RGFormsModel::get_field($form, $field_id);
            $input_type = RGFormsModel::get_input_type($field);
            $label = $field["label"];

            if(is_array(rgar($field, "choices")) && $input_type != "list")
            {
                if ($input_type == "checkbox") {
                    $valueArray = array();
                    foreach ($field['choices'] as $key => $choice)
                    {
                        $id = (string)$field['inputs'][$key]['id'];
                        if (isset($entry[$id]) && $entry[$id] != null):
                            $valueArray[] = $choice["text"];
                        endif;
                    }
                    $value = implode(",", $valueArray);
                } else if ($input_type == "radio") {
                    $value = rgar($entry, $field_id);
                    foreach ($field['choices'] as $choice)
                    {
                        if ($choice["value"] == $value)
                        {

                            $value = $choice["text"];
                            break;
                        }
                    }
                } else
                {
                    $value = rgar($entry, $field_id);
                }
            } else
            {
                $value = rgar($entry, $field_id);
            }

            if ((empty($contact_facebook)) && ((strpos(strtolower($var_tag), "facebook") !== false) ||  strpos(strtolower($label), "facebook") !=false))
            {
                $contact_facebook = $value;
            }

            if ((empty($contact_name)) && ((strpos(strtolower($var_tag), "name") !== false) ||  strpos(strtolower($label), "name") !=false))
            {
                $contact_name = $value;
            }

            if ((empty($contact_email)) && ((strpos(strtolower($var_tag), "mail") !== false) ||  strpos(strtolower($label), "mail") !=false))
            {
                $contact_email = $value;
            }

            if ((empty($contact_target_tag)) && ((strpos(strtolower($var_tag), "contact") !== false) ||  strpos(strtolower($label), "mail") !=false))
            {
                $contact_target_tag = $var_tag;
            }

            switch(strtolower($field_id))
            {
                case "date_created" :
                $merge_vars[$var_tag] = rgar($entry, "date_created");
                break;
                case "form_title" :
                $merge_vars[$var_tag] = rgar($form, "title");
                break;
                case "ip" :
                $merge_vars[$var_tag] = rgar($entry, "ip");
                break;
                case "source_url" :
                $merge_vars[$var_tag] = rgar($entry, "source_url");
                break;
                default :
                if($field_id == intval($field_id) && $input_type == "address") 
                    $merge_vars[$var_tag] = self::get_address($entry, $field_id);
                else if($field_id == intval($field_id) && $input_type == "name") 
                {
                    $contact_name = self::get_name($entry, $field_id);
                    $merge_vars[$var_tag] = $contact_name;
                }
                else if($field_id == intval($field_id) && $input_type == "email") 
                {
                    $contact_email = $value;
                    $contact_target_tag = $var_tag;
                             $merge_vars[$var_tag] = $contact_name;
                }
                else if ($field_id == intval($field_id) && $input_type == "phone" && $field["phoneFormat"] == "standard") 
                {
                    $phone = rgar($entry, $field_id);
                    if (preg_match('/^\D?(\d{3})\D?\D?(\d{3})\D?(\d{4})$/', $phone, $matches)){
                        $phone = sprintf("%s-%s-%s", $matches[1], $matches[2], $matches[3]);
                    }
                    $merge_vars[$var_tag] = $phone;
                } else if (!empty($field_id) && !empty($value))
                    $merge_vars[$var_tag] = apply_filters("gform_podio_field_value", $value, $form["id"], $field_id, $entry);
                break;
            }
        }

        if (!empty($contact_target_tag) && empty($contact_name))
        {
              foreach($form["fields"] as $field)
              {
                    $input_type = RGFormsModel::get_input_type($field);
                    $label = $field["label"];
                    $field_id = $field["id"];

                if (($field_id == intval($field_id) && $input_type == "name")  || strpos(strtolower($label), "name") !== false)
                {
                    $contact_name = self::get_name($entry, $field_id);
                    break;
                }
          }

        }


        if (!Podio::is_authenticated())
        {
            Podio::authenticate('app', array(
                'app_id' => $appid,
                'app_token' => $apptoken
                ));
        }

        if (!empty($contact_target_tag))
        {
            $contact_fields = array(
                "name"=>$contact_name,
                "mail"=>array($contact_email)
                );


            if (!empty($contact_facebook))
            {
                $filename = self::get_fb_img($contact_facebook);
                if ($filename)
                {
                    $fid = PodioFile::upload ($filename, $contact_facebook . ".jpg");
                    $contact_fields["avatar"] = ($fid->file_id);
                }
            }

            $existingContacts = PodioContact::get_for_app( $appid, $attributes = array(
                "mail" => array($contact_email),
                "name" => $contact_name
                ) );

            if (count($existingContacts)>0)
            {
                $first =  $existingContacts[0];
                $ep_profile_id = $first->profile_id;

                PodioContact::update( $ep_profile_id, $contact_fields );

            } else
            {
                $ep_profile_id = PodioContact::create( $spaceid, $contact_fields);
            }

            $merge_vars[$contact_target_tag] = $ep_profile_id;
        }
        $retval = PodioItem::create( $appid,  array('fields' => $merge_vars));
        return true;
    } catch (PodioError $e) 
    {
        echo "There was an error. The API responded with the error type " . $e->body['error'] ." and the mesage " . $e->body['error_description'] . ".";
        echo "<script>alert('Unfortunately an error has occured in the submission.  Please see the window for more details');</script>";
        try  
        {
            $title = "Error in Survey Submission";
            if (!empty($contact_name))
                $title = $title . " for " . $contactname;
            PodioTask::create( $attributes = array(
                "text" => $title,
                "description" => $e->body['error'] . " " . $e->body['error_description'])
                , $options = array("silent" => true) );
            return false;
        }  
        catch (PodioError $e2) 
        {
            return false;
        }
    }
}


public static function uninstall(){

//loading data lib
    require_once(self::get_base_path() . "/data.php");

    if(!GFPodio::has_access("gravityforms_podio_uninstall"))
        die(__("You don't have adequate permission to uninstall Podio Add-On.", "gravityformspodio"));

//droping all tables
    GFPodioData::drop_tables();

//removing options
    delete_option("gf_podio_settings");
    delete_option("gf_podio_version");

//Deactivating plugin
    $plugin = "gravityformspodio/podio.php";
    deactivate_plugins($plugin);
    update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
}

public static function is_optin($form, $settings, $entry){
    $config = $settings["meta"];

    $field = RGFormsModel::get_field($form, $config["optin_field_id"]);

    if(empty($field) || !$config["optin_enabled"])
        return true;

    $operator = isset($config["optin_operator"]) ? $config["optin_operator"] : "";
    $field_value = RGFormsModel::get_lead_field_value($entry, $field);
    $is_value_match = RGFormsModel::is_value_match($field_value, $config["optin_value"], $operator);
    $is_visible = !RGFormsModel::is_field_hidden($form, $field, array(), $entry);

    $is_optin = $is_value_match && $is_visible;

    return $is_optin;

}

private static function is_gravityforms_installed(){
    return class_exists("RGForms");
}

private static function is_gravityforms_supported(){
    if(class_exists("GFCommon")){
        $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
        return $is_correct_version;
    }
    else{
        return false;
    }
}

protected static function has_access($required_permission){
    $has_members_plugin = function_exists('members_get_capabilities');
    $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
    if($has_access)
        return $has_members_plugin ? $required_permission : "level_7";
    else
        return false;
}

//Returns the url of the plugin's root folder
protected static function get_base_url(){
    return plugins_url(null, __FILE__);
}

//Returns the physical path of the plugin's root folder
protected static function get_base_path(){
    $folder = basename(dirname(__FILE__));
    return WP_PLUGIN_DIR . "/" . $folder;
}

public static function set_logging_supported($plugins)
{
    $plugins[self::$slug] = "Podio";
    return $plugins;
}

private static function log_error($message){
    if(class_exists("GFLogging"))
    {
        GFLogging::include_logger();
        GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
    }
}

private static function log_debug($message){
    if(class_exists("GFLogging"))
    {
        GFLogging::include_logger();
        GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
    }
}
}

if(!function_exists("rgget")){
    function rgget($name, $array=null){
        if(!isset($array))
            $array = $_GET;

        if(isset($array[$name]))
            return $array[$name];

        return "";
    }
}

if(!function_exists("rgpost")){
    function rgpost($name, $do_stripslashes=true){
        if(isset($_POST[$name]))
            return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

        return "";
    }
}

if(!function_exists("rgar")){
    function rgar($array, $name){
        if(isset($array[$name]))
            return $array[$name];

        return '';
    }
}


if(!function_exists("rgempty")){
    function rgempty($name, $array = null){
        if(!$array)
            $array = $_POST;

        $val = rgget($name, $array);
        return empty($val);
    }
}


if(!function_exists("rgblank")){
    function rgblank($text){
        return empty($text) && strval($text) != "0";
    }
}