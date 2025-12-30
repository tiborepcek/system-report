# PHP System Report Dashboard

A lightweight, single-file PHP script that provides a real-time overview of your Linux server's health and resource usage.

## Features

*   **System Info**: Hostname, OS, Uptime, Remote IP, and PHP user.
*   **Resource Usage**: Visual bars for CPU Load, RAM, and Swap usage.
*   **Disk Monitoring**: Usage stats for mounted partitions. Includes a "Details" button to perform a deep scan (`du`) of the top 10 largest directories in a specific mount.
*   **Network**: RX/TX statistics for network interfaces.
*   **Hardware**: System temperatures (if available via `/sys/class/thermal`).
*   **Processes**: Top 6 processes consuming CPU and Memory.
*   **UI**: Responsive, dark-themed interface with auto-refresh capability.

## Requirements

*   **OS**: Linux (Ubuntu, Debian, CentOS, etc.).
*   **Web Server**: Tested with the PHP built-in server (`php -S 0.0.0.0:8080`).
*   **PHP**: Version 7.0 or higher.
*   **Configuration**: `shell_exec` must be enabled in `php.ini`.
*   **Dependencies**: The script relies on standard Linux utilities: `uptime`, `free`, `df`, `ps`, `systemctl`, `du`, `nproc`.

## Installing PHP (Ubuntu/Debian)

If PHP is not installed on your system, you can install it via the terminal:

```bash
sudo apt update
sudo apt install php-cli
```

## Installation

1.  Download or copy the `index.php` file.
2.  Upload it to your web server's public directory.
3.  Access the file via your web browser (e.g., `http://your-server/index.php`).

## Auto-Start on Boot (Systemd)

To run the dashboard automatically in the background using the PHP built-in server:

1.  Create a service file:
    ```bash
    sudo nano /etc/systemd/system/system-report.service
    ```

2.  Paste the following configuration (adjust `User` and `WorkingDirectory`):
    ```ini
    [Unit]
    Description=PHP System Report Dashboard
    After=network.target

    [Service]
    Type=simple
    User=your_username
    WorkingDirectory=/path/to/system-report
    ExecStart=/usr/bin/php -S 0.0.0.0:8080
    Restart=always

    [Install]
    WantedBy=multi-user.target
    ```

3.  Enable and start the service:
    ```bash
    sudo systemctl daemon-reload
    sudo systemctl enable system-report
    sudo systemctl start system-report
    ```

## Security Warning

This script exposes sensitive system information and executes shell commands directly on the server.

*   **Do not expose this script to the public internet without protection.**
*   **Authentication**: It is highly recommended to secure the directory containing this script using **HTTP Basic Auth** (`.htpasswd`) or by restricting access to specific IP addresses (e.g., via VPN or Localhost).

## Troubleshooting

*   **Locating `php.ini`**:
    *   To find the loaded configuration file, run `php --ini` in the terminal.
*   **Enabling `shell_exec`**:
    *   This script requires `shell_exec`. If disabled, edit `php.ini`, remove `shell_exec` from `disable_functions`, and restart PHP.
*   **"Unknown" or Empty Data**:
    *   **Test Permissions for `$phpUser`**: Run `sudo -u $phpUser /usr/bin/uptime` in the terminal. If it fails, the web server user lacks permissions.
    *   **Add Permission**: Ensure the command is executable by others. For example:
        ```bash
        sudo chmod o+rx /usr/bin/uptime /usr/bin/free
        ```