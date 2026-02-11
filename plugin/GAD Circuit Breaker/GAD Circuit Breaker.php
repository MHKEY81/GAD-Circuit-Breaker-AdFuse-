<?php
/**
 * Plugin Name: GAD Circuit Breaker
 * Description: Monitors Google Ads traffic for click fraud/spikes and automatically triggers a circuit break via Relay API.
 * Version: 1
 * Author: MHKEY
 */


if (!defined('ABSPATH')) exit;

global $wpvt_gads_db_version;
$wpvt_gads_db_version = '1.1';

class GAD_Circuit_Breaker {
    private static $instance = null;
    private $table_name;
    private $option_name = 'wpvt_gads_settings';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wpvt_gads_visits';

        register_activation_hook(__FILE__, array($this, 'on_activate'));
        register_uninstall_hook(__FILE__, array('GAD_Circuit_Breaker', 'on_uninstall'));

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'maybe_save_settings'));
        add_action('template_redirect', array($this, 'track_visit'));
        add_action('wpvt_gads_reenable_campaign', array($this, 'handle_reenable_campaign'), 10, 3);
    }

    public function on_activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_key VARCHAR(190) NOT NULL,
            visit_time DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY campaign_key (campaign_key),
            KEY visit_time (visit_time)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function on_uninstall() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpvt_gads_visits';
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option('wpvt_gads_settings');
    }

    /* ----------------- Admin UI ----------------- */

    public function admin_menu() {
        add_options_page(
            'Anti Attack Alarm - GAds',
            'Anti Attack GAds',
            'manage_options',
            'wpvt-gads-settings',
            array($this, 'settings_page')
        );
    }

