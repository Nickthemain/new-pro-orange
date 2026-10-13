#!/usr/bin/env bash
# =====================================================================
# install.sh — one-time server setup for SwipeHire
# For Ubuntu/Debian with Apache + MySQL or MariaDB.
#
#   cd /var/www/your_domain/php_auth
#   sudo bash install.sh          # live server: errors hidden
#   sudo bash install.sh --dev    # your own machine: error details shown
#   sudo bash install.sh --maps-key=AIza...   # optional Google Maps Embed API key
#
# What it does (safe to run again any time):
#   1. checks PHP has the MySQL driver
#   2. creates the database + tables (never deletes existing data)
#   3. creates a MySQL user "jobapp" with only the rights the app needs
#   4. writes config.local.php with that password
#   5. gives Apache write access to ONLY the two upload folders
# =====================================================================
set -euo pipefail

DEV=false
MAPS_KEY=""
for arg in "$@"; do
  case "$arg" in
    --dev)        DEV=true ;;
    --maps-key=*) MAPS_KEY="${arg#--maps-key=}" ;;
  esac
done

ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; exit 1; }

[[ $(id -u) -eq 0 ]] || { echo "Run it with sudo:  sudo bash install.sh"; exit 1; }

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PRIVATE_DIR="$(dirname "$(dirname "$APP_DIR")")/swipehire_private"   # outside the web folder
UPLOAD_DIR="$APP_DIR/uploads"
WEB_USER="${WEB_USER:-www-data}"
OWNER="${SUDO_USER:-root}"          # you keep ownership of the code
DB_NAME="job_matching_app"
DB_USER="jobapp"
LOCAL_CFG="$APP_DIR/config.local.php"

echo "SwipeHire setup"
echo "  app folder:     $APP_DIR"
echo "  private folder: $PRIVATE_DIR"
echo

# ---- 1. checks -------------------------------------------------------
id "$WEB_USER" >/dev/null 2>&1 || fail "Web server user '$WEB_USER' not found. Is Apache installed?"
command -v mysql >/dev/null || fail "mysql command not found. Install MySQL or MariaDB first."
command -v php   >/dev/null || fail "php command not found. Install PHP first."
php -m | grep -qi '^pdo_mysql$' || fail "PHP MySQL driver missing. Run: sudo apt install php-mysql && sudo systemctl restart apache2"
[[ -f "$APP_DIR/job_matching_app.sql" ]] || fail "job_matching_app.sql not found next to install.sh"
mysql -e "SELECT 1" >/dev/null 2>&1 || fail "Cannot reach MySQL as root. Is it running? sudo systemctl start mysql"
ok "PHP, MySQL and Apache user found"

# ---- 2. database + tables (CREATE ... IF NOT EXISTS, no deletes) ------
mysql < "$APP_DIR/job_matching_app.sql"
ok "Database '$DB_NAME' and tables ready"

# ---- 3. app database user -------------------------------------------
# Reuse the password from an earlier run so the site keeps working.
DB_PASS=""
if [[ -f "$LOCAL_CFG" ]]; then
  DB_PASS="$(php -r "error_reporting(0); include '$LOCAL_CFG'; echo defined('DB_PASS') ? DB_PASS : '';")"
fi
# Keep an existing Google Maps key unless a new one was given
if [[ -z "$MAPS_KEY" && -f "$LOCAL_CFG" ]]; then
  MAPS_KEY="$(php -r "error_reporting(0); include '$LOCAL_CFG'; echo defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';")"
fi
[[ "$MAPS_KEY" =~ ^[A-Za-z0-9_-]*$ ]] || fail "That Google Maps key has invalid characters"
if [[ -z "$DB_PASS" ]]; then
  DB_PASS="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
fi

mysql <<SQL
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$DB_USER'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "MySQL user '$DB_USER' ready (can read/write data, cannot drop tables)"

