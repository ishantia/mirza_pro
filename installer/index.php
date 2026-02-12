<?php
$uPOST = sanitizeInput($_POST);
$rootDirectory = dirname(__DIR__).'/';
$configDirectory = $rootDirectory.'config.php';
$tablesDirectory = $rootDirectory.'table.php';
if(!file_exists($configDirectory) || !file_exists($tablesDirectory)) {
    $ERROR[] = "فایل های پروژه ناقص هستند.";
    $ERROR[] = "فایل های پروژه را مجددا دانلود و بارگذاری کنید (<a href='https://github.com/ishantia/mirza_pro/releases/'>‎🌐 Github</a>)";
}
if(phpversion() < 8.2){
    $ERROR[] = "نسخه PHP شما باید حداقل 8.2 باشد.";
    $ERROR[] = "نسخه فعلی: ".phpversion();
    $ERROR[] = "لطفا نسخه PHP خود را به 8.2 یا بالاتر ارتقا دهید.";
}

$tempPath = dirname(dirname($_SERVER['SCRIPT_NAME']));

$tempPath = str_replace('//', '/', '/' . trim($tempPath, '/'));
$webAddress = rtrim($_SERVER['HTTP_HOST'] . $tempPath, '/') . '/';
$success = false;
$tgBot = [];
$botFirstMessage = '';
$installType = $uPOST['install_type'] ?? 'simple';
$serverType = $uPOST['server_type'] ?? 'cpanel';
$hasDbBackup = $uPOST['has_db_backup'] ?? 'no';
$currentStep = isset($uPOST['current_step']) ? (int)$uPOST['current_step'] : 1;
$installFieldTotal = 7;
$currentInstallField = isset($uPOST['current_install_field']) ? (int)$uPOST['current_install_field'] : 1;
$currentStep = max(1, min(3, $currentStep));
$currentInstallField = max(1, min($installFieldTotal, $currentInstallField));
function needsBackupUpload($installType, $hasDbBackup) {
    if ($installType == 'simple') return false;
    if (($installType == 'migrate_free_to_pro') && $hasDbBackup == 'yes') return false;
    return (($installType == 'migrate_free_to_pro') && $hasDbBackup == 'no');
}
function needsMigration($installType) {
    return ($installType == 'migrate_free_to_pro');
}
function isHttps() {
    return (
        ($_SERVER['REQUEST_SCHEME'] ?? 'http') === 'https' ||
        ($_SERVER['HTTPS'] ?? 'off') === 'on' ||
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    );
}
function addFieldToTable($tableName, $fieldName, $defaultValue, $fieldType = 'VARCHAR(100)', $connect) {
    $check = $connect->query("SHOW COLUMNS FROM `$tableName` LIKE '$fieldName'");
    if ($check->num_rows == 0) {
        $default = ($defaultValue === null || $defaultValue === 'null') ? 'NULL' : "'$defaultValue'";
        $sql = "ALTER TABLE `$tableName` ADD `$fieldName` $fieldType DEFAULT $default";
        return $connect->query($sql);
    }
    return true;
}
function cleanSQLContent($sql) {
    $sql = str_replace("\xEF\xBB\xBF", '', $sql);
    $problematicStatements = [
        '/SET\s+@saved_cs_client\s*=\s*@@character_set_client\s*;/i',
        '/SET\s+character_set_client\s*=\s*@saved_cs_client\s*;/i',
        '/SET\s+character_set_client\s*=\s*NULL\s*;/i',
        '/SET\s+@OLD_CHARACTER_SET_CLIENT\s*=\s*@@CHARACTER_SET_CLIENT\s*;/i',
        '/SET\s+@OLD_CHARACTER_SET_RESULTS\s*=\s*@@CHARACTER_SET_RESULTS\s*;/i',
        '/SET\s+@OLD_COLLATION_CONNECTION\s*=\s*@@COLLATION_CONNECTION\s*;/i',
        '/SET\s+NAMES\s+NULL\s*;/i',
        '/SET\s+CHARACTER_SET_CLIENT\s*=\s*@OLD_CHARACTER_SET_CLIENT\s*;/i',
        '/SET\s+CHARACTER_SET_RESULTS\s*=\s*@OLD_CHARACTER_SET_RESULTS\s*;/i',
        '/SET\s+COLLATION_CONNECTION\s*=\s*@OLD_COLLATION_CONNECTION\s*;/i',
        '/\/\*!40101\s+SET\s+@OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT\s+\*\/\s*;/i',
        '/\/\*!40101\s+SET\s+@OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS\s+\*\/\s*;/i',
        '/\/\*!40101\s+SET\s+@OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION\s+\*\/\s*;/i',
        '/\/\*!40101\s+SET\s+NAMES\s+utf8\s+\*\/\s*;/i',
        '/\/\*!40101\s+SET\s+CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT\s+\*\/\s*;/i',
        '/\/\*!40101\s+SET\s+CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS\s+\*\/\s*;/i',
        '/\/\*!40101\s+SET\s+COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION\s+\*\/\s*;/i',
        '/\/\*!40101\s+SET\s+NAMES\s+utf8mb4\s+\*\/\s*;/i',
    ];
    foreach($problematicStatements as $pattern) {
        $sql = preg_replace($pattern, '', $sql);
    }
    $charset_declaration = "/*!40101 SET NAMES utf8mb4 */;\n";
    $sql = $charset_declaration . $sql;
    return $sql;
}
function splitSQLQueries($sql) {
    $queries = [];
    $currentQuery = '';
    $inString = false;
    $stringChar = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($char === "'" || $char === '"') {

            $backslashCount = 0;
            for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                $backslashCount++;
            }
            $escaped = ($backslashCount % 2) === 1; 

            if (!$escaped) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                }
            }
        }

        if (!$inString) {

            if ($char === '-' && $i + 1 < $length && $sql[$i+1] === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($char === '/' && $i + 1 < $length && $sql[$i+1] === '*') {
                $i += 2;
                while ($i < $length && !($sql[$i] === '*' && $i + 1 < $length && $sql[$i+1] === '/')) {
                    $i++;
                }

                if ($i < $length) {
                    $i++;
                }
                continue;
            }
        }

        $currentQuery .= $char;

        if ($char === ';' && !$inString) {
            $trimmedQuery = trim($currentQuery);
            if (!empty($trimmedQuery) && $trimmedQuery !== ';') {
                $queries[] = $trimmedQuery;
            }
            $currentQuery = '';
        }
    }

    $trimmedQuery = trim($currentQuery);
    if (!empty($trimmedQuery) && $trimmedQuery !== ';') {
        $queries[] = $trimmedQuery;
    }

    return $queries;
}

