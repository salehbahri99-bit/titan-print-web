<?php
/* ============================================================
   Titan Print — upload.php
   يستقبل ملفاً (أي نوع آمن حتى 100MB) ويعيد رابطاً مباشراً دائماً.
   متوافق 100% مع زر «📎 أي ملف» في التطبيق.

   خطوات مهمة بعد الرفع على public_html:
   1) غيّر $SECRET أدناه إلى قيمة سرية خاصة بك.
   2) في التطبيق: الإعدادات ⚙ ← تبويب «الرفع والمفاتيح»:
        - رابط upload.php  = https://نطاقك/upload.php
        - المفتاح السري     = نفس قيمة $SECRET بالضبط
   3) لرفع ملفات كبيرة: ارفع .htaccess المرفق أيضاً (أو اضبط حدود PHP من hPanel).
   ============================================================ */

$SECRET = 'CHANGE-ME-titan-2025';   // ← غيّرها (نفس القيمة تُوضع في إعدادات التطبيق)
$MAX_MB = 100;                        // الحد الأقصى لحجم الملف بالميغابايت

/* ---------- ترويسات ورد JSON ---------- */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Titan-Key, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function out($ok, $extra = []) {
  echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- التحقق من المفتاح ---------- */
$key = $_SERVER['HTTP_X_TITAN_KEY'] ?? ($_POST['key'] ?? '');
if (!is_string($key) || !hash_equals($SECRET, $key)) {
  http_response_code(401); out(false, ['error' => 'مفتاح غير صحيح']);
}

/* ---------- وجود الملف (فراغ $_FILES غالباً = تجاوز post_max_size) ---------- */
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
  http_response_code(400);
  out(false, ['error' => 'لا ملف أو الحجم يتجاوز حد الخادم (post_max_size=' . ini_get('post_max_size') . ')']);
}
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
  out(false, ['error' => 'خطأ رفع رقم ' . $f['error'] . ' — راجع upload_max_filesize']);
}

/* ---------- حد الحجم ---------- */
if ($f['size'] > $MAX_MB * 1024 * 1024) {
  http_response_code(413); out(false, ['error' => 'الحجم يتجاوز ' . $MAX_MB . 'MB']);
}

/* ---------- منع الملفات القابلة للتنفيذ (حماية إلزامية) ---------- */
$orig = (string)$f['name'];
$ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$blocked = ['php','php2','php3','php4','php5','php7','php8','phtml','pht','phar','phps',
            'cgi','pl','py','sh','asp','aspx','jsp','exe','bat','com','htaccess',
            'htm','html','shtml','svgz'];
if ($ext === '' || in_array($ext, $blocked, true)) {
  http_response_code(415); out(false, ['error' => 'نوع ملف غير مسموح لأسباب أمنية: .' . $ext]);
}

/* ---------- مجلد الرفع ---------- */
$dir = __DIR__ . '/uploads';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
  http_response_code(500); out(false, ['error' => 'تعذّر إنشاء مجلد الرفع']);
}

/* ---------- اسم آمن وفريد (يدعم العربية) ---------- */
$base = $_POST['name'] ?? pathinfo($orig, PATHINFO_FILENAME);
$base = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', (string)$base);
$base = trim($base, '_');
if ($base === '') $base = 'file';
$base = mb_substr($base, 0, 60);
$fname = $base . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
$dest  = $dir . '/' . $fname;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
  http_response_code(500); out(false, ['error' => 'تعذّر حفظ الملف على الخادم']);
}

/* ---------- بناء الرابط المباشر ---------- */
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$url       = $scheme . '://' . $host . $scriptDir . '/uploads/' . rawurlencode($fname);

out(true, ['url' => $url, 'name' => $orig]);
