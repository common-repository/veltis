<?php
/**
 * @package: veltis-plugin
 */

/**
 * Plugin Name: Veltis Social Proof App
 * Description: Social Proof Notifications App - We help your visitors feel confident about their buying or sign-up decisions. Veltis displays recent visitor and customer actions on your website to gain trust, credibility and boost conversion
 * Version: 1.1.0
 * Author: Veltis
 * Author URI: https://veltis.io
 * License: GPLv3 or later
 * Text Domain: veltis-plugin
 */

if (!defined('ABSPATH')) {
    die;
}

class VeltisVariables
{
    public static function options_group()
    {
        return 'veltis_options';
    }

    public static function option_api_key() {
        return 'veltis_api_key';
    }

    public static function host() {
        return 'https://app.veltis.io';
    }
}

add_action('admin_menu', 'veltis_menu_admin_element');
add_action('admin_init', 'veltis_admin_main_initialize');
add_action('admin_notices', 'veltis_admin_notification');
add_action('wp_head', 'veltis_main_code');

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('woocommerce_checkout_order_processed', 'veltis_order');
} else {
    add_action('user_register', 'veltis_register_handler', 999);
}

function veltis_admin_main_initialize()
{
    wp_enqueue_style('veltis_styles_admin', plugin_dir_url(__FILE__).'style.css');
    register_setting(VeltisVariables::options_group(), VeltisVariables::option_api_key());
    wp_register_style('dashicons-veltis', plugin_dir_url(__FILE__).'/assets/css/veltis-ico.css');
    wp_enqueue_style('dashicons-veltis');
}

function veltis_main_code()
{
    $veltisApiKey = veltis_api_key();
    ?>

    <script async src="https://app.veltis.io/uploads/veltis.js"></script>
    <script>
        window.veltisData = window.veltisData || [];
        function veltisTag() { veltisData.push(arguments); }
        veltisTag('js', new Date());
        veltisTag('config', '<?php echo $veltisApiKey; ?>');
    </script>

    <?php
}

function veltis_order($id) {
    try {
        $order = wc_get_order($id);
        $items = $order->get_items();
        $products = array();
        foreach ($items as $item) {
            $quantity = $item->get_quantity();
            $product = $item->get_product();
            $images_arr = wp_get_attachment_image_src($product->get_image_id(), array('90', '90'), false);
            $image = null;
            if ($images_arr !== null && $images_arr[0] !== null) {
                $image = $images_arr[0];
                if (is_ssl()) {
                    $image = str_replace('http', 'https', $image);
                }
            }
            $p = array(
                'id' => $product->get_id(),
                'quantity' => (int) $quantity,
                'price' => (int) $product->get_price(),
                'name' => $product->get_name(),
                'link' => get_permalink($product->get_id()),
                'image' => $image,
            );
            array_push($products, $p);
        }
        send_orders_data_to_veltis($id, $order, $products);
    } catch(Exception $err) {

    }
}

function veltis_woo_register_handler($id, $data)
{
    try {
        $meta = get_user_meta($id);
        veltis_send_data_user(sanitize_text_field($data['user_email']), $meta);
    } catch(Exception $err) {
        $msg = 'Woo Register Error';
        veltis_error_handler($msg, $err, $data);
    }
}

function veltis_register_handler($id)
{
    if ( class_exists( 'WooCommerce' ) ) {
        return;
    }

    try {
        $user = new WP_User($id);
        $meta = get_user_meta($id);

        veltis_send_data_user($user->user_email, $meta);
    } catch(Exception $err) {
        $msg = 'WP register Error';
        veltis_error_handler($msg, $err, ['id' => $id]);
    }
}

function send_orders_data_to_veltis($orderId, $order, $products)
{
    $veltisApiKey = veltis_api_key();
    if ($veltisApiKey == null) {
        return;
    }

    $firstName = $order->get_billing_first_name();
    $lastName = $order->get_billing_last_name();
    $city = $order->get_billing_city();
    $email = $order->get_billing_email();

    $l_asJson = array(
        'orderId' => $orderId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'city' => $city,
        'ip' => $order->get_customer_ip_address(),
        'transaction_id' => $order->get_id(),
        'siteUrl' => get_site_url(),
        'country_code' => $order->get_billing_country(),
        'total' => (int) $order->get_total(),
        'currency' => $order->get_currency(),
        'products' => $products,
    );
    $l_sUrl = VeltisVariables::host() . '/webhook/'.$veltisApiKey;


    $body = wp_json_encode($l_asJson);

    $options = [
        'body'        => $body,
        'headers'     => [
            'Content-Type' => 'application/json',
        ],
        'timeout'     => 60,
        'redirection' => 3,
        'blocking'    => true,
        'httpversion' => '1.0',
        'sslverify'   => false,
        'data_format' => 'body',
    ];

    return wp_remote_post( $l_sUrl, $options );
}