function dropExistingTables($mysqli, $dbName, $logHandle, &$ERROR = null) {
    $log = function($message) use ($logHandle) {
        if ($logHandle) {
            $timestamp = date('Y-m-d H:i:s');
            fwrite($logHandle, "[{$timestamp}] {$message}\n");
        }
    };
    $log("\n🧨 حذف جداول و داده‌های فعلی...");
    if(!$mysqli->query("SET FOREIGN_KEY_CHECKS=0")) {
        $log("❌ عدم توانایی غیرفعال کردن FOREIGN_KEY_CHECKS: " . $mysqli->error);
        if(is_array($ERROR)) {
            $ERROR[] = "عدم توانایی غیرفعال کردن محدودیت‌های کلید خارجی پیش از حذف جداول.";
        }
        return false;
    }
    $dbNameSafe = $mysqli->real_escape_string($dbName);
    $tablesResult = $mysqli->query("SHOW FULL TABLES FROM `{$dbNameSafe}`");
    if(!$tablesResult) {
        $log("❌ عدم توانایی دریافت لیست جداول: " . $mysqli->error);
        $mysqli->query("SET FOREIGN_KEY_CHECKS=1");
        if(is_array($ERROR)) {
            $ERROR[] = "عدم توانایی مشاهده جداول فعلی جهت حذف.";
        }
        return false;
    }
    $droppedTables = 0;
    $droppedViews = 0;
    while($row = $tablesResult->fetch_array(MYSQLI_NUM)) {
        $tableName = $row[0] ?? '';
        if($tableName === '') continue;
        $tableType = strtoupper($row[1] ?? 'BASE TABLE');
        $dropQuery = $tableType === 'VIEW'
            ? "DROP VIEW IF EXISTS `{$tableName}`"
            : "DROP TABLE IF EXISTS `{$tableName}`";
        if($mysqli->query($dropQuery)) {
            if($tableType === 'VIEW') {
                $droppedViews++;
                $log("🗑️ DROP VIEW: {$tableName}");
            } else {
                $droppedTables++;
                $log("🗑️ DROP TABLE: {$tableName}");
            }
        } else {
            $log("❌ خطا در حذف {$tableType} {$tableName}: " . $mysqli->error);
        }
    }
    $tablesResult->free();
    $mysqli->query("SET FOREIGN_KEY_CHECKS=1");
    $log("✅ مجموع جدول‌های حذف شده: {$droppedTables} | ویوها: {$droppedViews}");
    return true;
}
function handleDatabaseImport($dbInfo, &$ERROR) {
    $debugFile = dirname(__DIR__) . '/backup_import_log_' . date('Y-m-d_H-i-s') . '.txt';
    $logHandle = @fopen($debugFile, 'w');
    function writeLog($handle, $message) {
        if($handle) {
            $timestamp = date('Y-m-d H:i:s');
            fwrite($handle, "[{$timestamp}] {$message}\n");
        }
    }
    writeLog($logHandle, "========================================");
    writeLog($logHandle, "شروع Import بکاپ دیتابیس");
    writeLog($logHandle, "========================================");
    writeLog($logHandle, "Database: " . $dbInfo['name']);
    writeLog($logHandle, "Username: " . $dbInfo['username']);
    if(!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] == UPLOAD_ERR_NO_FILE) {
        writeLog($logHandle, "❌ خطا: فایل بکاپ آپلود نشد");
        $ERROR[] = "لطفاً فایل بکاپ را انتخاب کنید (SQL یا ZIP).";
        if($logHandle) fclose($logHandle);
        return false;
    }
    if($_FILES['backup_file']['error'] != UPLOAD_ERR_OK) {
        writeLog($logHandle, "❌ خطا در آپلود فایل: " . $_FILES['backup_file']['error']);
        $ERROR[] = "خطا در آپلود فایل بکاپ.";
        if($logHandle) fclose($logHandle);
        return false;
    }
    $uploadedFile = $_FILES['backup_file']['tmp_name'];
    $fileName = $_FILES['backup_file']['name'];
    $fileSize = $_FILES['backup_file']['size'] ?? 0;
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    writeLog($logHandle, "\n📁 اطلاعات فایل:");
    writeLog($logHandle, " نام: {$fileName}");
    writeLog($logHandle, " حجم: " . number_format($fileSize) . " بایت (" . round($fileSize/1024, 2) . " KB)");
    writeLog($logHandle, " توسعه: {$fileExt}");
    writeLog($logHandle, " مسیر موقت: {$uploadedFile}");
    $sqlContent = '';
    if($fileExt == 'zip') {
        writeLog($logHandle, "\n🗜️ پردازش فایل ZIP...");
        if(!class_exists('ZipArchive')) {
            writeLog($logHandle, "❌ ZipArchive class در دسترس نیست");
            $ERROR[] = "افزونه ZipArchive در PHP فعال نیست.";
            if($logHandle) fclose($logHandle);
            return false;
        }
        $zip = new ZipArchive;
        if ($zip->open($uploadedFile) === TRUE) {
            writeLog($logHandle, "✅ ZIP باز شد");
            writeLog($logHandle, " تعداد فایل‌ها: " . $zip->numFiles);
            $extracted = false;
            for($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                writeLog($logHandle, " فایل [{$i}]: {$filename}");
                if(strtolower(pathinfo($filename, PATHINFO_EXTENSION)) == 'sql') {
                    $sqlContent = $zip->getFromIndex($i);
                    writeLog($logHandle, " ✅ SQL استخراج شد - حجم: " . number_format(strlen($sqlContent)) . " بایت");
                    $extracted = true;
                    break;
                }
            }
            $zip->close();
            if(!$extracted) {
                writeLog($logHandle, "❌ فایل SQL در ZIP یافت نشد");
                $ERROR[] = "فایل SQL در داخل ZIP یافت نشد.";
                if($logHandle) fclose($logHandle);
                return false;
            }
        } else {
            writeLog($logHandle, "❌ خطا در باز کردن ZIP");
            $ERROR[] = "خطا در باز کردن فایل ZIP.";
            if($logHandle) fclose($logHandle);
            return false;
        }
    }
    elseif($fileExt == 'sql') {
        writeLog($logHandle, "\n📄 خواندن فایل SQL...");
        $sqlContent = file_get_contents($uploadedFile);
        writeLog($logHandle, "✅ SQL خوانده شد - حجم: " . number_format(strlen($sqlContent)) . " بایت");
    }
    else {
        writeLog($logHandle, "❌ فرمت نامعتبر: {$fileExt}");
        $ERROR[] = "فرمت فایل باید SQL یا ZIP باشد.";
        if($logHandle) fclose($logHandle);
        return false;
    }
    if(empty($sqlContent)) {
        writeLog($logHandle, "❌ محتوای SQL خالی است");
        $ERROR[] = "فایل SQL خالی است.";
        if($logHandle) fclose($logHandle);
        return false;
    }
    writeLog($logHandle, "\n🧹 پاکسازی SQL Content...");
    $originalLength = strlen($sqlContent);
    $sqlContent = cleanSQLContent($sqlContent);
    $cleanedLength = strlen($sqlContent);
    writeLog($logHandle, " قبل: " . number_format($originalLength) . " بایت");
    writeLog($logHandle, " بعد: " . number_format($cleanedLength) . " بایت");
    writeLog($logHandle, " حذف شده: " . number_format($originalLength - $cleanedLength) . " بایت");
    writeLog($logHandle, "\n✂️ تقسیم Queries...");
    $queries = splitSQLQueries($sqlContent);
    writeLog($logHandle, "✅ تعداد Queries: " . count($queries));
    writeLog($logHandle, "\n📋 نمونه Queries:");
    for($i = 0; $i < min(5, count($queries)); $i++) {
        $preview = substr(str_replace("\n", " ", $queries[$i]), 0, 100);
        writeLog($logHandle, " [{$i}] {$preview}...");
    }
    try {
        writeLog($logHandle, "\n🔌 اتصال به MySQL...");
        $mysqli = new mysqli('localhost', $dbInfo['username'], $dbInfo['password'], $dbInfo['name']);
        if ($mysqli->connect_error) {
            writeLog($logHandle, "❌ خطای اتصال: " . $mysqli->connect_error);
            $ERROR[] = "خطا در اتصال به دیتابیس: " . $mysqli->connect_error;
            if($logHandle) fclose($logHandle);
            return false;
        }
        writeLog($logHandle, "✅ اتصال برقرار شد");
        $mysqli->set_charset("utf8mb4");
        writeLog($logHandle, "✅ Charset: utf8mb4");
        if(!dropExistingTables($mysqli, $dbInfo['name'], $logHandle, $ERROR)) {
            writeLog($logHandle, "❌ عدم توانایی حذف داده‌های قبلی دیتابیس");
            if($logHandle) fclose($logHandle);
            return false;
        }
        $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
        writeLog($logHandle, "✅ FOREIGN_KEY_CHECKS = 0");
        $mysqli->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        writeLog($logHandle, "✅ SQL_MODE = NO_AUTO_VALUE_ON_ZERO");
        $mysqli->query("SET AUTOCOMMIT = 0");
        writeLog($logHandle, "✅ AUTOCOMMIT = 0");
        $mysqli->query("START TRANSACTION");
        writeLog($logHandle, "✅ Transaction شروع شد");
        $successCount = 0;
        $failCount = 0;
        $errorMessages = [];
        $tableStats = [];
        writeLog($logHandle, "\n" . str_repeat("=", 80));
        writeLog($logHandle, "🚀 اجرای Queries...");
        writeLog($logHandle, str_repeat("=", 80));
        foreach($queries as $queryIndex => $query) {
            $query = trim($query);
            if(empty($query) || (substr($query, 0, 2) == '--' && strpos($query, 'INSERT') === false && strpos($query, 'CREATE') === false) || substr($query, 0, 1) == '#') {
                continue;
            }
            $queryType = '';
            $tableName = '';
            if(preg_match('/^(DROP|CREATE|INSERT|ALTER|UPDATE|DELETE)\s+(TABLE\s+)?(?:IF\s+EXISTS\s+)?(?:INTO\s+)?`?([^`\s(]+)`?/i', $query, $matches)) {
                $queryType = strtoupper($matches[1]);
                if(isset($matches[3])) {
                    $tableName = $matches[3];
                }
            }
            $queryPreview = substr(str_replace("\n", " ", $query), 0, 100);
            if($mysqli->query($query)) {
                $successCount++;
                if(!isset($tableStats[$tableName])) {
                    $tableStats[$tableName] = ['success' => 0, 'fail' => 0];
                }
                $tableStats[$tableName]['success']++;
                writeLog($logHandle, "[✅] #{$queryIndex} | {$queryType} | {$tableName}");
            } else {
                $failCount++;
                $errorCode = $mysqli->errno;
                $errorMsg = $mysqli->error;
                if(!isset($tableStats[$tableName])) {
                    $tableStats[$tableName] = ['success' => 0, 'fail' => 0];
                }
                $tableStats[$tableName]['fail']++;
                $errorMessages[] = substr($query, 0, 100) . "... - " . $mysqli->error;
                writeLog($logHandle, "[❌] #{$queryIndex} | {$queryType} | {$tableName}");
                writeLog($logHandle, " Error #{$errorCode}: {$errorMsg}");
                writeLog($logHandle, " Query: {$queryPreview}...");
                if($failCount > 50) {
                    writeLog($logHandle, "\n⛔ بیش از 50 خطا! متوقف می‌شود.");
                    break;
                }
            }
        }
        $mysqli->query("COMMIT");
        writeLog($logHandle, "\n✅ Transaction COMMIT شد");
        $mysqli->query("SET FOREIGN_KEY_CHECKS=1");
        writeLog($logHandle, "✅ FOREIGN_KEY_CHECKS = 1");
        $mysqli->close();
        writeLog($logHandle, "✅ اتصال بسته شد");
        writeLog($logHandle, "\n" . str_repeat("=", 80));
        writeLog($logHandle, "📊 خلاصه نتایج:");
        writeLog($logHandle, str_repeat("=", 80));
        writeLog($logHandle, "✅ موفق: {$successCount}");
        writeLog($logHandle, "❌ ناموفق: {$failCount}");
        writeLog($logHandle, "📊 کل: " . ($successCount + $failCount));
        writeLog($logHandle, "📊 نرخ موفقیت: " . round(($successCount / max(1, $successCount + $failCount)) * 100, 2) . "%");
        if(!empty($tableStats)) {
            writeLog($logHandle, "\n📋 آمار هر جدول:");
            foreach($tableStats as $table => $stats) {
                if($stats['fail'] > 0) {
                    writeLog($logHandle, " ⚠️ {$table}: موفق={$stats['success']}, خطا={$stats['fail']}");
                } else {
                    writeLog($logHandle, " ✅ {$table}: موفق={$stats['success']}");
                }
            }
        }
        if($failCount > 0 && $successCount == 0) {
            writeLog($logHandle, "\n🔴 نتیجه نهایی: ناموفق");
            $ERROR[] = "❌ خطا در ایمپورت دیتابیس";
            $ERROR[] = "تعداد خطا: $failCount";
            if(!empty($errorMessages)) {
                $ERROR[] = "<details><summary>جزئیات خطاها</summary>" . implode("<br>", array_slice($errorMessages, 0, 5)) . "</details>";
            }
            $ERROR[] = "📋 لاگ کامل: " . basename($debugFile);
            if($logHandle) fclose($logHandle);
            return false;
        }
        if($failCount > 0) {
            writeLog($logHandle, "\n🟡 نتیجه نهایی: موفق با هشدار");
            $ERROR[] = "⚠️ تعدادی از کوئری‌ها با خطا مواجه شدند ($failCount خطا از " . ($successCount + $failCount) . " کوئری)";
            $ERROR[] = "📋 لاگ کامل: " . basename($debugFile);
        } else {
            writeLog($logHandle, "\n🟢 نتیجه نهایی: موفق");
        }
        writeLog($logHandle, "\n========================================");
        writeLog($logHandle, "پایان Import");
        writeLog($logHandle, "========================================");
        if($logHandle) fclose($logHandle);
        return true;
    } catch (Exception $e) {
        writeLog($logHandle, "\n❌ Exception: " . $e->getMessage());
        $ERROR[] = "خطا در ایمپورت دیتابیس: " . $e->getMessage();
        if($logHandle) fclose($logHandle);
        return false;
    }
}
function runCompleteMigration($dbInfo, $adminNumber, &$migrationLog) {
    try {
        $connect = new mysqli('localhost', $dbInfo['username'], $dbInfo['password'], $dbInfo['name']);
        if ($connect->connect_error) {
            $migrationLog[] = "❌ خطا در اتصال: " . $connect->connect_error;
            return false;
        }
        $connect->set_charset("utf8mb4");
        $migrationLog[] = "✅ اتصال به دیتابیس";
        $migrationLog[] = "📋 بخش 1:مرزبان پنل";
        $check_old = $connect->query("SHOW TABLES LIKE 'marzbanpanel'");
        if ($check_old && $check_old->num_rows > 0) {
            $connect->query("RENAME TABLE `marzbanpanel` TO `marzban_panel`");
            $migrationLog[] = "✅ Rename: marzbanpanel → marzban_panel";
        }
        $result = $connect->query("SHOW TABLES LIKE 'marzban_panel'");
        $table_exists = ($result && $result->num_rows > 0);
        if ($table_exists) {
            $check = $connect->query("SHOW COLUMNS FROM `marzban_panel` LIKE 'statusTest'");
            if ($check && $check->num_rows > 0) {
                $connect->query("ALTER TABLE `marzban_panel` DROP COLUMN `statusTest`");
                $migrationLog[] = "🗑️ حذف statusTest";
            }
            $check = $connect->query("SHOW COLUMNS FROM `marzban_panel` LIKE 'configManual'");
            if ($check && $check->num_rows > 0) {
                $check_config = $connect->query("SHOW COLUMNS FROM `marzban_panel` LIKE 'config'");
                if ($check_config->num_rows == 0) {
                    $connect->query("ALTER TABLE `marzban_panel` CHANGE `configManual` `config` varchar(500) DEFAULT 'offconfig'");
                    $migrationLog[] = "✅ Rename: configManual → config";
                } else {
                    $connect->query("ALTER TABLE `marzban_panel` DROP COLUMN `configManual`");
                    $connect->query("ALTER TABLE `marzban_panel` MODIFY `config` varchar(500) DEFAULT 'offconfig'");
                    $migrationLog[] = "✅ تنظیم config";
                }
            }
            $check = $connect->query("SHOW COLUMNS FROM `marzban_panel` LIKE 'onholdstatus'");
            if ($check && $check->num_rows > 0) {
                $check_onhold = $connect->query("SHOW COLUMNS FROM `marzban_panel` LIKE 'onholdtest'");
                if ($check_onhold->num_rows == 0) {
                    $connect->query("ALTER TABLE `marzban_panel` CHANGE `onholdstatus` `onholdtest` varchar(60) DEFAULT '1'");
                    $migrationLog[] = "✅ Rename: onholdstatus → onholdtest";
                }
            }
            $connect->query("UPDATE `marzban_panel` SET `status` = 'active' WHERE `status` = 'activepanel'");
            $connect->query("UPDATE `marzban_panel` SET `status` = 'deactive' WHERE `status` = 'deactivepanel'");
            $VALUE = json_encode(array('f' => 0, 'n' => 0, 'n2' => 0));
            $value_price = json_encode(array('f' => 4000, 'n' => 4000, 'n2' => 4000));
            $value_main = json_encode(array('f' => 1, 'n' => 1, 'n2' => 1));
            $value_max = json_encode(array('f' => 1000, 'n' => 1000, 'n2' => 1000));
            $value_maxtime = json_encode(array('f' => 365, 'n' => 365, 'n2' => 365));
            $fields_added = 0;
            if (addFieldToTable('marzban_panel', 'proxies', null, 'TEXT', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'inbounds', null, 'TEXT', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'customvolume', $VALUE, 'TEXT', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'subvip', 'offsubvip', 'VARCHAR(60)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'changeloc', 'offchangeloc', 'VARCHAR(60)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'hideuser', null, 'TEXT', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'statusextend', 'onextend', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'code_panel', null, 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'priceextravolume', $value_price, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'pricecustomvolume', $value_price, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'pricecustomtime', $value_price, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'priceextratime', $value_price, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'priceChangeloc', '0', 'VARCHAR(100)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'mainvolume', $value_main, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'maxvolume', $value_max, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'maintime', $value_main, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'maxtime', $value_maxtime, 'VARCHAR(500)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'MethodUsername', '', 'VARCHAR(100)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'datelogin', null, 'TEXT', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'valusertest', '100', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'timeusertest', '1', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'secretcode', null, 'VARCHAR(200)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'inboundstatus', 'offinbounddisable', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'inbounddeactive', '0', 'VARCHAR(100)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'agent', 'all', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'inboundid', '1', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'linksubx', null, 'VARCHAR(200)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'conecton', 'offconecton', 'VARCHAR(100)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'type', 'marzban', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'Methodextend', '', 'VARCHAR(100)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'namecustom', 'vpn', 'VARCHAR(100)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'limit_panel', 'unlimted', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'TestAccount', 'ONTestAccount', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'status', 'active', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'sublink', 'onsublink', 'VARCHAR(50)', $connect)) { $fields_added++; }
            if (addFieldToTable('marzban_panel', 'hosts', null, 'JSON', $connect)) { $fields_added++; }
            $migrationLog[] = "✅ فیلدهای اضافه: $fields_added";
            $max_stmt = $connect->query("SELECT MAX(CAST(SUBSTRING(code_panel, 3) AS UNSIGNED)) as max_num FROM marzban_panel WHERE code_panel LIKE '7e%'");
            if ($max_stmt) {
                $max_row = $max_stmt->fetch_assoc();
                $next_num = $max_row['max_num'] ? (int)$max_row['max_num'] + 1 : 15;
            } else {
                $next_num = 15;
            }
            $stmt = $connect->query("SELECT id FROM marzban_panel WHERE code_panel IS NULL OR code_panel = ''");
            if ($stmt) {
                $updated_count = 0;
                while ($row = $stmt->fetch_assoc()) {
                    $code = '7e' . $next_num;
                    if ($connect->query("UPDATE marzban_panel SET code_panel = '$code' WHERE id = " . $row['id'])) {
                        $updated_count++;
                        $next_num++;
                    }
                }
                if ($updated_count > 0) {
                    $migrationLog[] = "✅ تولید code_panel: $updated_count پنل";
                }
            }
        }
        $migrationLog[] = "📋 بخش 2: setting";
        $connect->query("DROP TABLE IF EXISTS `setting`");
        $sql = "CREATE TABLE `setting` (
            `Bot_Status` varchar(200) DEFAULT NULL,
            `roll_Status` varchar(200) DEFAULT NULL,
            `get_number` varchar(200) DEFAULT NULL,
            `iran_number` varchar(200) DEFAULT NULL,
            `NotUser` varchar(200) DEFAULT NULL,
            `Channel_Report` varchar(600) DEFAULT NULL,
            `limit_usertest_all` varchar(600) DEFAULT NULL,
            `affiliatesstatus` varchar(600) DEFAULT NULL,
            `affiliatespercentage` varchar(600) DEFAULT NULL,
            `removedayc` varchar(600) DEFAULT NULL,
            `showcard` varchar(200) DEFAULT NULL,
            `numbercount` varchar(600) DEFAULT NULL,
            `statusnewuser` varchar(600) DEFAULT NULL,
            `statusagentrequest` varchar(600) DEFAULT NULL,
            `statuscategory` varchar(200) DEFAULT NULL,
            `statusterffh` varchar(200) DEFAULT NULL,
            `volumewarn` varchar(200) DEFAULT NULL,
            `inlinebtnmain` varchar(200) DEFAULT NULL,
            `verifystart` varchar(200) DEFAULT NULL,
            `id_support` varchar(200) DEFAULT NULL,
            `statusnamecustom` varchar(100) DEFAULT NULL,
            `statuscategorygenral` varchar(100) DEFAULT NULL,
            `statussupportpv` varchar(100) DEFAULT NULL,
            `agentreqprice` varchar(100) DEFAULT NULL,
            `bulkbuy` varchar(100) DEFAULT NULL,
            `on_hold_day` varchar(100) DEFAULT NULL,
            `cronvolumere` varchar(100) DEFAULT NULL,
            `verifybucodeuser` varchar(100) DEFAULT NULL,
            `scorestatus` varchar(100) DEFAULT NULL,
            `Lottery_prize` text DEFAULT NULL,
            `wheelـluck` varchar(45) DEFAULT NULL,
            `wheelـluck_price` varchar(45) DEFAULT NULL,
            `btn_status_extned` varchar(45) DEFAULT NULL,
            `daywarn` varchar(45) DEFAULT NULL,
            `categoryhelp` varchar(45) DEFAULT NULL,
            `linkappstatus` varchar(45) DEFAULT NULL,
            `iplogin` varchar(45) DEFAULT NULL,
            `wheelagent` varchar(45) DEFAULT NULL,
            `Lotteryagent` varchar(45) DEFAULT NULL,
            `languageen` varchar(45) DEFAULT NULL,
            `languageru` varchar(45) DEFAULT NULL,
            `statusfirstwheel` varchar(45) DEFAULT NULL,
            `statuslimitchangeloc` varchar(45) DEFAULT NULL,
            `Debtsettlement` varchar(45) DEFAULT NULL,
            `Dice` varchar(45) DEFAULT NULL,
            `keyboardmain` text NOT NULL,
            `statusnoteforf` varchar(45) NOT NULL,
            `statuscopycart` varchar(45) NOT NULL,
            `timeauto_not_verify` varchar(20) NOT NULL,
            `status_keyboard_config` varchar(20) DEFAULT NULL,
            `cron_status` text NOT NULL,
            `limitnumber` varchar(200) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($connect->query($sql) === TRUE) {
            $sql = "INSERT INTO `setting` VALUES ('botstatuson', 'rolleon', 'offAuthenticationphone', 'offAuthenticationiran', 'offnotuser', NULL, '1', 'offaffiliates', '0', '0', '1', '0', 'onnewuser', 'onrequestagent', 'offcategory', NULL, '2', 'offinline', 'offverify', NULL, 'offnamecustom', 'offcategorys', 'offpvsupport', '0', 'onbulk', '4', '5', 'offverify', '0', '{\"one\":\"0\",\"tow\":\"0\",\"theree\":\"0\"}', '0', '0', NULL, '2', '0', '0', '0', '1', '1', '0', '0', '0', '0', '1', '0', '{\"keyboard\":[[{\"text\":\"text_sell\"},{\"text\":\"text_extend\"}],[{\"text\":\"text_usertest\"},{\"text\":\"text_wheel_luck\"}],[{\"text\":\"text_Purchased_services\"},{\"text\":\"accountwallet\"}],[{\"text\":\"text_affiliates\"},{\"text\":\"text_Tariff_list\"}],[{\"text\":\"text_support\"},{\"text\":\"text_help\"}]]}', '1', '0', '4', '1', '{\"day\":true,\"volume\":true,\"remove\":false,\"remove_volume\":false,\"test\":false,\"on_hold\":false,\"uptime_node\":false,\"uptime_panel\":false}', '{\"free\":100,\"all\":100}')";
            $connect->query($sql);
            $migrationLog[] = "✅ ایجاد setting";
        }
        $migrationLog[] = "📋 بخش 2.2: channels";
        $connect->query("DROP TABLE IF EXISTS `channels`");
        $sql = "CREATE TABLE `channels` (
            `remark` varchar(200) NOT NULL,
            `linkjoin` varchar(200) NOT NULL,
            `link` varchar(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($connect->query($sql) === TRUE) {
            $migrationLog[] = "✅ ایجاد channels";
        } else {
            $migrationLog[] = "⚠️ عدم توانایی ایجاد channels: " . $connect->error;
        }
        $migrationLog[] = "📋 بخش 2.5: topicid";
        $connect->query("DROP TABLE IF EXISTS `topicid`");
        $sql = "CREATE TABLE `topicid` (
            `report` varchar(500) NOT NULL,
            `idreport` text NOT NULL,
            PRIMARY KEY (`report`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($connect->query($sql) === TRUE) {
            $sql = "INSERT INTO `topicid` (`report`, `idreport`) VALUES
                ('backupfile', '0'),
                ('buyreport', '0'),
                ('errorreport', '0'),
                ('otherreport', '0'),
                ('otherservice', '0'),
                ('paymentreport', '0'),
                ('porsantreport', '0'),
                ('reportcron', '0'),
                ('reportnight', '0'),
                ('reporttest', '0')";
            $connect->query($sql);
            $migrationLog[] = "✅ ایجاد topicid";
        }
        $migrationLog[] = "📋 بخش 3: جداول جدید";
        $tables_count = 0;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `app` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `nameapp` varchar(500) NOT NULL, `download_link` varchar(1000) NOT NULL, `os` varchar(200) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `botsaz` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `id_user` varchar(200) NOT NULL, `username` varchar(200) NOT NULL, `name_bot` varchar(200) NOT NULL, `token` varchar(500) NOT NULL, `time_expire` varchar(200) NOT NULL, `status` varchar(200) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `card_number` (`cardnumber` varchar(500) NOT NULL, `name_card` varchar(1000) NOT NULL, PRIMARY KEY (`cardnumber`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `departman` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `idsupport` varchar(200) NOT NULL, `name_departman` varchar(600) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `logs_api` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `header` longtext, `data` longtext, `ip` varchar(200) NOT NULL, `time` varchar(200) NOT NULL, `actions` varchar(200) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `manualsell` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `codepanel` varchar(100) NOT NULL, `codeproduct` varchar(100) NOT NULL, `namerecord` varchar(200) NOT NULL, `username` varchar(500) DEFAULT NULL, `contentrecord` text NOT NULL, `status` varchar(200) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `reagent_report` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` bigint(20) NOT NULL, `get_gift` tinyint(1) NOT NULL, `time` varchar(50) NOT NULL, `reagent` varchar(30) NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `user_id` (`user_id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `Requestagent` (`id` varchar(500) NOT NULL, `username` varchar(500) NOT NULL, `time` varchar(500) NOT NULL, `Description` varchar(500) NOT NULL, `status` varchar(500) NOT NULL, `type` varchar(500) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `service_other` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `id_user` varchar(500) NOT NULL, `username` varchar(1000) NOT NULL, `value` varchar(1000) NOT NULL, `time` varchar(200) NOT NULL, `price` varchar(200) NOT NULL, `type` varchar(1000) NOT NULL, `status` varchar(200) NOT NULL, `output` text NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `support_message` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `Tracking` varchar(100) NOT NULL, `idsupport` varchar(100) NOT NULL, `iduser` varchar(100) NOT NULL, `name_departman` varchar(600) NOT NULL, `text` text NOT NULL, `result` text NOT NULL, `time` varchar(200) NOT NULL, `status` enum('Answered','Pending','Unseen','Customerresponse','close') NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `topicid` (`report` varchar(500) NOT NULL, `idreport` text NOT NULL, PRIMARY KEY (`report`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `wheel_list` (`id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, `id_user` varchar(200) NOT NULL, `time` varchar(200) NOT NULL, `first_name` varchar(200) NOT NULL, `wheel_code` varchar(200) NOT NULL, `price` varchar(200) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB")) $tables_count++;
        if ($connect->query("CREATE TABLE IF NOT EXISTS `shopSetting` (`Namevalue` varchar(500) NOT NULL, `value` text NOT NULL, PRIMARY KEY (`Namevalue`)) ENGINE=InnoDB")) $tables_count++;
        $connect->query("INSERT INTO `topicid` VALUES ('backupfile', '0'), ('buyreport', '0'), ('errorreport', '0'), ('otherreport', '0'), ('otherservice', '0'), ('paymentreport', '0'), ('porsantreport', '0'), ('reportcron', '0'), ('reportnight', '0'), ('reporttest', '0') ON DUPLICATE KEY UPDATE idreport=idreport");
        $connect->query("INSERT INTO `departman` VALUES (1, '$adminNumber', 'پشتیبانی فنی') ON DUPLICATE KEY UPDATE name_departman='پشتیبانی فنی'");
        $connect->query("INSERT INTO `shopSetting` VALUES ('backserviecstatus', 'on'), ('chashbackextend', '0'), ('configshow', 'onconfig'), ('customtimepricef', '4000'), ('customvolmef', '4000'), ('statuschangeservice', 'onstatus'), ('statusdirectpabuy', 'ondirectbuy'), ('statusextra', 'offextra') ON DUPLICATE KEY UPDATE value=value");
        $migrationLog[] = "✅ جداول: $tables_count";
        $migrationLog[] = "📋 بخش 4: user";
        $old_fields = ["ref_code"];
        foreach ($old_fields as $field) {
            $check = $connect->query("SHOW COLUMNS FROM `user` LIKE '$field'");
            if ($check && $check->num_rows > 0) {
                if ($field == "ref_code") @$connect->query("ALTER TABLE `user` DROP INDEX `ref_code`");
                @$connect->query("ALTER TABLE `user` DROP COLUMN `$field`");
            }
        }
        if (addFieldToTable('user', 'verify', '', 'VARCHAR(100)', $connect)) {
            $migrationLog[] = "✅ verify";
        }
        $user_fields = 0;
        if (addFieldToTable('user', 'agent', '0', 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'namecustom', '', 'VARCHAR(300)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'number_username', '', 'VARCHAR(300)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'register', '', 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'cardpayment', '', 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'codeInvitation', null, 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'pricediscount', '0', 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'hide_mini_app_instruction', '0', 'VARCHAR(20)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'maxbuyagent', '0', 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'joinchannel', '0', 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'checkstatus', '0', 'VARCHAR(50)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'bottype', null, 'TEXT', $connect)) $user_fields++;
        if (addFieldToTable('user', 'score', '0', 'INT(255)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'limitchangeloc', '0', 'VARCHAR(50)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'status_cron', '1', 'VARCHAR(20)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'expire', null, 'VARCHAR(100)', $connect)) $user_fields++;
        if (addFieldToTable('user', 'token', null, 'VARCHAR(100)', $connect)) $user_fields++;
        $migrationLog[] = "✅ فیلدها: $user_fields";
        $migrationLog[] = "📋 بخش 5: textbot";
        $texts = [
            ['text_extend', '♻️ تمدید سرویس'],
            ['text_wheel_luck', '🎲 گردونه شانس']
        ];
        $texts_count = 0;
        foreach ($texts as $text) {
            if (@$connect->query("INSERT INTO `textbot` VALUES ('{$text[0]}', '{$text[1]}') ON DUPLICATE KEY UPDATE text='{$text[1]}'")) $texts_count++;
        }
        $migrationLog[] = "✅ متن‌ها: $texts_count";
        $migrationLog[] = "📋 بخش 6: تبدیل Category در product";

        $categoryDebugFile = __DIR__ . '/category_migration_debug.log';

        try {

            file_put_contents(
                $categoryDebugFile,
                "================ " . date('Y-m-d H:i:s') . " ================\nشروع بخش 6: تبدیل Category\n",
                FILE_APPEND
            );

            $check_category = $connect->query("SHOW TABLES LIKE 'category'");
            $check_product  = $connect->query("SHOW TABLES LIKE 'product'");

            file_put_contents(
                $categoryDebugFile,
                "وجود جدول category: " . (($check_category && $check_category->num_rows > 0) ? "YES" : "NO") . "\n" .
                "وجود جدول product : " . (($check_product  && $check_product->num_rows  > 0) ? "YES" : "NO") . "\n",
                FILE_APPEND
            );

            if ($check_category && $check_category->num_rows > 0 &&
                $check_product  && $check_product->num_rows  > 0) {

                $colInfo = $connect->query("SHOW FULL COLUMNS FROM `product` LIKE 'Category'");
                if ($colInfo && $col = $colInfo->fetch_assoc()) {
                    file_put_contents(
                        $categoryDebugFile,
                        "ستون Category → نوع: {$col['Type']}, Collation: {$col['Collation']}\n",
                        FILE_APPEND
                    );
                }

                $categories_result = $connect->query("SELECT `id`, `remark` FROM `category`");
                file_put_contents(
                    $categoryDebugFile,
                    "تعداد ردیف‌های جدول category: " . ($categories_result ? $categories_result->num_rows : 0) . "\n",
                    FILE_APPEND
                );

                if ($categories_result && $categories_result->num_rows > 0) {

                    $updateStmt = $connect->prepare(
                        "UPDATE `product` SET `Category` = ? WHERE `Category` = ?"
                    );
                    if (!$updateStmt) {
                        throw new Exception('آماده‌سازی UPDATE ناموفق بود: ' . $connect->error);
                    }

                    $updated_count = 0;

                    while ($row = $categories_result->fetch_assoc()) {
                        $cat_id     = (string)($row['id'] ?? '');
                        $cat_remark = (string)($row['remark'] ?? '');

                        if ($cat_id === '') {
                            file_put_contents(
                                $categoryDebugFile,
                                "⚠️ ردیف category با id خالی نادیده گرفته شد\n",
                                FILE_APPEND
                            );
                            continue;
                        }

                        $beforeCntRes = $connect->query(
                            "SELECT COUNT(*) AS c FROM `product` WHERE `Category` = '" .
                            $connect->real_escape_string($cat_id) . "'"
                        );
                        $beforeCntRow = $beforeCntRes ? $beforeCntRes->fetch_assoc() : ['c' => 0];
                        $beforeCount  = (int)($beforeCntRow['c'] ?? 0);

                        file_put_contents(
                            $categoryDebugFile,
                            "→ id={$cat_id}, remark=" .
                            json_encode($cat_remark, JSON_UNESCAPED_UNICODE) .
                            ", تعداد product قبل از آپدیت: {$beforeCount}\n",
                            FILE_APPEND
                        );

                        if ($beforeCount === 0) {
                            file_put_contents(
                                $categoryDebugFile,
                                "   هیچ محصولی با Category = {$cat_id} وجود ندارد، از این دسته می‌گذریم.\n",
                                FILE_APPEND
                            );
                            continue;
                        }

                        $updateStmt->bind_param('ss', $cat_remark, $cat_id);
                        if ($updateStmt->execute()) {
                            $affected = $updateStmt->affected_rows;
                            $updated_count += max(0, $affected);

                            file_put_contents(
                                $categoryDebugFile,
                                "   اجرای UPDATE → ردیف‌های تغییر کرده: {$affected}\n",
                                FILE_APPEND
                            );
                        } else {
                            file_put_contents(
                                $categoryDebugFile,
                                "   ⚠️ خطا در UPDATE برای id={$cat_id}: " . $updateStmt->error . "\n",
                                FILE_APPEND
                            );
                        }
                    }

                    $updateStmt->close();

                    file_put_contents(
                        $categoryDebugFile,
                        "جمع کل ردیف‌های آپدیت شده: {$updated_count}\n",
                        FILE_APPEND
                    );

                    if ($updated_count > 0) {
                        $migrationLog[] = "✅ تبدیل Category: $updated_count محصول";
                    } else {
                        $migrationLog[] = "ℹ️ بخش Category: ردیفی برای بروزرسانی پیدا نشد (احتمالاً ستون Category دیگر عددی نیست).";
                    }
                }
            }
        } catch (Exception $e) {
            $msg = "⚠️ خطا در تبدیل Category: " . $e->getMessage();
            $migrationLog[] = $msg;
            file_put_contents(
                $categoryDebugFile,
                "❌ EXCEPTION: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }

        $migrationLog[] = "📋 بخش 7: admin";
        $check_admin = $connect->query("SHOW TABLES LIKE 'admin'");
        if ($check_admin && $check_admin->num_rows > 0) {
            $count = $connect->query("SELECT COUNT(*) as cnt FROM admin")->fetch_assoc()['cnt'];
            if ($count == 0) {
                $connect->query("INSERT INTO `admin` (`id_admin`, `username`, `password`, `rule`) VALUES ('$adminNumber', 'admin', '14e9eab674', 'administrator')");
                $migrationLog[] = "✅ ایجاد ادمین جدید";
            } else {
                $connect->query("UPDATE `admin` SET `id_admin` = '$adminNumber', `username` = 'admin', `password` = '14e9eab674', `rule` = 'administrator' LIMIT 1");
                $migrationLog[] = "✅ به‌روزرسانی ادمین";
            }
        } else {
            $sql_create = "CREATE TABLE `admin` (
              `id_admin` varchar(500) NOT NULL,
              `username` varchar(1000) NOT NULL,
              `password` varchar(1000) NOT NULL,
              `rule` varchar(500) NOT NULL,
              PRIMARY KEY (`id_admin`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci";
            $connect->query($sql_create);
            $connect->query("INSERT INTO `admin` (`id_admin`, `username`, `password`, `rule`) VALUES ('$adminNumber', 'admin', '14e9eab674', 'administrator')");
            $migrationLog[] = "✅ ایجاد جدول admin";
        }
        $connect->close();
        return true;
    } catch (Exception $e) {
        $migrationLog[] = "❌ خطا: " . $e->getMessage();
        return false;
    }
}
if(isset($uPOST['submit']) && $uPOST['submit']) {
    $ERROR = [];
    $SUCCESS[] = "✅ ربات با موفقیت نصب شد !";
    $rawConfigData = file_get_contents($configDirectory);
    $tgAdminId = $uPOST['admin_id'];
    $tgBotToken = $uPOST['tg_bot_token'];
    $dbInfo['host'] = 'localhost';
    $dbInfo['name'] = $uPOST['database_name'];
    $dbInfo['username'] = $uPOST['database_username'];
    $dbInfo['password'] = $uPOST['database_password'];
    $inputUrl = $uPOST['bot_address_webhook'] ?? $webAddress . '/index.php';
    $document = normalizeDomainAddress($inputUrl);
    if ($document === null) {
        $ERROR[] = 'آدرس ارائه شده برای ربات نامعتبر است.';
    }
    if(!isHttps()) {
        $ERROR[] = 'برای فعال سازی ربات تلگرام نیازمند فعال بودن SSL (https) هستید';
        $ERROR[] = '<i>اگر از فعال بودن SSL مطمئن هستید، سرور پشت proxy/CDN (مثل Cloudflare) است – headers را در cPanel چک کنید یا با https مستقیم باز کنید.</i>';
        $sslLink = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
        $ERROR[] = '<a href="' . $sslLink . '">' . $sslLink . '</a>';
    }
    $isValidToken = isValidTelegramToken($tgBotToken);
    if(!$isValidToken) {
        $ERROR[] = "توکن ربات صحیح نمی باشد.";
    }
    if (!isValidTelegramId($tgAdminId)) {
        $ERROR[] = "آیدی عددی ادمین نامعتبر است.";
    }
    if($isValidToken) {
        $tgBot['details'] = getContents("https://api.telegram.org/bot".$tgBotToken."/getMe");
        if($tgBot['details']['ok'] == false) {
            $ERROR[] = "توکن ربات را بررسی کنید. <i>عدم توانایی دریافت جزئیات ربات.</i>";
        }
        else {
            $tgBot['recognition'] = getContents("https://api.telegram.org/bot".$tgBotToken."/getChat?chat_id=".$tgAdminId);
            if($tgBot['recognition']['ok'] == false) {
                $ERROR[] = "<b>عدم شناسایی مدیر ربات:</b>";
                $ERROR[] = "ابتدا ربات را فعال/استارت کنید با اکانت که میخواهید مدیر اصلی ربات باشد.";
                $ERROR[] = "<a href='https://t.me/".$tgBot['details']['result']['username']."'>@".$tgBot['details']['result']['username']."</a>";
            }
        }
    }
    try {
        $dsn = "mysql:host=" . $dbInfo['host'] . ";dbname=" . $dbInfo['name'] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $dbInfo['username'], $dbInfo['password']);
        $SUCCESS[] = "✅ اتصال به دیتابیس موفقیت آمیز بود!";
    }
    catch (\PDOException $e) {
        $ERROR[] = "❌ عدم اتصال به دیتابیس: ";
        $ERROR[] = "اطلاعات ورودی را بررسی کنید.";
        $ERROR[] = "<code>".$e->getMessage()."</code>";
    }
    if(in_array($installType, ['migrate_free_to_pro']) && $hasDbBackup == 'no' && empty($ERROR)) {
        $importSuccess = handleDatabaseImport($dbInfo, $ERROR);
        if($importSuccess) {
            $SUCCESS[] = "✅ بکاپ دیتابیس با موفقیت ایمپورت شد!";
        }
    }
    if(empty($ERROR)) {
        $replacements = [
            '{database_name}' => $dbInfo['name'],
            '{username_db}' => $dbInfo['username'],
            '{password_db}' => $dbInfo['password'],
            '{API_KEY}' => $tgBotToken,
            '{admin_number}' => $tgAdminId,
            '{domain_name}' => $document['address'],
            '{username_bot}' => $tgBot['details']['result']['username']
        ];
        $replacementCount = 0;
        if ($serverType == 'server') {

            $serverConfigTemplate = '<?php
$APIKEY = \'{API_KEY}\';
$usernamedb = \'{username_db}\';
$passworddb = \'{password_db}\';
$dbname = \'{database_name}\';
$domainhosts = \'{domain_name}\';
$adminnumber = \'{admin_number}\';
$usernamebot = \'{username_bot}\';
$secrettoken = \'A6M3yCSN\';
$connect = mysqli_connect(\'localhost\', $usernamedb, $passworddb, $dbname);
if ($connect->connect_error) {
die(\' The connection to the database failed:\' . $connect->connect_error);
}
mysqli_set_charset($connect, \'utf8mb4\');
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$dsn = "mysql:host=localhost;dbname=$dbname;charset=utf8mb4";
try {
     $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (\\PDOException $e) {
     throw new \\PDOException($e->getMessage(), (int)$e->getCode());
}
?>';
            $newConfigData = str_replace(array_keys($replacements), array_values($replacements), $serverConfigTemplate);
            $replacementCount = count($replacements);
        } else {

            $newConfigData = updateConfigValues($rawConfigData, $replacements, $replacementCount);
        }
        if($replacementCount === 0 || file_put_contents($configDirectory,$newConfigData) === false) {
            $ERROR[] = '✏️❌ خطا در زمان بازنویسی اطلاعات فایل اصلی ربات';
            $ERROR[] = "فایل های پروژه را مجددا دانلود و بارگذاری کنید (<a href='https://github.com/ishantia/mirza_pro/releases/'>‎🌐 Github</a>)";
    }
        else {
            $baseAddress = rtrim($document['address'], '/');
            $tableResult = getContents("https://".$baseAddress."/table.php");
            $SUCCESS[] = "✅ جداول دیتابیس ایجاد/بروزرسانی شد";
            $shouldRunMigrationTasks = ($installType === 'simple') || needsMigration($installType);
            if ($shouldRunMigrationTasks) {
                $migrationLog = [];
                $migrationResult = runCompleteMigration($dbInfo, $tgAdminId, $migrationLog);
                if ($migrationResult) {
                    $migrationTitle = needsMigration($installType)
                        ? "مهاجرت کامل انجام شد"
                        : "ساختار دیتابیس بروزرسانی شد";
                    $SUCCESS[] = "✅ {$migrationTitle} (" . count($migrationLog) . " مرحله)";
                    foreach ($migrationLog as $log) {
                        $SUCCESS[] = $log;
                    }
                }
            }
            if ($installType === 'migrate_free_to_pro') {
                $categoryMigrationLog = [];
                if (runCategoryRemarkMigration($dbInfo, $categoryMigrationLog)) {
                    $SUCCESS[] = "✅ مهاجرت Category ID به Remark به‌صورت خودکار انجام شد.";
                    foreach ($categoryMigrationLog as $entry) {
                        $SUCCESS[] = $entry;
                    }
                } else {
                    $ERROR[] = "⚠️ مهاجرت خودکار Category ID به Remark با خطا مواجه شد.";
                }
            }
            ensureAdminRecord($dbInfo, $tgAdminId);
            getContents("https://api.telegram.org/bot".$tgBotToken."/setwebhook?url=https://".$baseAddress.'/index.php');
            $SUCCESS[] = "✅ Webhook تنظیم شد";
            $botFirstMessage = "\n[🤖] شما به عنوان ادمین معرفی شدید.";
            $telegramMessage = urlencode(' '.$SUCCESS[0].$botFirstMessage);
            $replyMarkup = urlencode(json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '⚙️ شروع ربات ', 'callback_data' => 'start']
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE));
            getContents("https://api.telegram.org/bot{$tgBotToken}/sendMessage?chat_id={$tgAdminId}&text={$telegramMessage}&reply_markup={$replyMarkup}");
            $success = true;
        }
    }
}

function ensureAdminRecord($dbInfo, $adminNumber) {
    try {
        $connect = @new mysqli('localhost', $dbInfo['username'], $dbInfo['password'], $dbInfo['name']);
        if ($connect->connect_error) {
            return false;
        }
        $connect->set_charset("utf8mb4");
        $tableCheck = $connect->query("SHOW TABLES LIKE 'admin'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $result = $connect->query("SELECT COUNT(*) as cnt FROM admin");
            $countRow = $result ? $result->fetch_assoc() : ['cnt' => 0];
            $count = (int)($countRow['cnt'] ?? 0);
            if ($count == 0) {
                $stmt = $connect->prepare("INSERT INTO `admin` (`id_admin`, `username`, `password`, `rule`) VALUES (?, 'admin', '14e9eab674', 'administrator')");
                if ($stmt) {
                    $stmt->bind_param('s', $adminNumber);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $adminNumberEscaped = $connect->real_escape_string($adminNumber);
                $connect->query("UPDATE `admin` SET `id_admin` = '{$adminNumberEscaped}', `username` = 'admin', `password` = '14e9eab674', `rule` = 'administrator' LIMIT 1");
            }
        } else {
            $connect->query("CREATE TABLE `admin` (
              `id_admin` varchar(500) NOT NULL,
              `username` varchar(1000) NOT NULL,
              `password` varchar(1000) NOT NULL,
              `rule` varchar(500) NOT NULL,
              PRIMARY KEY (`id_admin`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci");
            $stmt = $connect->prepare("INSERT INTO `admin` (`id_admin`, `username`, `password`, `rule`) VALUES (?, 'admin', '14e9eab674', 'administrator')");
            if ($stmt) {
                $stmt->bind_param('s', $adminNumber);
                $stmt->execute();
                $stmt->close();
            }
        }
        $connect->close();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
function runCategoryRemarkMigration($dbInfo, &$log) {
    $log = [];
    $host = $dbInfo['host'] ?? 'localhost';
    $user = $dbInfo['username'] ?? '';
    $pass = $dbInfo['password'] ?? '';
    $name = $dbInfo['name'] ?? '';

    $debugFile = __DIR__ . '/category_migration_charset.log';

    try {
        $connection = new mysqli($host, $user, $pass, $name);
        if ($connection->connect_error) {
            $msg = "❌ اتصال دیتابیس برای مهاجرت دسته‌بندی‌ها ممکن نیست: " . $connection->connect_error;
            $log[] = $msg;
            file_put_contents($debugFile, $msg . "\n", FILE_APPEND);
            return false;
        }

        $connection->set_charset('utf8mb4');
        $connection->query("SET NAMES utf8mb4");
        $connection->query("SET CHARACTER SET utf8mb4");
        $connection->query("SET collation_connection = 'utf8mb4_unicode_ci'");

        ensureTableAndColumn($connection, 'category', 'id');
        ensureTableAndColumn($connection, 'category', 'remark');
        ensureTableAndColumn($connection, 'product', 'Category');

        $log[] = "ℹ️ در حال تنظیم charset ستون‌های category.remark و product.Category روی utf8mb4...";

        $sql1 = "ALTER TABLE `category`
                 MODIFY `remark` VARCHAR(191)
                 CHARACTER SET utf8mb4
                 COLLATE utf8mb4_unicode_ci";

        if (!$connection->query($sql1)) {
            $log[] = "⚠️ خطا در تغییر charset category.remark: " . $connection->error;
        }

        $sql2 = "ALTER TABLE `product`
                 MODIFY `Category` VARCHAR(191)
                 CHARACTER SET utf8mb4
                 COLLATE utf8mb4_unicode_ci";

        if (!$connection->query($sql2)) {
            $log[] = "⚠️ خطا در تغییر charset product.Category: " . $connection->error;
        }

        $log[] = "✅ ساختار جداول و ستون‌ها بررسی شد.";

        $categoryMap   = [];
        $categoryResult = $connection->query("SELECT `id`, `remark` FROM `category`");
        if (!$categoryResult) {
            throw new Exception('بارگذاری دسته‌بندی‌ها با خطا مواجه شد: ' . $connection->error);
        }
        while ($row = $categoryResult->fetch_assoc()) {
            $id     = trim((string) ($row['id'] ?? ''));
            $remark = $row['remark'] ?? '';
            if ($id === '') {
                continue;
            }
            $categoryMap[$id] = $remark;
        }
        $categoryResult->free();
        if (empty($categoryMap)) {
            throw new Exception('دسته‌بندی معتبری برای مهاجرت یافت نشد.');
        }

        $total = 0;
        $countResult = $connection->query("SELECT COUNT(*) AS total FROM `product`");
        if ($countResult) {
            $total = (int) ($countResult->fetch_assoc()['total'] ?? 0);
            $countResult->free();
        }
        $log[] = "📊 تعداد محصولات برای بروزرسانی: {$total}";
        file_put_contents($debugFile, "Products total: {$total}\n", FILE_APPEND);

        if ($total === 0) {
            $connection->close();
            return true;
        }

        $connection->autocommit(false);
        $connection->begin_transaction();

        $updateStmt = $connection->prepare(
            "UPDATE `product` SET `Category` = ? WHERE `Category` = ?"
        );
        if (!$updateStmt) {
            throw new Exception('آماده‌سازی پرس‌وجو ناموفق بود: ' . $connection->error);
        }

        $processed = 0;
        $failed    = 0;

        foreach ($categoryMap as $categoryId => $remark) {
            $updateStmt->bind_param('ss', $remark, $categoryId);

            file_put_contents(
                $debugFile,
                "Try UPDATE for ID={$categoryId} , remark=" .
                json_encode($remark, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );

            if (!$updateStmt->execute()) {
                $failed++;
                $msg = "⚠️ خطا در بروزرسانی {$categoryId}: " . $updateStmt->error;
                $log[] = $msg;
                file_put_contents($debugFile, $msg . "\n", FILE_APPEND);
                continue;
            }

            $affected  = $updateStmt->affected_rows;
            $processed += $affected;

            if ($affected > 0) {
                $msg = "✅ {$affected} رکورد برای {$remark} آپدیت شد.";
                $log[] = $msg;
                file_put_contents($debugFile, $msg . "\n", FILE_APPEND);
            }
        }

        $connection->commit();
        $updateStmt->close();
        $connection->close();

        $log[] = "✅ تراکنش ثبت شد (پردازش: {$processed}, خطاها: {$failed}).";
        file_put_contents(
            $debugFile,
            "COMMIT OK (processed={$processed}, failed={$failed})\n",
            FILE_APPEND
        );

        return true;
    } catch (Exception $exception) {
        $msg = "❌ خطای مهاجرت دسته‌بندی‌ها: " . $exception->getMessage();
        $log[] = $msg;
        file_put_contents($debugFile, $msg . "\n", FILE_APPEND);

        if (isset($connection) && $connection instanceof mysqli && $connection->connect_errno === 0) {
            $connection->rollback();
            $connection->close();
            file_put_contents($debugFile, "ROLLBACK\n", FILE_APPEND);
        }
        return false;
    }
}
function ensureTableAndColumn(mysqli $mysqli, string $table, string $column): void {
    $tableEscaped = $mysqli->real_escape_string($table);
    $columnEscaped = $mysqli->real_escape_string($column);
    $tableResult = $mysqli->query("SHOW TABLES LIKE '{$tableEscaped}'");
    if (!$tableResult || $tableResult->num_rows === 0) {
        throw new Exception("جدول {$table} پیدا نشد.");
    }
    $tableResult->free();
    $columnResult = $mysqli->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");
    if (!$columnResult || $columnResult->num_rows === 0) {
        throw new Exception("ستون {$column} در جدول {$table} وجود ندارد.");
    }
    $columnResult->free();
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ نصب خودکار ربات میرزا پرو</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
        * {
            font-family: Arad, sans-serif;
        }
        .wizard-steps {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 30px 0;
            gap: 20px;
        }
        .wizard-step {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .wizard-step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(50, 184, 198, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .wizard-step.active .wizard-step-number {
            background: rgba(50, 184, 198, 0.3);
            border-color: #32b8c6;
            color: #32b8c6;
            box-shadow: 0 0 20px rgba(50, 184, 198, 0.4);
        }
        .wizard-step.completed .wizard-step-number {
            background: rgba(40, 167, 69, 0.3);
            border-color: #28a745;
            color: #28a745;
        }
        .wizard-step-label {
            color: #666;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .wizard-step.active .wizard-step-label {
            color: #32b8c6;
            font-weight: 600;
        }
        .wizard-arrow {
            color: rgba(50, 184, 198, 0.3);
            font-size: 20px;
        }
        .wizard-content {
            min-height: 300px;
        }
        .step-section {
            display: none;
        }
        .step-section.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .wizard-navigation {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }
        .wizard-btn {
            padding: 14px 32px;
            border-radius: 8px;
            border: 2px solid;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-family: Arad, sans-serif;
        }
        .wizard-btn-next {
            background: rgba(50, 184, 198, 0.2);
            border-color: #32b8c6;
            color: #32b8c6;
        }
        .wizard-btn-next:hover {
            background: rgba(50, 184, 198, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(50, 184, 198, 0.3);
        }
        .wizard-btn-prev {
            background: rgba(108, 117, 125, 0.2);
            border-color: #6c757d;
            color: #aaa;
        }
        .wizard-btn-prev:hover {
            background: rgba(108, 117, 125, 0.3);
            transform: translateY(-2px);
        }
        .wizard-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .server-type-selector {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 24px 0;
        }
        @media (max-width: 768px) {
            .server-type-selector {
                grid-template-columns: 1fr;
            }
        }
        .server-type-card {
            border: 2px solid rgba(50, 184, 198, 0.4);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(0, 0, 0, 0.2);
        }
        .server-type-card:hover {
            border-color: #32b8c6;
            background: rgba(50, 184, 198, 0.1);
        }
        .server-type-card.active {
            border-color: #32b8c6;
            background: rgba(50, 184, 198, 0.2);
            box-shadow: 0 0 20px rgba(50, 184, 198, 0.3);
        }
        .server-type-card h3 {
            margin-bottom: 12px;
            color: #32b8c6;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .server-type-card i {
            font-size: 20px;
        }
        .server-type-card p {
            color: #aaa;
            font-size: 13px;
            margin: 0;
        }
        .server-type-card input {
            display: none;
        }
        .install-type-selector {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 24px 0;
        }
        @media (max-width: 768px) {
            .install-type-selector {
                grid-template-columns: 1fr;
            }
        }
        .install-type-card {
            border: 2px solid rgba(50, 184, 198, 0.4);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(0, 0, 0, 0.2);
        }
        .install-type-card:hover {
            border-color: #32b8c6;
            background: rgba(50, 184, 198, 0.1);
        }
        .install-type-card.active {
            border-color: #32b8c6;
            background: rgba(50, 184, 198, 0.2);
            box-shadow: 0 0 20px rgba(50, 184, 198, 0.3);
        }
        .install-type-card h3 {
            margin-bottom: 12px;
            color: #32b8c6;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .install-type-card i {
            font-size: 20px;
        }
        .install-type-card p {
            color: #aaa;
            font-size: 13px;
            margin: 0;
        }
        .install-type-card input {
            display: none;
        }
        #db-backup-question {
            background: rgba(255, 193, 7, 0.1);
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 8px;
            padding: 32px;
            margin-top: 32px;
            margin-bottom: 24px;
        }
        #db-backup-question h3 {
            margin: 0 0 24px 0;
            color: #ffc107;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 12px;
        }
        #db-backup-question i {
            font-size: 22px;
        }
        .backup-btn {
            flex: 1;
            min-width: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 16px 24px;
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(50, 184, 198, 0.4);
            border-radius: 8px;
            color: #fff;
            font-size: 15px;
            font-weight: 500;
            font-family: Arad, sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .backup-btn i {
            font-size: 18px;
        }
        .backup-btn:hover {
            border-color: #32b8c6;
            background: rgba(50, 184, 198, 0.15);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .backup-btn.active {
            border-color: #32b8c6;
            background: rgba(50, 184, 198, 0.25);
            box-shadow: 0 0 20px rgba(50, 184, 198, 0.4);
        }
        .migration-section {
            display: none;
        }
        .migration-section.active {
            display: block;
        }
        .install-field-step {
            display: none;
        }
        .install-field-step.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }
        .install-field-progress {
            text-align: center;
            color: #aaa;
            font-size: 14px;
            margin-top: 15px;
        }
        .install-field-navigation {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
        #install-submit {
            margin-top: 25px;
        }
        @media (max-width: 768px) {
            .wizard-steps {
                flex-direction: column;
                gap: 10px;
            }
            .wizard-arrow {
                transform: rotate(90deg);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-cog"></i> نصب خودکار ربات میرزا پرو</h1>
        <?php if (!empty($ERROR)): ?>
            <div class="alert alert-danger">
                <?php echo implode("<br>",$ERROR); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo implode("<br>",$SUCCESS); ?>
            </div>
            <a class="submit-success" href="https://t.me/<?php echo $tgBot['details']['result']['username']; ?>"><i class="fas fa-robot"></i> رفتن به ربات <?php echo "‎@".$tgBot['details']['result']['username']; ?> »</a>
            <div style="text-align: center; margin-top: 20px; font-size: 18px; color: #28a745;">
                <p>نصب با موفقیت تکمیل شد! <i class="fas fa-gift"></i></p>
                <p>در حال حذف پوشه Installer ...</p>
            </div>
            <script>
                window.location.href = 'delete_installer.php';
            </script>
        <?php endif; ?>
        <form id="installer-form" <?php if($success) { echo 'style="display:none;"'; } ?> method="post" enctype="multipart/form-data">
            <div class="wizard-steps">
                <div class="wizard-step <?php echo ($currentStep === 1) ? 'active' : (($currentStep > 1) ? 'completed' : ''); ?>" data-step="1">
                    <div class="wizard-step-number">1</div>
                    <div class="wizard-step-label">انتخاب نوع سرور</div>
                </div>
                <div class="wizard-arrow"><i class="fas fa-arrow-left"></i></div>
                <div class="wizard-step <?php echo ($currentStep === 2) ? 'active' : (($currentStep > 2) ? 'completed' : ''); ?>" data-step="2">
                    <div class="wizard-step-number">2</div>
                    <div class="wizard-step-label">نوع نصب</div>
                </div>
                <div class="wizard-arrow"><i class="fas fa-arrow-left"></i></div>
                <div class="wizard-step <?php echo ($currentStep === 3) ? 'active' : ''; ?>" data-step="3">
                    <div class="wizard-step-number">3</div>
                    <div class="wizard-step-label">اطلاعات نصب</div>
                </div>
            </div>
            <div class="wizard-content">
                <div class="step-section <?php echo ($currentStep === 1) ? 'active' : ''; ?>" id="step-1">
                    <h2 style="text-align: center; color: #32b8c6; margin-bottom: 20px;">
                        <i class="fas fa-server"></i> انتخاب نوع سرور
                    </h2>
                    <div class="server-type-selector">
                        <div class="server-type-card <?php echo ($serverType === 'cpanel') ? 'active' : ''; ?>" data-server-type="cpanel">
                            <input type="radio" name="server_type" value="cpanel" id="cpanel" <?php echo ($serverType === 'cpanel') ? 'checked' : ''; ?>>
                            <h3><i class="fas fa-server"></i> هاست cPanel</h3>
                            <p>نصب روی هاست cPanel</p>
                        </div>
                        <div class="server-type-card <?php echo ($serverType === 'server') ? 'active' : ''; ?>" data-server-type="server">
                            <input type="radio" name="server_type" value="server" id="server" <?php echo ($serverType === 'server') ? 'checked' : ''; ?>>
                            <h3><i class="fas fa-cloud"></i> سرور</h3>
                            <p>نصب روی سرور (مسیر: /var/www/html/)</p>
                        </div>
                    </div>
                </div>
                <div class="step-section <?php echo ($currentStep === 2) ? 'active' : ''; ?>" id="step-2">
                    <h2 style="text-align: center; color: #32b8c6; margin-bottom: 20px;">
                        <i class="fas fa-cogs"></i> نوع نصب
                    </h2>
                    <div class="install-type-selector" id="install-type-selector">
                        <div class="install-type-card <?php echo ($installType === 'simple') ? 'active' : ''; ?>" data-install-type="simple">
                            <input type="radio" name="install_type" value="simple" id="simple" <?php echo ($installType === 'simple') ? 'checked' : ''; ?>>
                            <h3><i class="fas fa-download"></i> نصب ساده</h3>
                            <p>نصب جدید بدون داده قبلی</p>
                        </div>
                        <div class="install-type-card <?php echo ($installType === 'migrate_free_to_pro') ? 'active' : ''; ?>" data-install-type="migrate_free_to_pro">
                            <input type="radio" name="install_type" value="migrate_free_to_pro" id="migrate_free_to_pro" <?php echo ($installType === 'migrate_free_to_pro') ? 'checked' : ''; ?>>
                            <h3><i class="fas fa-arrow-up"></i> مهاجرت رایگان به پرو</h3>
                            <p>انتقال از نسخه رایگان به پرو</p>
                        </div>
                    </div>
                    <div id="db-backup-question" style="<?php echo ($installType === 'migrate_free_to_pro') ? 'display:block;' : 'display:none;'; ?>">
                        <h3>
                            <i class="fas fa-question-circle"></i>
                            آیا دیتابیس بکاپ از قبل وارد شده است
                        </h3>
                        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 20px;">
                            <button type="button" class="backup-btn <?php echo ($hasDbBackup === 'yes') ? 'active' : ''; ?>" data-value="yes">
                                <i class="fas fa-check"></i> بله، از قبل وارد کرده‌ام
                            </button>
                            <button type="button" class="backup-btn <?php echo ($hasDbBackup === 'no') ? 'active' : ''; ?>" data-value="no">
                                <i class="fas fa-upload"></i> خیر، نیاز به آپلود دارم
                            </button>
                        </div>
                        <input type="hidden" name="has_db_backup" id="has_db_backup" value="<?php echo escapeHtml($hasDbBackup); ?>">
                    </div>
                    <?php $migrationActive = needsBackupUpload($installType, $hasDbBackup); ?>
                    <div class="migration-section <?php echo $migrationActive ? 'active' : ''; ?>" id="migration-section" style="<?php echo $migrationActive ? 'display:block;' : 'display:none;'; ?>">
                        <h3><i class="fas fa-file-archive"></i> آپلود فایل بکاپ</h3>
                        <div class="file-upload">
                            <label for="backup_file"><i class="fas fa-folder-open"></i> فایل بکاپ دیتابیس (SQL یا ZIP):</label>
                            <input type="file" name="backup_file" id="backup_file">
                            <small style="display: block; margin-top: 5px; color: #666;">
                                ⚠️ فرمت‌های پشتیبانی شده: .sql یا .zip (شامل فایل SQL). در اندروید، اگر .sql انتخاب نشد، فایل SQL را زیپ کنید و آپلود نمایید.
                            </small>
                        </div>
                    </div>
                </div>
                <div class="step-section <?php echo ($currentStep === 3) ? 'active' : ''; ?>" id="step-3">
                    <h2 style="text-align: center; color: #32b8c6; margin-bottom: 20px;">
                        <i class="fas fa-database"></i> اطلاعات نصب
                    </h2>
                    <div class="install-field-step <?php echo ($currentInstallField === 1) ? 'active' : ''; ?>" data-field-step="1">
                        <div class="form-group">
                            <label for="admin_id"><i class="fas fa-user"></i> آیدی عددی ادمین:</label>
                            <input type="text" id="admin_id" name="admin_id"
                                   placeholder="ADMIN TELEGRAM #Id" value="<?php echo escapeHtml($uPOST['admin_id'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="install-field-step <?php echo ($currentInstallField === 2) ? 'active' : ''; ?>" data-field-step="2">
                        <div class="form-group">
                            <label for="tg_bot_token"><i class="fas fa-key"></i> توکن ربات تلگرام:</label>
                            <input type="text" id="tg_bot_token" name="tg_bot_token"
                                   placeholder="BOT TOKEN" value="<?php echo escapeHtml($uPOST['tg_bot_token'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="install-field-step <?php echo ($currentInstallField === 3) ? 'active' : ''; ?>" data-field-step="3">
                        <div class="form-group">
                            <label for="database_username"><i class="fas fa-user"></i> نام کاربری دیتابیس:</label>
                            <input type="text" id="database_username" name="database_username"
                                   placeholder="DATABASE USERNAME" value="<?php echo escapeHtml($uPOST['database_username'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="install-field-step <?php echo ($currentInstallField === 4) ? 'active' : ''; ?>" data-field-step="4">
                        <div class="form-group">
                            <label for="database_password"><i class="fas fa-lock"></i> رمز عبور دیتابیس:</label>
                            <input type="text" id="database_password" name="database_password"
                                   placeholder="DATABASE PASSWORD" value="<?php echo escapeHtml($uPOST['database_password'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="install-field-step <?php echo ($currentInstallField === 5) ? 'active' : ''; ?>" data-field-step="5">
                        <div class="form-group">
                            <label for="database_name"><i class="fas fa-database"></i> نام دیتابیس:</label>
                            <input type="text" id="database_name" name="database_name"
                                   placeholder="DATABASE NAME" value="<?php echo escapeHtml($uPOST['database_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="install-field-step <?php echo ($currentInstallField === 6) ? 'active' : ''; ?>" data-field-step="6">
                        <div class="form-group">
                            <details>
                                <summary for="secret_key"><i class="fas fa-globe"></i> آدرس سورس ربات</summary>
                                <label for="bot_address_webhook">آدرس صفحه سورس ربات (نه installer! مثال: https:
                                <input type="text" id="bot_address_webhook" name="bot_address_webhook"
                                       placeholder="https://yourdomain.com/mirzabotconfig/index.php"
                                       value="<?php echo escapeHtml($uPOST['bot_address_webhook'] ?? ($webAddress.'/index.php')); ?>" required>
                            </details>
                        </div>
                    </div>
                    <div class="install-field-step <?php echo ($currentInstallField === 7) ? 'active' : ''; ?>" data-field-step="7">
                        <div class="form-group">
                            <label for="remove_directory"><i class="fas fa-exclamation-triangle" style="color:#f30;"></i> <b style="color:#f30;">هشدار:</b> حذف خودکار اسکریپت نصب‌کننده پس از نصب موفقیت‌آمیز</label>
                            <label for="remove_directory" style="font-size: 14px;font-weight: normal;text-indent: 20px;">برای امنیت بیشتر، بعد از اتمام نصب ربات پوشه Installer حذف خواهد شد. </label>
                        </div>
                    </div>
                    <div class="install-field-progress">
                        <span>فیلد <span id="install-progress"><?php echo $currentInstallField . ' / ' . $installFieldTotal; ?></span></span>
                    </div>
                    <div class="install-field-navigation">
                        <button type="button" class="wizard-btn wizard-btn-prev" id="install-prev-btn" style="<?php echo ($currentInstallField <= 1) ? 'display:none;' : ''; ?>">
                            <i class="fas fa-arrow-right"></i> فیلد قبل
                        </button>
                        <button type="button" class="wizard-btn wizard-btn-next" id="install-next-btn" style="<?php echo ($currentInstallField >= $installFieldTotal) ? 'display:none;' : ''; ?>">
                            فیلد بعد <i class="fas fa-arrow-left"></i>
                        </button>
                    </div>
                    <div class="install-submit" id="install-submit" style="<?php echo ($currentInstallField >= $installFieldTotal) ? 'display:block;' : 'display:none;'; ?>">
                        <button type="submit" name="submit" value="submit"><i class="fas fa-rocket"></i> نصب ربات</button>
                    </div>
                </div>
            </div>
            <div class="wizard-navigation">
                <button type="button" class="wizard-btn wizard-btn-prev" id="prev-btn" style="<?php echo ($currentStep <= 1) ? 'display:none;' : ''; ?>">
                    <i class="fas fa-arrow-right"></i> مرحله قبل
                </button>
                <button type="button" class="wizard-btn wizard-btn-next" id="next-btn" style="<?php echo ($currentStep >= 3) ? 'display:none;' : ''; ?>">
                    مرحله بعد <i class="fas fa-arrow-left"></i>
                </button>
            </div>
            <input type="hidden" name="current_step" id="current_step" value="<?php echo $currentStep; ?>">
            <input type="hidden" name="current_install_field" id="current_install_field" value="<?php echo $currentInstallField; ?>">
        </form>
        <footer>
            <p>MirzabotPro Installer , Made by ♥️ | <a href="https://github.com/ishantia/mirza_pro/releases/">Github</a> | <a href="https://t.me/+COMDGvsapck0NzE0">Telegram</a> | &copy; <?php echo date('Y'); ?></p>
        </footer>
    </div>
        <script>
        let currentStep = <?php echo (int)$currentStep; ?>;
        const totalWizardSteps = 3;
        let currentInstallField = <?php echo (int)$currentInstallField; ?>;
        let installFieldSteps = [];
        let totalInstallFields = <?php echo $installFieldTotal; ?>;

        function selectServerType(type) {
            var serverInputs = document.querySelectorAll('input[name="server_type"]');
            serverInputs.forEach(function(input) {
                input.checked = (input.value === type);
            });
            document.querySelectorAll('.server-type-card').forEach(function(card) {
                card.classList.toggle('active', card.dataset.serverType === type);
            });
            updateInstallTypes();
        }

        function selectInstallType(type, skipToggle) {
            var installInputs = document.querySelectorAll('input[name="install_type"]');
            installInputs.forEach(function(input) {
                input.checked = (input.value === type);
            });
            document.querySelectorAll('.install-type-card').forEach(function(card) {
                card.classList.toggle('active', card.dataset.installType === type);
            });
            if (type === 'migrate_free_to_pro') {
                var hasBackupInput = document.getElementById('has_db_backup');
                var currentValue = hasBackupInput ? hasBackupInput.value : 'no';
                handleBackupChoice(currentValue, true);
            }
            if (!skipToggle) {
                toggleBackupUpload();
            } else if (type === 'migrate_free_to_pro') {
                toggleBackupUpload();
            }
        }

        function updateInstallTypes() {
            var serverInput = document.querySelector('input[name="server_type"]:checked');
            var serverType = serverInput ? serverInput.value : 'cpanel';
            var simpleCard = document.querySelector('.install-type-card[data-install-type="simple"]');
            var migrateCard = document.querySelector('.install-type-card[data-install-type="migrate_free_to_pro"]');
            if (serverType === 'server') {
                if (simpleCard) simpleCard.style.display = 'none';
                if (migrateCard) migrateCard.style.display = 'block';
                var selected = document.querySelector('input[name="install_type"]:checked');
                if (!selected || selected.value !== 'migrate_free_to_pro') {
                    selectInstallType('migrate_free_to_pro', true);
                }
            } else {
                if (simpleCard) simpleCard.style.display = 'block';
                if (migrateCard) migrateCard.style.display = 'block';
            }
            toggleBackupUpload();
        }

        function handleBackupChoice(value, skipToggle) {
            var hasBackupInput = document.getElementById('has_db_backup');
            if (hasBackupInput) {
                hasBackupInput.value = value;
            }
            document.querySelectorAll('.backup-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.dataset.value === value);
            });
            if (!skipToggle) {
                toggleBackupUpload();
            }
        }

        function toggleBackupUpload() {
            var installTypeInput = document.querySelector('input[name="install_type"]:checked');
            var hasBackupInput = document.getElementById('has_db_backup');
            var dbQuestion = document.getElementById('db-backup-question');
            var migrationSection = document.getElementById('migration-section');
            var isMigration = installTypeInput && installTypeInput.value === 'migrate_free_to_pro';
            var hasBackup = hasBackupInput ? hasBackupInput.value : 'no';
            if (dbQuestion) {
                dbQuestion.style.display = isMigration ? 'block' : 'none';
            }
            if (migrationSection) {
                var needsUpload = isMigration && hasBackup === 'no';
                migrationSection.style.display = needsUpload ? 'block' : 'none';
                migrationSection.classList.toggle('active', needsUpload);
            }
        }

        function changeInstallField(delta) {
            if (!installFieldSteps.length) {
                return;
            }
            if (delta > 0 && !validateInstallField(currentInstallField)) {
                return;
            }
            var next = currentInstallField + delta;
            if (next < 1 || next > totalInstallFields) {
                return;
            }
            currentInstallField = next;
            updateInstallFieldSteps();
        }

        function validateInstallField(stepIndex) {
            var step = installFieldSteps[stepIndex - 1];
            if (!step) {
                return true;
            }
            var inputs = step.querySelectorAll('input[required], select[required], textarea[required]');
            for (var i = 0; i < inputs.length; i++) {
                var input = inputs[i];
                var needsValue = (input.type === 'text' || input.type === 'password' || input.type === 'tel' || input.type === 'email' || input.type === 'url' || input.type === 'search' || input.type === 'number');
                if (needsValue && input.value.trim() === '') {
                    input.reportValidity();
                    return false;
                }
                if ((input.tagName === 'SELECT' || input.tagName === 'TEXTAREA') && input.value.trim() === '') {
                    input.reportValidity();
                    return false;
                }
                if (input.type === 'file' && !input.files.length) {
                    input.reportValidity();
                    return false;
                }
            }
            return true;
        }

        function updateInstallFieldSteps() {
            if (!installFieldSteps.length) {
                return;
            }
            if (currentInstallField < 1) {
                currentInstallField = 1;
            }
            if (currentInstallField > totalInstallFields) {
                currentInstallField = totalInstallFields;
            }
            installFieldSteps.forEach(function(step, index) {
                step.classList.toggle('active', index === currentInstallField - 1);
            });
            var prevBtn = document.getElementById('install-prev-btn');
            var nextBtn = document.getElementById('install-next-btn');
            var submitBlock = document.getElementById('install-submit');
            var progress = document.getElementById('install-progress');
            var hiddenField = document.getElementById('current_install_field');
            if (prevBtn) {
                prevBtn.style.display = currentInstallField === 1 ? 'none' : 'inline-flex';
            }
            if (nextBtn) {
                nextBtn.style.display = currentInstallField === totalInstallFields ? 'none' : 'inline-flex';
            }
            if (submitBlock) {
                submitBlock.style.display = currentInstallField === totalInstallFields ? 'block' : 'none';
            }
            if (progress) {
                progress.textContent = currentInstallField + ' / ' + totalInstallFields;
            }
            if (hiddenField) {
                hiddenField.value = currentInstallField;
            }
        }

        function updateWizardDisplay() {
            if (currentStep < 1) {
                currentStep = 1;
            }
            if (currentStep > totalWizardSteps) {
                currentStep = totalWizardSteps;
            }
            document.querySelectorAll('.wizard-step').forEach(function(step) {
                var stepNum = parseInt(step.dataset.step, 10);
                step.classList.remove('active');
                step.classList.remove('completed');
                if (stepNum === currentStep) {
                    step.classList.add('active');
                } else if (stepNum < currentStep) {
                    step.classList.add('completed');
                }
            });
            document.querySelectorAll('.step-section').forEach(function(section) {
                section.classList.toggle('active', section.id === 'step-' + currentStep);
            });
            if (currentStep === totalWizardSteps) {
                updateInstallFieldSteps();
            }
            var prevBtn = document.getElementById('prev-btn');
            var nextBtn = document.getElementById('next-btn');
            if (prevBtn) {
                prevBtn.style.display = currentStep === 1 ? 'none' : 'inline-flex';
            }
            if (nextBtn) {
                nextBtn.style.display = currentStep === totalWizardSteps ? 'none' : 'inline-flex';
            }
            var hiddenStep = document.getElementById('current_step');
            if (hiddenStep) {
                hiddenStep.value = currentStep;
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        document.addEventListener('DOMContentLoaded', function() {
            installFieldSteps = Array.from(document.querySelectorAll('.install-field-step'));
            if (installFieldSteps.length) {
                totalInstallFields = installFieldSteps.length;
            }
            updateInstallFieldSteps();
            updateWizardDisplay();
            updateInstallTypes();

            document.querySelectorAll('.server-type-card').forEach(function(card) {
                card.addEventListener('click', function() {
                    selectServerType(card.dataset.serverType);
                });
            });
            document.querySelectorAll('.install-type-card').forEach(function(card) {
                card.addEventListener('click', function() {
                    selectInstallType(card.dataset.installType);
                });
            });
            document.querySelectorAll('.backup-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    handleBackupChoice(btn.dataset.value);
                });
            });

            var installNextBtn = document.getElementById('install-next-btn');
            var installPrevBtn = document.getElementById('install-prev-btn');
            if (installNextBtn) {
                installNextBtn.addEventListener('click', function() {
                    changeInstallField(1);
                });
            }
            if (installPrevBtn) {
                installPrevBtn.addEventListener('click', function() {
                    changeInstallField(-1);
                });
            }

            var nextBtn = document.getElementById('next-btn');
            var prevBtn = document.getElementById('prev-btn');
            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    if (currentStep < totalWizardSteps) {
                        currentStep++;
                        updateWizardDisplay();
                    }
                });
            }
            if (prevBtn) {
                prevBtn.addEventListener('click', function() {
                    if (currentStep > 1) {
                        currentStep--;
                        updateWizardDisplay();
                    }
                });
            }

            var hasBackupInput = document.getElementById('has_db_backup');
            if (hasBackupInput) {
                handleBackupChoice(hasBackupInput.value || 'no', true);
            }
            toggleBackupUpload();

            var installerForm = document.getElementById('installer-form');
            if (installerForm) {
                installerForm.addEventListener('submit', function() {
                    var stepField = document.getElementById('current_step');
                    var fieldField = document.getElementById('current_install_field');
                    if (stepField) {
                        stepField.value = currentStep;
                    }
                    if (fieldField) {
                        fieldField.value = currentInstallField;
                    }
                });
            }

        });
    </script>
</body>
</html>
<?php
function getContents($url) {
    $context = stream_context_create([
        'http' => ['timeout' => 30],
        'https' => ['timeout' => 30],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['ok' => false];
    }
    $decoded = json_decode($response, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false];
    }
    return $decoded;
}
function isValidTelegramToken($token) {
    return preg_match('/^\d{6,12}:[A-Za-z0-9_-]{35}$/', $token);
}
function isValidTelegramId($id) {
    return preg_match('/^\d{6,12}$/', $id);
}
function sanitizeInput(&$INPUT, array $options = []) {
    $defaultOptions = [
        'allow_html' => false,
        'allowed_tags' => '',
        'remove_spaces' => false,
        'connection' => null,
        'max_length' => 0,
        'encoding' => 'UTF-8'
    ];
    $options = array_merge($defaultOptions, $options);
    if (is_array($INPUT)) {
        return array_map(function($item) use ($options) {
            return sanitizeInput($item, $options);
        }, $INPUT);
    }
    if ($INPUT === null || $INPUT === false) {
        return '';
    }
    $INPUT = trim((string)$INPUT);
    $INPUT = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $INPUT);
    if ($options['max_length'] > 0) {
        $INPUT = mb_substr($INPUT, 0, $options['max_length'], $options['encoding']);
    }
    if (!$options['allow_html']) {
        $INPUT = strip_tags($INPUT);
    } elseif (!empty($options['allowed_tags'])) {
        $INPUT = strip_tags($INPUT, $options['allowed_tags']);
    }
    if ($options['remove_spaces']) {
        $INPUT = preg_replace('/\s+/', ' ', trim($INPUT));
    }
    if ($options['connection'] instanceof mysqli) {
        $INPUT = $options['connection']->real_escape_string($INPUT);
    }
    return $INPUT;
}
function normalizeDomainAddress($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $parsedUrl = parse_url($url);
    if (empty($parsedUrl['host'])) {
        return null;
    }
    $path = $parsedUrl['path'] ?? '';
    $path = preg_replace('#/index\.php$#i', '', $path);
    $path = preg_replace('#/installer/?$#', '', $path);
    $path = rtrim($path, '/');
    $path = ltrim($path, '/');
    $address = $parsedUrl['host'];
    if ($path !== '') {
        $address .= '/' . $path;
    }
    return [
        'address' => $address
    ];
}
function updateConfigValues($configContents, array $placeholderValues, &$replacementCount = 0) {
    $replacementCount = 0;
    $configData = str_replace(array_keys($placeholderValues), array_values($placeholderValues), $configContents, $placeholderReplacementCount);
    if ($placeholderReplacementCount > 0) {
        $replacementCount += $placeholderReplacementCount;
    }
    $variableMap = [
        'dbname' => $placeholderValues['{database_name}'] ?? '',
        'usernamedb' => $placeholderValues['{username_db}'] ?? '',
        'passworddb' => $placeholderValues['{password_db}'] ?? '',
        'APIKEY' => $placeholderValues['{API_KEY}'] ?? '',
        'adminnumber' => $placeholderValues['{admin_number}'] ?? '',
        'domainhosts' => $placeholderValues['{domain_name}'] ?? '',
        'usernamebot' => $placeholderValues['{username_bot}'] ?? '',
    ];
    $updatedConfig = $configData;
    foreach ($variableMap as $variable => $value) {
        $pattern = '/(\$' . preg_quote($variable, '/') . '\s*=\s*)([\'\"])(.*?)(\2)(\s*;)([^\n]*)(\n?)/u';
        $updatedConfig = preg_replace_callback(
            $pattern,
            function ($matches) use ($value, &$replacementCount) {
                $replacementCount++;
                $quoteChar = $matches[2];
                $formattedValue = formatConfigValue($value, $quoteChar);
                return $matches[1] . $formattedValue . $matches[5] . $matches[6] . $matches[7];
            },
            $updatedConfig,
            1
        );
    }
    return $updatedConfig;
}
function escapeHtml($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function formatConfigValue($value, $quoteChar = '\'') {
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($quoteChar !== "'" && $quoteChar !== '"') {
        $quoteChar = "'";
    }
    $stringValue = (string) $value;
    $escapedValue = addcslashes($stringValue, "\\$quoteChar");
    return $quoteChar . $escapedValue . $quoteChar;
}
?>