private function get_defaults() {
    return array(
        'param_name'      => 'gad_campaignid', // نام پارامتر GET که تو URL هست
        'campaigns'       => "",               // هر خط: campaign_key | campaign_title | id1,id2,...
        'threshold'       => 10,               // آستانه تعداد بازدید
        'window_minutes'  => 5,                // پنجره زمانی (دقیقه)
        'allow_eval'      => 0,                // اجرای کد سفارشی
        'php_code'        => "",               // کد دلخواه

        // ID اکانت (Customer ID در Google Ads)
        'customer_id'     => '',

        // مدت زمان تا دوباره فعال شدن کمپین (دقیقه)
        'reenable_minutes' => 60,

        // Relay API
        'relay_url'       => 'Your Relay URL',
        'relay_key'       => 'Your Relay Token',
        'relay_message'   => 'سایت {site} – کمپین {campaign_title} ({campaign_key}) – {count} ورودی در {window} دقیقه (id={url_param})',

        // پیام وقتی کمپین دوباره فعال می‌شود
        'relay_enable_message' => 'سایت {site} – کمپین {campaign_title} ({campaign_key}) دوباره فعال شد (id={url_param})',
    );
}

    private function get_settings() {
        $defaults = $this->get_defaults();
        $opt = get_option($this->option_name, array());
        return wp_parse_args($opt, $defaults);
    }

    public function maybe_save_settings() {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['wpvt_gads_settings_submit'])) return;
    check_admin_referer('wpvt_gads_save_settings');

    $param_name = isset($_POST['wpvt_gads_param_name']) ? sanitize_text_field($_POST['wpvt_gads_param_name']) : 'gad_campaignid';
    $campaigns  = isset($_POST['wpvt_gads_campaigns']) ? sanitize_textarea_field($_POST['wpvt_gads_campaigns']) : '';
    $threshold  = isset($_POST['wpvt_gads_threshold']) ? intval($_POST['wpvt_gads_threshold']) : 10;
    $window     = isset($_POST['wpvt_gads_window']) ? intval($_POST['wpvt_gads_window']) : 5;
    $allow_eval = isset($_POST['wpvt_gads_allow_eval']) ? 1 : 0;
    $php_code   = isset($_POST['wpvt_gads_php_code']) ? $_POST['wpvt_gads_php_code'] : '';

    // جدید: Customer ID و مدت re-enable
    $customer_id       = isset($_POST['wpvt_gads_customer_id']) ? sanitize_text_field($_POST['wpvt_gads_customer_id']) : '';
    $reenable_minutes  = isset($_POST['wpvt_gads_reenable_minutes']) ? intval($_POST['wpvt_gads_reenable_minutes']) : 60;

    $relay_url  = isset($_POST['wpvt_gads_relay_url']) ? esc_url_raw($_POST['wpvt_gads_relay_url']) : '';
    $relay_key  = isset($_POST['wpvt_gads_relay_key']) ? sanitize_text_field($_POST['wpvt_gads_relay_key']) : '';
    $relay_msg  = isset($_POST['wpvt_gads_relay_message']) ? sanitize_text_field($_POST['wpvt_gads_relay_message']) : '';

    // جدید: پیام فعال‌سازی مجدد
    $relay_enable_msg = isset($_POST['wpvt_gads_relay_enable_message']) ? sanitize_text_field($_POST['wpvt_gads_relay_enable_message']) : '';

    if (!$allow_eval) $php_code = '';

    $settings = array(
        'param_name'     => $param_name,
        'campaigns'      => $campaigns,
        'threshold'      => max(1, $threshold),
        'window_minutes' => max(1, $window),
        'allow_eval'     => $allow_eval,
        'php_code'       => $php_code,

        'customer_id'     => $customer_id,
        'reenable_minutes'=> max(0, $reenable_minutes),

        'relay_url'      => $relay_url,
        'relay_key'      => $relay_key,
        'relay_message'  => $relay_msg,
        'relay_enable_message' => $relay_enable_msg,
    );

    update_option($this->option_name, $settings);
    add_settings_error('wpvt_gads_messages', 'wpvt_gads_saved', 'تنظیمات ذخیره شد.', 'updated');
    wp_redirect(admin_url('options-general.php?page=wpvt-gads-settings&updated=true'));
    exit;
}


    public function settings_page() {
        if (!current_user_can('manage_options')) wp_die('Not allowed');
        $s = $this->get_settings();
        settings_errors('wpvt_gads_messages'); ?>
        <div class="wrap">
            <h1>GAD Circuit Breaker</h1>
            <form method="post">
                <?php wp_nonce_field('wpvt_gads_save_settings'); ?>
                <table class="form-table">
                    <tr>
    <th scope="row"><label for="wpvt_gads_customer_id">Customer ID (Account ID)</label></th>
    <td>
        <input name="wpvt_gads_customer_id" id="wpvt_gads_customer_id" type="text" value="<?php echo esc_attr($s['customer_id']); ?>">
        <p class="description">همون Customer ID اکانت Google Ads (برای همه کمپین‌ها مشترک است).</p>
    </td>
</tr>
<tr>
    <th scope="row"><label for="wpvt_gads_reenable_minutes">مدت زمان تا فعال‌سازی مجدد کمپین (دقیقه)</label></th>
    <td>
        <input name="wpvt_gads_reenable_minutes" id="wpvt_gads_reenable_minutes" type="number" min="0" value="<?php echo esc_attr($s['reenable_minutes']); ?>">
        <p class="description">مثلاً 60 یعنی کمپین بعد از 60 دقیقه دوباره enable شود. اگر صفر بگذاری، کمپین خودکار فعال نمی‌شود.</p>
    </td>
</tr>
<tr>
    <th scope="row">Relay API (هاست خارج)</th>
    <td>
        <label for="wpvt_gads_relay_url">Relay URL</label><br>
        <input name="wpvt_gads_relay_url" id="wpvt_gads_relay_url" type="text" style="width:80%" value="<?php echo esc_attr($s['relay_url']); ?>"><br><br>

        <label for="wpvt_gads_relay_key">Key</label><br>
        <input name="wpvt_gads_relay_key" id="wpvt_gads_relay_key" type="text" style="width:50%" value="<?php echo esc_attr($s['relay_key']); ?>"><br><br>

        <label for="wpvt_gads_relay_message">پیام آلارم (قابل استفاده: {site}, {campaign_key}, {campaign_title}, {count}, {window}, {url_param})</label><br>
        <input name="wpvt_gads_relay_message" id="wpvt_gads_relay_message" type="text" style="width:90%" value="<?php echo esc_attr($s['relay_message']); ?>"><br><br>

        <label for="wpvt_gads_relay_enable_message">پیام هنگام فعال‌سازی مجدد کمپین</label><br>
        <input name="wpvt_gads_relay_enable_message" id="wpvt_gads_relay_enable_message" type="text" style="width:90%" value="<?php echo esc_attr($s['relay_enable_message']); ?>">
        <p class="description">قابل استفاده: {site}, {campaign_key}, {campaign_title}, {url_param}. اگر {count} یا {window} استفاده شود، مقدار 0 و پنجره فعلی گذاشته می‌شود.</p>
    </td>
</tr>

                    <tr>
                        <th scope="row"><label for="wpvt_gads_param_name">نام پارامتر کمپین در URL</label></th>
                        <td>
                            <input name="wpvt_gads_param_name" id="wpvt_gads_param_name" type="text" value="<?php echo esc_attr($s['param_name']); ?>">
                            <p class="description">مثال: <code>gad_campaignid</code> یا هر پارامتری که ID کمپین/ادگروپ داخلش می‌آید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpvt_gads_campaigns">کمپین‌ها (هر خط یکی)</label></th>
                        <td>
                            <textarea name="wpvt_gads_campaigns" id="wpvt_gads_campaigns" rows="8" cols="80"><?php echo esc_textarea($s['campaigns']); ?></textarea>
                          <p class="description">
    فرمت هر خط:<br>
    <code>campaign_key | عنوان کمپین | id1,id2,id3 | threshold(اختیاری)</code><br>
    مثال بدون آستانه اختصاصی (استفاده از آستانه کلی):<br>
    <code>fridge_samsung | کمپین یخچال سامسونگ | 23071363905,23071364000</code><br>
    مثال با آستانه اختصاصی (مثلاً 20 بازدید در بازه زمانی):<br>
    <code>fridge_samsung | کمپین یخچال سامسونگ | 23071363905,23071364000 | 20</code>
</p>

                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpvt_gads_threshold">آستانه (تعداد بازدید در پنجره زمانی)</label></th>
                        <td><input name="wpvt_gads_threshold" id="wpvt_gads_threshold" type="number" min="1" value="<?php echo esc_attr($s['threshold']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpvt_gads_window">پنجره زمانی (دقیقه)</label></th>
                        <td><input name="wpvt_gads_window" id="wpvt_gads_window" type="number" min="1" value="<?php echo esc_attr($s['window_minutes']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">اجرای کد PHP (خطرناک!)</th>
                        <td>
                            <label><input type="checkbox" name="wpvt_gads_allow_eval" value="1" <?php checked($s['allow_eval'], 1); ?>> اجازه اجرای کد زیر</label>
                            <p class="description">متغیرهای قابل‌استفاده: <code>$campaign_key</code>، <code>$campaign_title</code>، <code>$count</code>، <code>$url_param</code>.</p>
                            <textarea name="wpvt_gads_php_code" rows="8" cols="80"><?php echo esc_textarea($s['php_code']); ?></textarea>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="wpvt_gads_settings_submit" class="button button-primary" value="ذخیره تنظیمات"></p>
            </form>

            <h2>هوک توسعه‌دهندگان</h2>
            <pre><code>add_action('wpvt_gads_threshold_reached', function($campaign_key, $campaign_title, $count, $url_param){
    // اینجا می‌تونی لاگ کنی، ایمیل بزنی، فایروال صدا بزنی و ...
});</code></pre>
        </div>
        <?php
    }

    /* ----------------- Helpers ----------------- */

