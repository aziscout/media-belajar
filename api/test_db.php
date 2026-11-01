<?php
/**
 * Database Diagnostic & Test Tool
 * URL: https://domainanda.com/api/test_db.php
 * Digunakan untuk menguji apakah koneksi database dan tabel di hosting sudah berjalan normal.
 */

// Aktifkan error reporting agar semua pesan tampil jelas
ini_set('display_errors', '1');
error_reporting(E_ALL);

$logFile = __DIR__ . '/db_error.log';

// Tindakan clear log jika diminta
if (isset($_GET['clear_log'])) {
    @file_put_contents($logFile, '');
    header('Location: test_db.php');
    exit;
}

// 1. Cek keberadaan file .env
$envPaths = [
    'api/.env' => __DIR__ . '/.env',
    'root .env' => dirname(__DIR__) . '/.env'
];
$foundEnv = null;
foreach ($envPaths as $label => $path) {
    if (file_exists($path) && is_readable($path)) {
        $foundEnv = $label . " ($path)";
        break;
    }
}

// 2. Load config
require_once __DIR__ . '/config.php';

$host = DB_HOST;
$port = DB_PORT;
$dbname = DB_NAME;
$user = DB_USER;
$pass = DB_PASS;
$passLen = strlen($pass);
$maskedPass = $passLen > 0 ? str_repeat('*', min(8, $passLen)) . " (" . $passLen . " karakter)" : "<span style='color:red;'>KOSONG (EMPTY)</span>";

// 3. Uji Koneksi Database
$connSuccess = false;
$connError = null;
$pdo = null;
$tablesStatus = [];
$serverInfo = '';

if (empty($dbname) || empty($user)) {
    $connError = "DB_NAME atau DB_USER masih kosong! Pastikan file api/.env sudah diisi.";
    logDbError("Test DB Gagal: $connError");
} else {
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5
        ]);
        $connSuccess = true;
        $serverInfo = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        
        // Log keberhasilan
        $time = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$time] [TEST_DB] SUKSES: Berhasil terhubung ke database $dbname dengan user $user (MySQL $serverInfo)" . PHP_EOL, FILE_APPEND);

        // Periksa tabel utama
        $checkTables = ['media', 'mata_pelajaran', 'kompetensi_keahlian', 'users', 'profiles', 'ratings'];
        foreach ($checkTables as $tbl) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
                $tablesStatus[$tbl] = [
                    'status' => 'OK',
                    'count'  => (int)$count
                ];
            } catch (\Exception $ex) {
                $tablesStatus[$tbl] = [
                    'status' => 'Tabel Belum Ada',
                    'error'  => $ex->getMessage()
                ];
            }
        }
    } catch (\PDOException $e) {
        $connError = $e->getMessage();
        logDbError("Test DB PDOException: $connError (Host: $host, DB: $dbname, User: $user)");

        // Jika error 1044 (Access denied to database), coba deteksi database yang sebenarnya diizinkan untuk user ini
        $availableDatabases = [];
        try {
            $dsnNoDb = "mysql:host=$host;port=$port;charset=utf8mb4";
            $rawPdo = new PDO($dsnNoDb, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
            $dbs = $rawPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dbs as $d) {
                if (!in_array($d, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                    $availableDatabases[] = $d;
                }
            }
        } catch (\Exception $exNoDb) {
            // Gagal koneksi user/pass dasar
        }
    }
}

