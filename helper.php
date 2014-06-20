
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