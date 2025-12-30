<?php
// Increase max execution time to prevent timeouts during deep disk scan
set_time_limit(300);

/**
 * Define Absolute Paths
 * These are the standard locations on most Linux distros (Ubuntu/Debian/CentOS).
 */
$cmd_uptime = '/usr/bin/uptime';
$cmd_free   = '/usr/bin/free';

// Execution with full paths
$os = PHP_OS;
if (file_exists('/etc/os-release')) {
    $osInfo = parse_ini_file('/etc/os-release');
    if ($osInfo && isset($osInfo['PRETTY_NAME'])) {
        $os = $osInfo['PRETTY_NAME'];
    }
}
$hostname = gethostname();
$remoteIP = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$uptime = shell_exec("$cmd_uptime -p") ?? 'Uptime data unavailable';
$phpUser = shell_exec('whoami');

// Memory Logic
$free_output = shell_exec("$cmd_free -m");
if ($free_output) {
    $lines = explode("\n", trim($free_output));
    $mem_line = preg_split('/\s+/', $lines[1]); 
    $memTotalVal = $mem_line[1];
    $memAvailableVal = isset($mem_line[6]) ? $mem_line[6] : $mem_line[3];
    $memoryTotal = $memTotalVal . " MB";
    $memoryAvailable  = $memAvailableVal . " MB";
    $memoryPercent = ($memTotalVal > 0) ? round(($memAvailableVal / $memTotalVal) * 100, 1) : 0;

    // Swap Logic
    $swapTotal = $swapUsed = "0 MB";
    $swapPercent = 0;
    if (isset($lines[2]) && strpos($lines[2], 'Swap:') !== false) {
        $swap_line = preg_split('/\s+/', $lines[2]);
        $swapTotalVal = $swap_line[1];
        $swapUsedVal = $swap_line[2];
        $swapTotal = $swapTotalVal . " MB";
        $swapUsed = $swapUsedVal . " MB";
        $swapPercent = ($swapTotalVal > 0) ? round(($swapUsedVal / $swapTotalVal) * 100, 1) : 0;
    }
} else {
    $memoryTotal = $memoryAvailable = $swapTotal = $swapUsed = "Unknown";
    $memoryPercent = $swapPercent = 0;
}

// Disk and CPU (Native PHP, no paths needed)
$disks = [];
$dfOutput = shell_exec('df -P -B 1 | grep -vE "^Filesystem|tmpfs|cdrom|overlay|squashfs|devtmpfs|run|docker|^/dev/loop"');
if ($dfOutput) {
    foreach (explode("\n", trim($dfOutput)) as $line) {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 6) {
            $total = $parts[1];
            $used = $parts[2];
            $mount = $parts[5];
            $disks[] = [
                'mount' => $mount,
                'total' => round($total / (1024**3), 2),
                'used' => round($used / (1024**3), 2),
                'percent' => ($total > 0) ? round(($used / $total) * 100, 1) : 0
            ];
        }
    }
}
if (empty($disks)) {
    $total = disk_total_space("/");
    $used = $total - disk_free_space("/");
    $disks[] = ['mount' => '/', 'total' => round($total / (1024**3), 2), 'used' => round($used / (1024**3), 2), 'percent' => ($total > 0) ? round(($used / $total) * 100, 1) : 0];
}

$load = sys_getloadavg();
$cores = intval(shell_exec('nproc'));
if ($cores < 1) $cores = 1;
$loadPercent = round(($load[0] / $cores) * 100, 1);

function getBarColor($percent) {
    if ($percent > 85) return '#e74c3c';
    if ($percent > 70) return '#f1c40f';
    return '#2ecc71';
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function renderTableRows($processData) {
    if ($processData) {
        $lines = explode("\n", trim($processData));
        array_shift($lines); // Remove header
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 3);
            if (count($parts) >= 3) {
                echo "<tr><td style='padding: 5px;'>{$parts[0]}</td><td style='padding: 5px; text-align: center; color: #64b5f6;'>{$parts[1]}</td><td style='padding: 5px; text-align: right;'>{$parts[2]}</td></tr>";
            }
        }
    }
}
$topProcesses = shell_exec("ps -eo user,%mem,comm --sort=-%mem | head -n 6");
$topCpuProcesses = shell_exec("ps -eo user,%cpu,comm --sort=-%cpu | head -n 6");
$topDiskUsage = null;
if (isset($_GET['disk_scan'])) {
    $scanPath = $_GET['disk_scan'];
    foreach ($disks as $d) {
        if ($d['mount'] === $scanPath) {
            $topDiskUsage = shell_exec("du -Shx " . escapeshellarg($scanPath) . " 2>/dev/null | sort -rh | head -n 10");
            break;
        }
    }
}
$timestamp = date('Y-m-d H:i:s');