// Baca isi log terbaru
$recentLogs = file_exists($logFile) ? file_get_contents($logFile) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnosa Database — Media Belajar</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; padding: 24px; margin: 0; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 28px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
        h1 { font-size: 24px; margin-top: 0; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; }
        .badge { display: inline-block; padding: 6px 14px; font-weight: 700; border-radius: 9999px; font-size: 14px; }
        .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .section { margin-top: 24px; }
        .section-title { font-size: 16px; font-weight: 700; color: #334155; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 14px; }
        th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; color: #475569; }
        .alert { padding: 14px 18px; border-radius: 10px; margin-top: 14px; font-size: 14px; line-height: 1.5; }
        .alert-danger { background: #fef2f2; border: 1px solid #f87171; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        pre { background: #0f172a; color: #e2e8f0; padding: 14px; border-radius: 10px; font-size: 12px; overflow-x: auto; max-height: 250px; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; background: #0284c7; color: #fff; border: none; cursor: pointer; }
        .btn-secondary { background: #64748b; }
    </style>
</head>
<body>
<div class="container">
    <h1>Diagnosa & Status Koneksi Database (Hostinger)</h1>

    <!-- STATUS UTAMA -->
    <div style="margin-bottom: 20px;">
        Status Koneksi: 
        <?php if ($connSuccess): ?>
            <span class="badge badge-success">✓ BERHASIL TERHUBUNG</span>
        <?php else: ?>
            <span class="badge badge-danger">✗ GAGAL TERHUBUNG (ERROR)</span>
        <?php endif; ?>
    </div>

    <?php if ($connSuccess): ?>
        <div class="alert alert-success">
            <strong>Koneksi Database Sukses!</strong><br>
            Server MySQL / MariaDB versi: <strong><?= htmlspecialchars($serverInfo) ?></strong>.<br>
            Backend PHP berhasil membaca file konfigurasi dan berkomunikasi dengan database.
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <strong>Pesan Error MySQL:</strong><br>
            <code><?= htmlspecialchars($connError) ?></code>
            <br><br>
            <strong>Solusi Cepat di Hostinger:</strong>
            <ul style="margin: 6px 0 0 16px; padding: 0;">
                <?php if (str_contains($connError, 'Access denied')): ?>
                    <li>Pesan <em>Access denied</em> berarti <strong>DB_USER</strong> atau <strong>DB_PASS</strong> di file <code>api/.env</code> salah. Buka File Manager Hostinger -> <code>api/.env</code> -> sesuaikan username & password.</li>
                <?php elseif (str_contains($connError, 'Unknown database')): ?>
                    <li>Pesan <em>Unknown database</em> berarti <strong>DB_NAME</strong> (<code><?= htmlspecialchars($dbname) ?></code>) tidak ditemukan. Cek kembali nama database di menu MySQL Databases Hostinger (biasanya berawalan <code>uXXXXXX_...</code>).</li>
                <?php elseif (str_contains($connError, 'Connection refused') || str_contains($connError, 'No such file')): ?>
                    <li>Pastikan <strong>DB_HOST</strong> di <code>api/.env</code> diisi <code>"localhost"</code> dan <strong>DB_PORT</strong> diisi <code>"3306"</code>.</li>
                <?php else: ?>
                    <li>Periksa kembali file <code>public_html/api/.env</code> dan pastikan database sudah dibuat di Hostinger hPanel.</li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- PARAMETER TERBACA -->
    <div class="section">
        <div class="section-title">Konfigurasi yang Terbaca dari <code>.env</code></div>
        <table>
            <tr><th width="35%">Parameter</th><th>Nilai Terdeteksi</th></tr>
            <tr><td>File .env yang dimuat</td><td><?= htmlspecialchars($foundEnv ?? 'TIDAK DITEMUKAN (.env hilang)') ?></td></tr>
            <tr><td>DB_HOST</td><td><code><?= htmlspecialchars($host) ?></code></td></tr>
            <tr><td>DB_PORT</td><td><code><?= htmlspecialchars($port) ?></code></td></tr>
            <tr><td>DB_NAME</td><td><code><?= htmlspecialchars($dbname) ?></code></td></tr>
            <tr><td>DB_USER</td><td><code><?= htmlspecialchars($user) ?></code></td></tr>
            <tr><td>DB_PASS (Status)</td><td><?= $maskedPass ?></td></tr>
        </table>
    </div>

    <!-- TABEL DI DATABASE -->
    <?php if ($connSuccess): ?>
    <div class="section">
        <div class="section-title">Status Tabel Database (Hasil Import SQL)</div>
        <table>
            <tr><th>Nama Tabel</th><th>Status</th><th>Jumlah Data</th></tr>
            <?php foreach ($tablesStatus as $tbl => $info): ?>
                <tr>
                    <td><code><?= htmlspecialchars($tbl) ?></code></td>
                    <td>
                        <?php if ($info['status'] === 'OK'): ?>
                            <span style="color:#15803d; font-weight:600;">✓ Ada</span>
                        <?php else: ?>
                            <span style="color:#b91c1c; font-weight:600;">✗ Belum Ada (Harap Import SQL)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= isset($info['count']) ? number_format($info['count']) . ' baris' : '-' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if (isset($tablesStatus['media']) && $tablesStatus['media']['status'] !== 'OK'): ?>
            <div class="alert alert-danger" style="margin-top: 10px;">
                <strong>Perhatian:</strong> Tabel <code>media</code> belum ada di database! Anda harus membuka phpMyAdmin di Hostinger dan meng-import berkas <code>u798354071_media_belajar.sql</code>.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- LOG ERROR FILE -->
    <div class="section">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div class="section-title">Riwayat Log Error (<code>api/db_error.log</code>)</div>
            <?php if (!empty($recentLogs)): ?>
                <a href="test_db.php?clear_log=1" class="btn btn-secondary" style="padding: 4px 10px; font-size:12px;">Hapus Log</a>
            <?php endif; ?>
        </div>
        <?php if (!empty($recentLogs)): ?>
            <pre><?= htmlspecialchars($recentLogs) ?></pre>
        <?php else: ?>
            <p style="color:#64748b; font-size:14px; margin-top:8px;">Belum ada catatan error di <code>api/db_error.log</code>.</p>
        <?php endif; ?>
    </div>

    <div style="margin-top: 24px; text-align: center;">
        <a href="test_db.php" class="btn">Segarkan / Uji Ulang (Refresh)</a>
        <a href="/" class="btn btn-secondary" style="margin-left: 8px;">Kembali ke Halaman Utama</a>
    </div>
</div>
</body>
</html>