private function parse_campaigns_config($raw) {
    $lines = preg_split('/\r?\n/', trim($raw));
    $campaigns = array();

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // campaign_key | title | id1,id2,id3 | threshold(اختیاری)
        $parts = explode('|', $line);
        if (count($parts) < 3) continue;

        $key     = trim($parts[0]);
        $title   = trim($parts[1]);
        $ids_raw = trim($parts[2]);

        if ($key === '' || $ids_raw === '') continue;

        $ids_array = array();
        foreach (explode(',', $ids_raw) as $id) {
            $id = trim($id);
            if ($id === '') continue;
            $ids_array[] = $id;
        }
        if (empty($ids_array)) continue;

        // قسمت چهارم = threshold اختیاری
        $threshold = null;
        if (isset($parts[3])) {
            $th = intval(trim($parts[3]));
            if ($th > 0) {
                $threshold = $th;
            }
        }

        $campaigns[] = array(
            'key'       => $key,
            'title'     => $title,
            'ids'       => $ids_array,
            'threshold' => $threshold,
        );
    }

    return $campaigns;
}



    private function send_relay_alert($url, $key, $message, $extra_params = array()) {
    if (empty($url) || empty($key) || empty($message)) return false;

    // پارامترهای پایه
    $args = array(
        'key' => $key,
        'msg' => $message,
    );

    // پارامترهای اضافه مثل action, customer_id, campaign_id
    if (is_array($extra_params) && !empty($extra_params)) {
        $args = array_merge($args, $extra_params);
    }

    $final_url = add_query_arg($args, $url);

    $resp = wp_remote_get($final_url, array('timeout' => 7));
    if (is_wp_error($resp)) {
        error_log('WPVT GAds relay error: ' . $resp->get_error_message());
        return false;
    }
    $code = wp_remote_retrieve_response_code($resp);
    return ($code >= 200 && $code < 300);
}


    private function build_message($template, $campaign_key, $campaign_title, $count, $window, $url_param) {
        $replacements = array(
            '{site}'           => get_bloginfo('name'),
            '{campaign_key}'   => $campaign_key,
            '{campaign_title}' => $campaign_title,
            '{count}'          => $count,
            '{window}'         => $window,
            '{url_param}'      => $url_param,
        );
        return strtr($template, $replacements);
    }

    /* ----------------- Tracking ----------------- */
