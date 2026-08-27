# Shuffle — Installation & Setup Guide

## System Requirements

| Component | Minimum |
|-----------|---------|
| PHP | 8.4+ |
| MariaDB / MySQL | 10.6+ / 8.0+ |
| Web server | Apache 2.4+ or Nginx 1.24+ |
| S3-compatible storage | Ceph RGW (reference), MinIO, or AWS S3 |
| SMTP relay | Any compliant server |

PHP extensions required: `pdo_mysql`, `mbstring`, `json`, `openssl`, `filter`

---

## 1. Get the Code

```bash
git clone https://github.com/your-org/shuffle.git /var/www/shuffle
cd /var/www/shuffle
chmod +x bin/trello-import.php
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
        'endpoint'   => 'http://localhost:9000',   // any S3-compatible (Ceph RGW / MinIO / AWS)
        'bucket'     => 'shuffle',
        'access_key' => 'your-access-key',
        'secret_key' => 'your-secret-key',
        'region'     => 'us-west-1',
        'path_style' => true,   // true for Ceph/MinIO; false for AWS virtual-hosted style
    ],
    // SMTP is configured via the web wizard at /setup.php (see Section 7 below).
    // You do not need an smtp block here for a standard installation.
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

## 4. S3 Storage Setup

Shuffle stores attachments in any S3-compatible object store (Ceph RGW,
MinIO, Cloudflare R2, AWS S3, …). Point the config at wherever you've
provisioned a `shuffle` bucket.

### Example: local MinIO or Ceph RGW

1. Create the bucket `shuffle` (if it does not already exist) and an access
   key + secret key pair scoped to it (Ceph admin console or `radosgw-admin`).
2. Note these values:

   ```
   endpoint   : http://localhost:9000
   bucket     : shuffle
   region     : us-west-1
   path_style : true
   ```

3. Put them (and the key pair) in `etc/config.php` under the `s3` section —
   see the example below and `etc/config.example.php`.

```php
's3' => [
    'endpoint'   => 'http://localhost:9000',
    'bucket'     => 'shuffle',
    'region'     => 'us-west-1',
    'path_style' => true,
    'access_key' => 'your-access-key',
    'secret_key' => 'your-secret-key',
],
```

### Using MinIO (alternative self-hosted)

```bash
# Download and start MinIO
curl -Lo /usr/local/bin/minio https://dl.min.io/server/minio/release/linux-amd64/minio
chmod +x /usr/local/bin/minio

MINIO_ROOT_USER=admin MINIO_ROOT_PASSWORD=adminpassword \
  minio server /data/minio --console-address ":9001"
```

Create the bucket and an access key pair via the MinIO console
(`http://127.0.0.1:9001`) or `mc`, then use that endpoint/key pair in config:

```php
's3' => [
    'endpoint'   => 'http://127.0.0.1:9000',
    'bucket'     => 'shuffle',
    'region'     => 'us-east-1',
    'path_style' => true,
    ...
],
```

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
chmod -R 750 /var/www/shuffle/bin/trello-import.php
```

---

## 7. First Admin Account — Web Setup Wizard

On a fresh installation (no admin user in the database), Shuffle redirects all requests to the setup wizard. Open your browser and navigate to:

```
https://boards.example.com/setup.php
```

The wizard walks you through four steps:

| Step | What happens |
|------|--------------|
| **1 — Admin Account** | Enter your organisation name, admin username, full name, email, and password. |
| **2 — SMTP Configuration** | Enter your SMTP server details. Click **Test Connection** to verify the settings before continuing. |
| **3 — Invite First Member** | Enter a colleague's email address. Shuffle sends the invitation to confirm the full email pipeline. |
| **4 — Complete** | A summary of what was created. Click **Go to Login** to sign in. |

> **Note:** All database writes are deferred until Step 3. If the invitation email fails to send, nothing is committed and you can correct the SMTP settings and retry.

Once setup completes, `/setup.php` is automatically locked: any visit redirects to `/login.php`.

---

### 7a. Headless / Automated Deployment

For deployments where a browser session is not possible (e.g. CI/CD pipelines, Docker entrypoints), you can seed the database directly instead of using the wizard.

**Required SQL** (run after importing the schema):

```sql
-- Create the organisation
INSERT INTO organizations (name) VALUES ('Acme Corp');
SET @org_id = LAST_INSERT_ID();

-- Create the admin user (replace values as needed)
INSERT INTO users (username, password_hash, name, email, role, organization_id, status)
VALUES (
    'admin',
    -- Generate with: php -r "echo password_hash('your-password', PASSWORD_ARGON2ID);"
    '$argon2id$v=19$...',
    'Admin User',
    'admin@example.com',
    'admin',
    @org_id,
    'active'
);

-- Seed SMTP settings (replaces the web wizard SMTP step)
INSERT INTO settings (`key`, `value`) VALUES
    ('smtp.host',       'smtp.example.com'),
    ('smtp.port',       '587'),
    ('smtp.encryption', 'tls'),
    ('smtp.username',   'noreply@example.com'),
    ('smtp.password',   'smtp-password'),
    ('smtp.from_email', 'noreply@boards.example.com'),
    ('smtp.from_name',  'Shuffle');
```

Alternatively, you can add an `smtp` array to `etc/config.php` as a fallback (used only when no `smtp.*` rows exist in the `settings` table):

```php
'smtp' => [
    'host'       => 'smtp.example.com',
    'port'       => 587,
    'encryption' => 'tls',
    'username'   => 'noreply@example.com',
    'password'   => 'smtp-password',
    'from_email' => 'noreply@boards.example.com',
    'from_name'  => 'Shuffle',
],
```

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
