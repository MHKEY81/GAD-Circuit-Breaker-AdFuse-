    <?php
    // -------------------------
    // تنظیمات امنیتی و ثابت‌ها
    // -------------------------

    // کلید امنیتی برای صدا زدن این رله از سمت سایت‌ها
    define('RELAY_KEY', '3xAMPLEk3yKARNO-12@');

    // تنظیمات تلگرام
    $bot_token = 'Your bot token';
    $chat_id   = 'Target chat id';

    // تنظیمات سرور Google Ads Proxy
    $ads_base_url = 'Your Server Address';
    $ads_api_key  = 'Your Server Token'; // کلیدی که بهم دادی

    // -------------------------
    // اعتبارسنجی کلید رله
    // -------------------------

    if (!isset($_GET['key']) || $_GET['key'] !== RELAY_KEY) {
        http_response_code(403);
        exit('ACCESS DENIED');
    }

    // -------------------------
    // ورودی‌ها
    // -------------------------

    $action      = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : ''; // enable / disable / ...
    $customer_id = isset($_GET['customer_id']) ? trim($_GET['customer_id']) : '';
    $campaign_id = isset($_GET['campaign_id']) ? trim($_GET['campaign_id']) : '';
    $message     = isset($_GET['msg']) ? trim($_GET['msg']) : 'No message';

    // -------------------------
    // تابع ساده برای درخواست GET
    // -------------------------
    function http_get_simple($url, $timeout = 7) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $response = curl_exec($ch);
        $info     = curl_getinfo($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        return [
            'body'        => $response,
            'http_code'   => isset($info['http_code']) ? $info['http_code'] : 0,
            'curl_error'  => $err,
        ];
    }

    // -------------------------
    // ۱) تماس با سرور Ads (در صورت نیاز)
    // -------------------------

    $ads_result = [
        'called'    => false,
        'endpoint'  => null,
        'http_code' => null,
        'error'     => null,
    ];

    if ($action === 'enable' || $action === 'disable') {

        if ($customer_id === '' || $campaign_id === '') {
            // اگر برای enable/disable شناسه‌ها نیامده باشند
            $ads_result['called'] = false;
            $ads_result['error']  = 'Missing customer_id or campaign_id';
        } else {
            // تعیین endpoint بر اساس اکشن
            // توجه: اگر روی سرورت نام اندپوینت disable چیز دیگه‌ایه، همین‌جا عوضش کن.
            $endpoint = ($action === 'enable') ? '/enable' : '/pause';

            $query = http_build_query([
                'customer_id'  => $customer_id,
                'campaign_id'  => $campaign_id,
                'key'          => $ads_api_key,
            ]);

            $ads_url = rtrim($ads_base_url, '/') . $endpoint . '?' . $query;

            $ads_result['called']   = true;
            $ads_result['endpoint'] = $ads_url;

            $resp = http_get_simple($ads_url);

            $ads_result['http_code'] = $resp['http_code'];
            if ($resp['http_code'] < 200 || $resp['http_code'] >= 300) {
                $ads_result['error'] = 'Ads API request failed: HTTP ' . $resp['http_code'] . ' ' . $resp['curl_error'];
            }
        }
    }

    // -------------------------
    // ۲) جلوگیری از ارسال پیام تکراری به تلگرام (مثل نسخه قبلی)
    // -------------------------

    $message_hash = md5($message);
    $cache_file   = __DIR__ . '/telegram_cache.txt';

    $last_hash = file_exists($cache_file) ? file_get_contents($cache_file) : '';

    if ($last_hash === $message_hash) {
        // همون پیام قبلاً فرستاده شده
        echo json_encode([
            'status'  => 'ok',
            'note'    => 'Duplicate Telegram message ignored.',
            'ads'     => $ads_result,
        ]);
        exit;
    }

    // ذخیره هش جدید
    file_put_contents($cache_file, $message_hash);

    // -------------------------
    // ۳) ارسال پیام به تلگرام
    // -------------------------

    $telegram_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

    $data = [
        'chat_id' => $chat_id,
        'text'    => $message
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $telegram_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tg_response = curl_exec($ch);
    $tg_info     = curl_getinfo($ch);
    $tg_error    = curl_error($ch);
    curl_close($ch);

    // -------------------------
    // ۴) خروجی ساده برای دیباگ
    // -------------------------

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'status'        => 'ok',
        'telegram_http' => isset($tg_info['http_code']) ? $tg_info['http_code'] : 0,
        'telegram_err'  => $tg_error,
        'ads'           => $ads_result,
        'raw_telegram'  => $tg_response,
    ]);