public function handle_reenable_campaign($campaign_key, $campaign_title, $url_param) {
    $settings = $this->get_settings();

    $relay_url  = $settings['relay_url'];
    $relay_key  = $settings['relay_key'];
    $customer_id = isset($settings['customer_id']) ? trim($settings['customer_id']) : '';

    if (empty($relay_url) || empty($relay_key) || empty($customer_id)) {
        return;
    }

    // پیام فعال‌سازی مجدد
    $template = !empty($settings['relay_enable_message'])
        ? $settings['relay_enable_message']
        : 'سایت {site} – کمپین {campaign_title} ({campaign_key}) دوباره فعال شد (id={url_param})';

    // اینجا count=0 و window از تنظیمات فعلی
    $window = intval($settings['window_minutes']);
    $msg = $this->build_message(
        $template,
        $campaign_key,
        $campaign_title,
        0,
        $window,
        $url_param
    );

    // صدا زدن رله با action=enable
    $this->send_relay_alert(
        $relay_url,
        $relay_key,
        $msg,
        array(
            'action'      => 'enable',
            'customer_id' => $customer_id,
            'campaign_id' => $url_param,
        )
    );

    /**
     * هوک توسعه‌دهندگان بعد از فعال‌سازی مجدد کمپین
     */
    do_action('wpvt_gads_campaign_reenabled', $campaign_key, $campaign_title, $url_param);
}




