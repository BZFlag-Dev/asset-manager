BZFlag Asset Manager
====================

Asset Manager is a web interface for the submission of game assets and a simple moderation system. This is a replacement for the old BZFlag Image Uploader a.k.a. submitimages.

Feature Progress
----------------
* [X] BZFlag WebLogin integration
* [X] Queue database
* [X] Image thumbnail generator
* [X] Ability to upload new assets
* [X] Moderation interface for the asset queue
* [ ] Email notifications
  * [ ] Moderation queue reminders
* [ ] Directory index for listing assets

Requirements
------------
* PHP 8.2 or later (ideally the FPM variant), with the following extensions:
  * PDO
  * cURL
  * FileInfo
  * GD
  * PDO
  * SQLite3
* [Composer](https://getcomposer.org/download/)

Installation
------------
This assumes that the website will be stored in ```/var/www/asset-manager```, where ```/var/www/asset-manager/README.md``` would be this file.
```shell
git clone https://github.com/BZFlag-Dev/asset-manager /var/www/asset-manager
cd /var/www/asset-manager
composer install
```
The final location of approved assets would be elsewhere, such as ```/var/www/assets/public```.

A configuration file named ```config.php``` will need to be created and would be placed at ```/var/www/asset-manager/config.php```:
```php
<?php
return [
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