function veltis_error_handler($message, $err, $data = null)
{
    $veltisApiKey = veltis_api_key();
    if ($veltisApiKey == null) {
        return;
    }

    $l_asJson = array(
        'message' => $message,
        'err' => veltis_exception($err),
        'data' => $data,
    );

    $l_sUrl = VeltisVariables::host() . '/v2/api/ext/data/site/error/'.$veltisApiKey;

    $body = wp_json_encode($l_asJson);

    $options = [
        'body'        => $body,
        'headers'     => [
            'Content-Type' => 'application/json',
        ],
        'timeout'     => 60,
        'redirection' => 3,
        'blocking'    => true,
        'httpversion' => '1.0',
        'sslverify'   => false,
        'data_format' => 'body',
    ];

    return wp_remote_post( $l_sUrl, $options );
}

function veltis_api_key() {
    $veltisApiKey = get_option(VeltisVariables::option_api_key());
    return $veltisApiKey;
}


function veltis_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $veltisApiKey = veltis_api_key(); ?>

    <div class="wrap" id="veltis-settings">
        <a href="https://veltis.io">
            <img id="veltis-logo" src="<?php echo plugin_dir_url(__FILE__).'assets/logo-veltis-1.png'; ?>">
        </a>
        <form action="options.php" method="post">
            <?php
            settings_fields(VeltisVariables::options_group());
            do_settings_sections(VeltisVariables::options_group());
            ?>
            <div class="veltis-settings-container">
                <?php if ($veltisApiKey != null) { ?>
                    <div class="veltis-success">Veltis Social Proof Notifications are configured properly!</div>
                    <div class="veltis-info">If you still see <strong>"your site is not configured properly..."</strong> then open your website in <strong>incognito mode</strong> or <strong>clear cache</strong></div>
                <?php } else { ?>
                    <div class="veltis-error">Please enter your site API Key. </div>

                    <div class="veltis-register">Don't have an account yet? Please register: <a href="https://app.veltis.io/register">https://app.veltis.io/register</a> </div>

                <?php } ?>

                <div class="label">Your API Key:</div>
                <input type="text" placeholder="required" name="<?php echo VeltisVariables::option_api_key(); ?>" value="<?php echo esc_attr($veltisApiKey); ?>" />
                <br/>
                <br/>
                <a href="https://veltis.io/how-to-install-wordpress-plugin/" style="color: orange" target="_blank">Where is my API key?</a>
            </div>

            <div style="margin-left:20px;">
                <?php submit_button('Save'); ?>
            </div>
        </form>
    </div>

    <?php
}

function veltis_admin_notification()
{
    $veltisApiKey = veltis_api_key();
    if ($veltisApiKey != null) {
        return;
    }

    ?>
    <div class="notice notice-error is-dismissible">
        <p class="ps-error">Veltis is not configured properly! <a href="admin.php?page=veltis">Click here</a></p>
    </div>

    <?php
}

function veltis_menu_admin_element()
{
    add_menu_page('Veltis Settings', 'Veltis', 'manage_options', 'veltis', 'veltis_settings_page', 'dashicons-veltis');
}

function veltis_exception($err) {
    if(!isset($err) || is_null($err)) {
        return [];
    }
    return [
        'message' => $err->getMessage(),
        'file' => $err->getFile() . ':' . $err->getLine(),
        'code' => $err->getCode(),
    ];
}

function veltis_send_data_user($email, $meta)
{
    $veltisApiKey = veltis_api_key();
    if ($veltisApiKey == null) {
        return;
    }

    $data = array(
        'email' => $email,
        'siteUrl' => get_site_url(),
    );

    if (isset($meta['first_name'][0]) && strlen($meta['first_name'][0]) > 0) {
        $data['first_name'] = $meta['first_name'][0];
    } elseif (isset($_POST['first_name']) && strlen($_POST['first_name']) > 0) {
        $data['first_name'] = sanitize_text_field($_POST['first_name']);
    } elseif(isset($meta['nickname'][0]) and strlen($meta['nickname'][0]) > 0) {
        $data['first_name'] = $meta['nickname'][0];
    } elseif (isset($_POST['nickname']) && strlen($_POST['nickname']) > 0) {
        $data['first_name'] = sanitize_text_field($_POST['nickname']);
    } elseif(isset($meta['user_login'][0]) and strlen($meta['user_login'][0]) > 0) {
        $data['first_name'] = $meta['user_login'][0];
    } elseif (isset($_POST['user_login']) && strlen($_POST['user_login']) > 0) {
        $data['first_name'] = sanitize_text_field($_POST['user_login']);
    }

    if (isset($meta['last_name'][0])) {
        $data['last_name'] = $meta['last_name'][0];
    } elseif (isset($_POST['last_name']) && strlen($_POST['last_name']) > 0) {
        $data['last_name'] = sanitize_text_field($_POST['last_name']);
    }

    if (isset($_SERVER['REMOTE_ADDR'])) {
        $data['ip'] = sanitize_text_field($_SERVER['REMOTE_ADDR']);
    }

    $l_sUrl = VeltisVariables::host() . '/webhook/'.$veltisApiKey;

    $body = wp_json_encode($data);

    $options = [
        'body'        => $body,
        'headers'     => [
            'Content-Type' => 'application/json',
        ],
        'timeout'     => 60,
        'redirection' => 3,
        'blocking'    => true,
        'httpversion' => '1.0',
        'sslverify'   => false,
        'data_format' => 'body',
    ];

    wp_remote_post( $l_sUrl, $options );
}

?>
