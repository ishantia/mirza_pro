<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');
ini_set('error_log', 'error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
$setting = select('setting', '*');

$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE type = 'marzban' ORDER BY RAND() LIMIT 25");
$stmt->execute();

while ($panel = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!isset($panel['name_panel'])) {
        continue;
    }

    $usersResponse = getusers($panel['name_panel'], 'on_hold');
    if (!is_array($usersResponse)) {
        error_log('on_hold: Unexpected response when fetching users for panel ' . $panel['name_panel']);
        continue;
    }

    if (isset($usersResponse['error'])) {
        error_log('on_hold: Failed to fetch users for panel ' . $panel['name_panel'] . ' - ' . $usersResponse['error']);
        continue;
    }

    if (empty($usersResponse['users']) || !is_array($usersResponse['users'])) {
        continue;
    }

    foreach ($usersResponse['users'] as $user) {
        if (!is_array($user) || empty($user['username'])) {
            continue;
        }

        $invoice = select('invoice', '*', 'username', $user['username'], 'select');
        if (!$invoice || !is_array($invoice)) {
            continue;
        }

        if (($invoice['Status'] ?? null) === 'send_on_hold') {
            continue;
        }

        $line = $invoice['username'] ?? null;
        if ($line === null) {
            continue;
        }

        $timeSincePurchase = (time() - (int) ($invoice['time_sell'] ?? 0)) / 86400;
        if ($timeSincePurchase < (int) ($setting['on_hold_day'] ?? 0)) {
            continue;
        }

        $sql = "SELECT * FROM service_other WHERE username = :username AND type = 'change_location'";
        $checkStmt = $pdo->prepare($sql);
        $checkStmt->bindParam(':username', $line, PDO::PARAM_STR);
        $checkStmt->execute();
        if ($checkStmt->rowCount() !== 0) {
            continue;
        }

        if (!in_array($user['status'] ?? '', ['on_hold'], true)) {
            continue;
        }

        $supportId = $setting['id_support'] ?? '';
        $text = "سلام! 🌐\n\n" .
            "دیدیم که شما هنوز به کانفیگ خود با نام کاربری {$line} متصل نشده‌اید و بیش از {$setting['on_hold_day']} روز از فعال‌سازی آن " .
            "گذشته است. اگر در راه‌اندازی یا استفاده از سرویس مشکلی دارید، لطفاً با تیم پشتیبانی ما از طریق آیدی زیر در ارتباط باشید تا به شما کمک کنیم.\n" .
            "ما آماده‌ایم تا هر گونه سوال یا مشکلی را برطرف کنیم! 📞\n\n" .
            "اکانت پشتیبانی : @{$supportId}";

        sendmessage($invoice['id_user'], $text, null, 'HTML');
        update('invoice', 'Status', 'send_on_hold', 'username', $line);
    }
}