# ---- 4. config.local.php --------------------------------------------
cat > "$LOCAL_CFG" <<PHP
<?php
// Written by install.sh — keeps secrets out of config.php. Do not share this file.
define('DB_HOST', 'localhost');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
define('APP_DEBUG', $DEV);
define('GOOGLE_MAPS_API_KEY', '$MAPS_KEY');
PHP
chown "$OWNER:$WEB_USER" "$LOCAL_CFG"
chmod 640 "$LOCAL_CFG"
ok "config.local.php written (debug: $DEV, Google Maps key: $([[ -n "$MAPS_KEY" ]] && echo set || echo 'none, keyless map'))"

# ---- 5. folders -------------------------------------------------------
mkdir -p "$UPLOAD_DIR/images" "$PRIVATE_DIR/cv"

# code: you own it, Apache can only read it
chown -R "$OWNER:$WEB_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
chmod 640 "$LOCAL_CFG"

# upload folders: Apache can write here and nowhere else
chown -R "$WEB_USER:$WEB_USER" "$UPLOAD_DIR" "$PRIVATE_DIR"
chmod 755 "$UPLOAD_DIR" "$UPLOAD_DIR/images"
chmod 750 "$PRIVATE_DIR" "$PRIVATE_DIR/cv"
ok "Upload folders writable by $WEB_USER (code stays read-only)"

# ---- 6. Apache rules ---------------------------------------------------
# Ubuntu's Apache ignores .htaccess by default (AllowOverride None), so the
# same rules are added to Apache's own config, where they always apply.
if command -v a2enconf >/dev/null && [[ -d /etc/apache2/conf-available ]]; then
  CONF=/etc/apache2/conf-available/swipehire.conf
  cat > "$CONF" <<APACHE
# Added by SwipeHire install.sh
<Directory "$APP_DIR">
    Options -Indexes
    # setup files and secrets are never downloadable
    <FilesMatch "\.(sh|sql|md|log)$|^config\.local\.php$">
        Require all denied
    </FilesMatch>
</Directory>
<Directory "$UPLOAD_DIR">
    Options -Indexes
    # uploaded files can never run as code
    <FilesMatch "\.(php|phtml|phar|cgi|pl|py)$">
        Require all denied
    </FilesMatch>
</Directory>
APACHE
  a2enconf -q swipehire
  if apache2ctl configtest >/dev/null 2>&1; then
    (systemctl reload apache2 2>/dev/null || apache2ctl graceful) && ok "Apache rules added: no folder listings, setup files blocked"
  else
    a2disconf -q swipehire; warn "Apache config test failed, rules not applied"
  fi
else
  warn "Apache not detected — protect install.sh, *.sql and uploads/ in your web server config yourself"
fi

# ---- 7. final test as the web user -----------------------------------
for d in "$UPLOAD_DIR/images" "$PRIVATE_DIR/cv"; do
  if sudo -u "$WEB_USER" test -w "$d"; then ok "write test passed: $d"; else fail "still not writable: $d"; fi
done
if sudo -u "$WEB_USER" php -r "
  include '$LOCAL_CFG';
  new PDO('mysql:host=localhost;dbname=$DB_NAME;charset=utf8mb4', DB_USER, DB_PASS);
" 2>/dev/null; then
  ok "PHP can log in to MySQL as '$DB_USER'"
else
  fail "PHP could not connect to MySQL with the new user"
fi

# PHP's upload limit must be at least the app's 2 MB limit
php -r '
  $b = fn($v) => (int)$v * (["K"=>1024,"M"=>1048576,"G"=>1073741824][strtoupper(substr(trim($v), -1))] ?? 1);
  exit($b(ini_get("upload_max_filesize")) >= 2097152 && $b(ini_get("post_max_size")) > 2097152 ? 0 : 1);
' && ok "PHP upload limits allow 2 MB files" \
  || warn "PHP upload_max_filesize/post_max_size is below 2 MB — raise it in php.ini"

echo
echo "Done. Open the site and sign up."
$DEV && echo "Debug is ON. Before going live run:  sudo bash install.sh   (without --dev)"
echo "Health check page (debug only): /php_auth/check.php"
echo "If you copy new files over this folder later, run this script again."