$netInterfaces = [];
if (file_exists('/proc/net/dev')) {
    $lines = file('/proc/net/dev');
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            $line = str_replace(':', ' ', $line);
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 10) {
                $netInterfaces[] = ['name' => $parts[0], 'rx' => formatBytes($parts[1]), 'tx' => formatBytes($parts[9])];
            }
        }
    }
}

$temps = [];
$thermalZones = glob('/sys/class/thermal/thermal_zone*');
if ($thermalZones) {
    foreach ($thermalZones as $zone) {
        $temp = @file_get_contents($zone . '/temp');
        $type = @file_get_contents($zone . '/type');
        if ($temp !== false && $type !== false) {
            $temps[] = ['name' => trim($type), 'temp' => round(intval($temp) / 1000, 1) . ' °C'];
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>System Report for <?= htmlspecialchars($hostname) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #121212; color: #e0e0e0; padding: 20px; }
        .wrapper { display: flex; flex-wrap: wrap; gap: 20px; justify-content: center; max-width: 1100px; margin: auto; align-items: flex-start; }
        .container { background: #1e1e1e; flex: 1; min-width: 300px; max-width: 500px; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.5); }
        h1 { color: #ffffff; font-size: 20px; text-align: center; border-bottom: 1px solid #333; padding-bottom: 15px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #333; align-items: center; flex-wrap: wrap; }
        .label { color: #b0bec5; font-weight: 600; }
        .val { color: #64b5f6; font-weight: bold; }
        .bar-bg { background: #333; height: 8px; border-radius: 4px; margin-top: 5px; overflow: hidden; }
        .bar-fill { height: 100%; transition: width 0.5s; }
        @media (max-width: 768px) {
            .container { min-width: 100%; max-width: 100%; }
        }
        .btn-refresh { float: right; background: #2980b9; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="wrapper">
    <div class="container">
        <h1>🖥️ Server Health Report <a href="?" class="btn-refresh">↻ Refresh</a></h1>
        <div class="row"><span class="label">Last Updated:</span> <span class="val"><?= $timestamp ?></span></div>
        <div class="row"><span class="label">PHP process running by user:</span> <span class="val"><?= htmlspecialchars($phpUser) ?></span></div>
        <div class="row"><span class="label">Hostname:</span> <span class="val"><?= htmlspecialchars($hostname) ?></span></div>
        <div class="row"><span class="label">Remote IP:</span> <span class="val"><?= htmlspecialchars($remoteIP) ?></span></div>
        <div class="row"><span class="label">OS:</span> <span class="val"><?= htmlspecialchars($os) ?></span></div>
        <div class="row"><span class="label">Uptime:</span> <span class="val"><?= htmlspecialchars($uptime) ?></span></div>
        <div class="row">
            <span class="label">CPU Load (<?= $loadPercent ?>%)</span>
            <span class="val"><?= number_format($load[0], 2) . " " . number_format($load[1], 2) . " " . number_format($load[2], 2) ?></span>
        </div>
        <div class="bar-bg">
            <div class="bar-fill" style="width: <?= min($loadPercent, 100) ?>%; background: <?= getBarColor($loadPercent) ?>;"></div>
        </div>
        <div class="row">
            <span class="label">RAM Available (<?= $memoryPercent ?>%)</span>
            <span class="val"><?= $memoryAvailable ?> / <?= $memoryTotal ?></span>
        </div>
        <div class="bar-bg">
            <div class="bar-fill" style="width: <?= $memoryPercent ?>%; background: <?= $memoryPercent < 15 ? '#e74c3c' : ($memoryPercent < 30 ? '#f1c40f' : '#2ecc71') ?>;"></div>
        </div>
        <div class="row">
            <span class="label">Swap Used (<?= $swapPercent ?>%)</span>
            <span class="val"><?= $swapUsed ?> / <?= $swapTotal ?></span>
        </div>
        <div class="bar-bg">
            <div class="bar-fill" style="width: <?= $swapPercent ?>%; background: <?= getBarColor($swapPercent) ?>;"></div>
        </div>
        <?php $diskCount = count($disks); foreach ($disks as $i => $disk): ?>
        <div class="row" title="<?= $disk['mount'] ?>">
            <span class="label">Disk <?= ($diskCount > 1) ? ($i + 1) . ' ' : '' ?>(<?= $disk['percent'] ?>%)</span>
            <span class="val">
                <?= $disk['used'] ?> GB / <?= $disk['total'] ?> GB
                <?php $isScanning = (isset($_GET['disk_scan']) && $_GET['disk_scan'] === $disk['mount']); ?>
                <button onclick="<?= $isScanning ? "window.location.href=window.location.pathname" : "window.location.href='?disk_scan=" . urlencode($disk['mount']) . "'" ?>" style="margin-left: 10px; background: #333; color: #e0e0e0; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;"><?= $isScanning ? "No details" : "Details" ?></button>
            </span>
        </div>
        <div class="bar-bg" title="<?= $disk['mount'] ?>">
            <div class="bar-fill" style="width: <?= $disk['percent'] ?>%; background: <?= getBarColor($disk['percent']) ?>;"></div>
        </div>
        <?php endforeach; ?>
        <div id="disk-details" style="display: <?= isset($_GET['disk_scan']) ? 'block' : 'none' ?>;">
        <h2 style="font-size: 16px; margin-top: 20px; border-bottom: 1px solid #333; padding-bottom: 10px; color: #fff;">Top Disk Usage (Deep) <?= isset($_GET['disk_scan']) ? htmlspecialchars($_GET['disk_scan']) : '' ?></h2>
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr>
                <th style="text-align: left; color: #b0bec5; padding: 5px;">Path</th>
                <th style="text-align: right; color: #b0bec5; padding: 5px;">Size</th>
            </tr>
            <?php
            if ($topDiskUsage) {
                $lines = explode("\n", trim($topDiskUsage));
                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', trim($line), 2);
                    if (count($parts) == 2) {
                        echo "<tr><td style='padding: 5px;'>{$parts[1]}</td><td style='padding: 5px; text-align: right; color: #64b5f6;'>{$parts[0]}</td></tr>";
                    }
                }
            }
            ?>
        </table>
        </div>
    </div>

    <div class="container">
        <h2 style="font-size: 16px; margin-top: 0; border-bottom: 1px solid #333; padding-bottom: 10px; color: #fff;">Network Interfaces</h2>
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr>
                <th style="text-align: left; color: #b0bec5; padding: 5px;">Interface</th>
                <th style="text-align: right; color: #b0bec5; padding: 5px;">RX</th>
                <th style="text-align: right; color: #b0bec5; padding: 5px;">TX</th>
            </tr>
            <?php foreach ($netInterfaces as $iface): ?>
            <tr>
                <td style="padding: 5px;"><?= $iface['name'] ?></td>
                <td style="padding: 5px; text-align: right; color: #64b5f6;"><?= $iface['rx'] ?></td>
                <td style="padding: 5px; text-align: right; color: #64b5f6;"><?= $iface['tx'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php if (!empty($temps)): ?>
        <h2 style="font-size: 16px; margin-top: 20px; border-bottom: 1px solid #333; padding-bottom: 10px; color: #fff;">System Temperatures</h2>
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <?php foreach ($temps as $t): ?>
            <tr>
                <td style="padding: 5px; color: #b0bec5;"><?= htmlspecialchars($t['name']) ?></td>
                <td style="padding: 5px; text-align: right; color: #64b5f6;"><?= $t['temp'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <h2 style="font-size: 16px; margin-top: 20px; border-bottom: 1px solid #333; padding-bottom: 10px; color: #fff;">Top Memory Processes</h2>
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr>
                <th style="text-align: left; color: #b0bec5; padding: 5px;">User</th>
                <th style="text-align: center; color: #b0bec5; padding: 5px;">%</th>
                <th style="text-align: right; color: #b0bec5; padding: 5px;">Command</th>
            </tr>
            <?php
            renderTableRows($topProcesses);
            ?>
        </table>

        <h2 style="font-size: 16px; margin-top: 20px; border-bottom: 1px solid #333; padding-bottom: 10px; color: #fff;">Top CPU Processes</h2>
        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
            <tr>
                <th style="text-align: left; color: #b0bec5; padding: 5px;">User</th>
                <th style="text-align: center; color: #b0bec5; padding: 5px;">%</th>
                <th style="text-align: right; color: #b0bec5; padding: 5px;">Command</th>
            </tr>
            <?php
            renderTableRows($topCpuProcesses);
            ?>
        </table>
    </div>
    </div>
</body>
</html>