public function track_visit() {
    // فقط پنل ادمین را نادیده بگیر، بقیه کل فرانت‌اند سایت چک بشه
    if ( is_admin() ) {
        return;
    }

    global $wpdb;
    $settings = $this->get_settings();

    $param_name = $settings['param_name'];
    if (empty($param_name)) return;

    // لیست کمپین‌ها از تنظیمات
    $campaigns = $this->parse_campaigns_config($settings['campaigns']);
    if (empty($campaigns)) return;

    // مقدار پارامتر (مثلاً gad_campaignid) از URL
    $url_param = isset($_GET[$param_name]) ? sanitize_text_field(wp_unslash($_GET[$param_name])) : '';
    if ($url_param === '') return;

    // پیدا کردن کمپینی که این ID زیرمجموعه‌اش است
    $matched_campaign = null;
    foreach ($campaigns as $c) {
        if (in_array($url_param, $c['ids'], true)) {
            $matched_campaign = $c;
            break;
        }
    }
    if (!$matched_campaign) return;

    $campaign_key   = $matched_campaign['key'];
    $campaign_title = $matched_campaign['title'];

    // پنجره زمانی
    $window            = intval($settings['window_minutes']);
    $default_threshold = intval($settings['threshold']);

    // اگه برای خود کمپین threshold ست شده بود، از همون استفاده کن؛
    $threshold = $default_threshold;
    if (isset($matched_campaign['threshold']) && intval($matched_campaign['threshold']) > 0) {
        $threshold = intval($matched_campaign['threshold']);
    }

    $now = current_time('mysql', 1); // GMT

    // ثبت بازدید برای این کمپین
    $wpdb->insert(
        $this->table_name,
        array('campaign_key' => $campaign_key, 'visit_time' => $now),
        array('%s', '%s')
    );

    // پاکسازی بازدیدهای قدیمی
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($window * 60));
    $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE visit_time < %s", $cutoff));

    // شمارش بازدیدهای اخیر همین کمپین
    $count = intval($wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE campaign_key = %s AND visit_time >= %s",
            $campaign_key,
            $cutoff
        )
    ));

    // جلوگیری از تریگر چندباره در همان پنجره
    $transient_key = 'wpvt_gads_triggered_' . md5($campaign_key);
    $already = get_transient($transient_key);

    if ($count >= $threshold && !$already) {
        // جلوگیری از تریگر دوباره در همین بازه
        set_transient($transient_key, 1, $window * 60);

        /**
         * هوک توسعه‌دهندگان
         *
         * @param string $campaign_key
         * @param string $campaign_title
         * @param int    $count
         * @param string $url_param
         */
        do_action('wpvt_gads_threshold_reached', $campaign_key, $campaign_title, $count, $url_param);

        // ۱) ساخت پیام آلارم (مثل قبل)
        $msg = $this->build_message(
            $settings['relay_message'],
            $campaign_key,
            $campaign_title,
            $count,
            $window,
            $url_param
        );

        // Customer ID از تنظیمات
        $customer_id = isset($settings['customer_id']) ? trim($settings['customer_id']) : '';

        // ۲) صدا زدن Relay با action=disable + ارسال تلگرام
        $extra_params = array();
        if (!empty($customer_id)) {
            $extra_params = array(
                'action'      => 'disable',
                'customer_id' => $customer_id,
                'campaign_id' => $url_param, // همون ID که از URL اومده و تو کانفیگ مپ شده
            );
        }

        $this->send_relay_alert(
            $settings['relay_url'],
            $settings['relay_key'],
            $msg,
            $extra_params
        );

        // ۳) زمان‌بندی فعال‌سازی مجدد کمپین (enable) با WP-Cron
        $reenable_minutes = isset($settings['reenable_minutes']) ? intval($settings['reenable_minutes']) : 0;

        if ($reenable_minutes > 0 && !empty($customer_id)) {
            $timestamp = time() + ($reenable_minutes * 60);

            wp_schedule_single_event(
                $timestamp,
                'wpvt_gads_reenable_campaign',
                array($campaign_key, $campaign_title, $url_param)
            );
        }

        // ۴) اجرای eval (مثل قبل، اگر فعال باشد)
        if (!empty($settings['allow_eval']) && !empty($settings['php_code'])) {
            $php = $settings['php_code'];
            try {
                $count_local           = $count;
                $campaign_key_local    = $campaign_key;
                $campaign_title_local  = $campaign_title;
                $url_param_local       = $url_param;
                eval($php);
            } catch (\Throwable $e) {
                error_log('WPVT GAds eval error: ' . $e->getMessage());
            }
        }
    }
}
}

GAD_Circuit_Breaker::instance();

?>
