BZFlag Asset Manager
====================

Asset Manager is a web interface for the submission of game assets and a simple moderation system. This is a replacement for the old BZFlag Image Uploader a.k.a. submitimages.

Feature Progress
----------------
* [X] BZFlag WebLogin integration
* [X] Queue database
* [X] Image thumbnail generator
* [X] Ability to upload new assets
  * [X] Editing asset information after a change is requested
* [X] Moderation interface for the asset queue
* [X] Email notifications
  * [ ] Moderation queue reminders
* [X] Directory index for listing assets
* [X] Write out static index.html files for directory indexes
* [ ] Conversion script to migrate from the old site

Requirements
------------
* PHP 8.4 or later (ideally the FPM variant), with the following extensions:
  * PDO
  * cURL
  * FileInfo
  * GD
  * PDO
  * MySQL
* MariaDB
* [Composer](https://getcomposer.org/download/)

Installation
------------
This assumes that the website will be stored in ```/var/www/asset-manager```, where ```/var/www/asset-manager/README.md``` would be this file.
```shell
git clone https://github.com/BZFlag-Dev/asset-manager /var/www/asset-manager
cd /var/www/asset-manager
composer install --no-dev -o
composer -d tools install --no-dev
mkdir -p /var/www/assets/public
```
The final location of approved assets would be elsewhere, such as ```/var/www/assets/public```.

A configuration file named ```config.php``` will need to be created and would be placed at ```/var/www/asset-manager/config.php```:
```php
<?php
return [
  'database' => [
    'database' => 'assets',
    'username' => 'assets',
    'password' => 'PutPasswordHere'
  ],
  'site' => [
    'takedown_address' => 'dmca@domain.test'
  ],
  'path' => [
    'files' => '/var/www/assets/public'
  ],
  'auth' => [
    'admin_group' => 'SOME.GROUP',
  ],
  'asset' => [
    'upload' => [
      'types' => [
        'image/png' => 'png',
        'image/jpeg' => ['jpg', 'jpeg']
      ]
    ]
  ],
  'email' => [
    'from_address' => 'noreply@domain.test',
    'notify_addresses' => [
      'admin1@domain.test',
      'admin2@domain.test'
    ]
  ]
];
```

Database Setup
--------------

This assumes the asset-manager database is 'assets', the asset-manager user is 'assets', and another migration account
is called 'assets_migration'. Adjust the below to match your environment.

```mysql
GRANT SELECT, INSERT, UPDATE, DELETE ON assets.* TO assets@localhost;
GRANT SELECT, INSERT, UPDATE, DELETE, ALTER, CREATE, DROP ON assets.* TO assets_migration@localhost;
```

Create a `phinx-config.php`:
```php
<?php
return [
  'database' => [
    'database' => 'assets',
    'username' => 'assets_migration',
    'password' => 'PutPasswordHere'
  ]
];
```

Run the migration:
```bash
php tools/vendor/bin/phinx migrate
```

Webserver Configuration
-----------------------

Create a web server configuration, such as something like this for Apache:
```apacheconf
<VirtualHost *:80>
        ServerName assets.example.com

        DocumentRoot /var/www/assets/public
        <Directory /var/www/assets/public>
                Require all granted
                DirectoryIndex index.html
        </Directory>


        Alias /manage /var/www/asset-manager/public
        <Directory /var/www/asset-manager/public>
                Require all granted

                RewriteEngine On
                RewriteBase /manage
                RewriteCond %{REQUEST_FILENAME} !-f
                RewriteCond %{REQUEST_FILENAME} !-d
                RewriteRule ^ index.php [QSA,L]
        </Directory>
</VirtualHost>
```
