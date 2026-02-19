# Shuffle — Installation & Setup Guide

## System Requirements

| Component | Minimum |
|-----------|---------|
| PHP | 8.4+ |
| MariaDB / MySQL | 10.6+ / 8.0+ |
| Web server | Apache 2.4+ or Nginx 1.24+ |
| S3-compatible storage | MinIO or AWS S3 |
| SMTP relay | Any compliant server |

PHP extensions required: `pdo_mysql`, `mbstring`, `json`, `openssl`, `filter`

---

## 1. Get the Code

```bash
git clone https://github.com/your-org/shuffle.git /var/www/shuffle
cd /var/www/shuffle
chmod +x bin/setup.php bin/trello-import.php
```

---

## 2. Database Setup

Create the database and a dedicated user:

```sql
CREATE DATABASE shuffle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'shuffle'@'127.0.0.1' IDENTIFIED BY 'strong-password-here';
GRANT ALL PRIVILEGES ON shuffle.* TO 'shuffle'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Import the schema:

```bash
mysql -u shuffle -p shuffle < /var/www/shuffle/doc/schema.sql
```

---

## 3. Configuration

Copy the example configuration and fill in your values:

```bash
cp /var/www/shuffle/etc/config.example.php /var/www/shuffle/etc/config.php
```

Edit `/var/www/shuffle/etc/config.php`:

```php
return [
    'app' => [
        'name'     => 'Shuffle',               // Application name shown in UI
        'url'      => 'https://boards.example.com',
        'locale'   => 'en',
        'timezone' => 'UTC',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'shuffle',
        'user'     => 'shuffle',
        'password' => 'strong-password-here',
        'charset'  => 'utf8mb4',
    ],
    's3' => [
        'endpoint'   => 'http://127.0.0.1:9000',  // MinIO or AWS endpoint
        'bucket'     => 'shuffle',
        'access_key' => 'your-access-key',
        'secret_key' => 'your-secret-key',
        'region'     => 'us-east-1',
        'path_style' => true,   // true for MinIO; false for AWS virtual-hosted style
    ],
    'smtp' => [
        'host'       => 'smtp.example.com',
        'port'       => 587,
        'encryption' => 'tls',           // 'tls', 'ssl', or '' for none
        'username'   => 'noreply@example.com',
        'password'   => 'smtp-password',
        'from_email' => 'noreply@boards.example.com',
        'from_name'  => 'Shuffle',
    ],
    'session' => [
        'lifetime'    => 86400,     // Session lifetime in seconds (24h)
        'cookie_name' => 'shuffle_session',
    ],
    'upload' => [
        'chunk_size' => 5242880,    // 5 MB — S3 multipart threshold
    ],
];
```

---

## 4. S3 / MinIO Setup

### Using MinIO (self-hosted)

```bash
# Download and start MinIO
curl -Lo /usr/local/bin/minio https://dl.min.io/server/minio/release/linux-amd64/minio
chmod +x /usr/local/bin/minio

MINIO_ROOT_USER=admin MINIO_ROOT_PASSWORD=adminpassword \
  minio server /data/minio --console-address ":9001"
```

Create the bucket:

```bash
# Using the MinIO client (mc)
mc alias set local http://127.0.0.1:9000 admin adminpassword
mc mb local/shuffle
mc anonymous set none local/shuffle   # Keep the bucket private
```

Create an access key pair via the MinIO console (`http://127.0.0.1:9001`) or with `mc`:

```bash
mc admin user add local shuffle-user strong-key
mc admin policy attach local readwrite --user shuffle-user
mc admin user svcacct add local shuffle-user
```

Use the generated access key and secret key in `etc/config.php`.

### Using AWS S3

1. Create an S3 bucket in the AWS console.
2. Create an IAM user with `AmazonS3FullAccess` (or a scoped policy) on that bucket.
3. Generate an access key for the IAM user.
4. Set `'path_style' => false` and `'endpoint' => 'https://s3.amazonaws.com'` in config.

---

## 5. Web Server Configuration

### Apache

Enable the required modules:

```bash
a2enmod rewrite headers
```

Create a virtual host (e.g. `/etc/apache2/sites-available/shuffle.conf`):

```apache
<VirtualHost *:443>
    ServerName boards.example.com
    DocumentRoot /var/www/shuffle/www

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/boards.example.com.crt
    SSLCertificateKeyFile /etc/ssl/private/boards.example.com.key

    <Directory /var/www/shuffle/www>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>

    # API rewrite — forward /v1/* to the API front controller
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/v1/
    RewriteRule ^/v1/(.*)$ /v1/index.php [L,QSA]

    # Block access to include/ and etc/
    <DirectoryMatch "^/var/www/shuffle/(include|etc|bin|doc)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

Enable and reload:

```bash
a2ensite shuffle
systemctl reload apache2
```

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name boards.example.com;
    root /var/www/shuffle/www;
    index index.php;

    ssl_certificate     /etc/ssl/certs/boards.example.com.crt;
    ssl_certificate_key /etc/ssl/private/boards.example.com.key;

    # PHP handler
    location ~ \.php$ {
        fastcgi_pass   unix:/run/php/php8.4-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include        fastcgi_params;
    }

    # API routing
    location /v1/ {
        try_files $uri /v1/index.php?$query_string;
    }

    # Block sensitive directories
    location ~* ^/(include|etc|bin|doc)/ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ =404;
    }
}
```

---

## 6. File Permissions

```bash
chown -R www-data:www-data /var/www/shuffle
chmod 640 /var/www/shuffle/etc/config.php
chmod -R 750 /var/www/shuffle/bin
```

---

## 7. First Admin Account

Run the interactive setup script to create the initial administrator account and default organization:

```bash
php /var/www/shuffle/bin/setup.php
```

You will be prompted for:
- **Username** (3–64 chars, letters/numbers/dots/hyphens/underscores)
- **Full name**
- **Email address**
- **Password** (minimum 8 characters)

The script creates the default organization (named after `config['app']['name']`) and the admin user in a single transaction.

---

## 8. Inviting Users

Shuffle uses invite-only registration. To add users:

1. Log in as an admin at `https://boards.example.com/login.php`
2. Go to **Admin → Users** and click **Invite User**
3. Enter the user's email address and role (Member or Viewer)
4. The user receives an email with an activation link (valid for 72 hours)

---

## 9. Importing from Trello

Export your Trello board: **Board → Show Menu → More → Print and Export → Export as JSON**.

Run the import tool:

```bash
php /var/www/shuffle/bin/trello-import.php \
    --org=1 \
    --user=1 \
    /path/to/trello-export.json
```

**Options:**

| Flag | Description |
|------|-------------|
| `--org=<id>` | Target organization ID (required) |
| `--user=<id>` | Importer user ID (defaults to first admin) |
| `--dry-run` | Validate JSON without writing to the database |

The import is idempotent: re-running with the same file after a successful import will fail with an error (detected via `trello_id`). Attachments are downloaded from Trello's CDN and uploaded to your S3 bucket. Trello members without a matching Shuffle account are created as placeholder users.

---

## 10. Updating

```bash
cd /var/www/shuffle
git pull
# Review schema changes in git log and apply new migrations manually if needed
```

There is no automated migration tool; schema changes between versions are listed in the release notes.
