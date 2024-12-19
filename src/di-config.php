<?php

use App\Database\DatabaseInterface;
use League\Config\Configuration;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nette\Schema\Expect;
use PHPMailer\PHPMailer\PHPMailer;
use Slim\Views\Twig;

return [
  Configuration::class => function () {
    // Define config schema
    $config = new Configuration([
      'database' => Expect::structure([
        'path' => Expect::string(dirname(__DIR__).'/var/data/db.sqlite3')
      ]),
      'site' => Expect::structure([
        // The name of the game that these assets are being hosted for
        'game_name' => Expect::string('BZFlag'),
        // Site title (used for the page header and the page title)
        'title' => Expect::string('Asset Manager'),
        // Base URL of the site, used for generating links to the final asset locations
        'base_url' => Expect::string()->required(),
        // Base path
        'base_path' => Expect::string('/manage'),
        // Content takedown requests
        'takedown_address' => Expect::email()->required()
      ]),
      // Paths should NOT end with a trailing slash
      'path' => Expect::structure([
        // Uploaded files are stored here before approval
        'upload' => Expect::string(dirname(__DIR__).'/var/upload'),
        // Approved files are moved here
        'files' => Expect::string()->required()
      ]),
      'asset' => Expect::structure([
        'image' => Expect::structure([
          'max_width' => Expect::int(4096),
          'max_height' => Expect::int(4096)
        ]),
        'upload' => Expect::structure([
          'max_file_size' => Expect::int(2 * 1024 * 1024)->min(512 * 1024),
          'max_file_count' => Expect::int(8)->min(1)->max(20),
          'licenses' => Expect::structure([
            // A short list of popular licenses
            'popular' => Expect::listOf(Expect::string())
              ->default(['CC-BY-4.0', 'CC-BY-SA-4.0', 'CC-BY-3.0', 'CC-BY-SA-3.0', 'CC0-1.0', 'LGPL-2.1-only', 'MPL-2.0', 'MIT'])
              ->mergeDefaults(false),
            // A list of less popular asset licenses, but still popular in open-source
            'common' => Expect::listOf(Expect::string())
              ->default(['GPL-2.0-only', 'GPL-2.0-or-later', 'GPL-3.0-only', 'GPL-3.0-or-later', 'LGPL-2.0-only', 'LGPL-2.0-or-later',
                'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'MPL-1.0', 'MPL-1.1', 'BSD-3-Clause',
                'BSD-2-Clause', 'AGPL-3.0-only', 'AGPL-3.0-or-later'])
              ->mergeDefaults(false),
            // Allow specifying an unlisted license by providing the name, and the URL or text
            'allow_other' => Expect::bool(true),
            // Allow showing all other OSI-approved licenses that weren't in the 'popular' or 'common' sections above
            'allow_other_osi' => Expect::bool(false)
          ]),
          'types' => Expect::arrayOf(
            Expect::anyOf(Expect::string(), Expect::listOf(Expect::string())),
            Expect::string()
          )->required()
        ])
      ]),
      'email' => Expect::structure([
        // Emails are sent from this address
        'from_address' => Expect::email()->required(),
        // When a new asset is uploaded for moderation, all emails here will
        // be notified.
        'notify_addresses' => Expect::listOf(Expect::email())->required(),

        'smtp' => Expect::structure([
          'host' => Expect::string('localhost'),
          'port' => Expect::int(25)->min(1)->max(65535),
          'username' => Expect::string(''),
          'password' => Expect::string(''),
          'encryption' => Expect::anyOf('null', 'ssl', 'tls')->default('null')
        ])
      ]),
      'auth' => Expect::structure([
        // The URL to the BZFlag list server
        'list_url' => Expect::string('https://my.bzflag.org/db/'),
        // The URL to the weblogin page, which the return URL will be appended to
        'weblogin_url' => Expect::string('https://my.bzflag.org/weblogin.php?url='),
        // An uppercase group name in the format of ORG.GROUP
        'admin_group' => Expect::string()->required(),
        // This should only be set to false for local test/dev environments
        'check_ip' => Expect::bool(true)
      ]),
      // Display debug messages in the browser? Disable for production site.
      'debug' => Expect::bool(false),
      'logging' => Expect::structure([
        // Absolute path to the configuration file
        'path' => Expect::string(dirname(__DIR__).'/var/log/app.log'),
        // Log level (see https://seldaek.github.io/monolog/doc/01-usage.html)
        'level' => Expect::int(250)->min(100)->max(600)
      ])
    ]);

    // Merge our configuration file information
    $config->merge(require dirname(__DIR__) . '/config.php');

    return $config;
  },

  DatabaseInterface::class => function (Configuration $config, Logger $logger) {
    return new \App\Database\SQLite3($config, $logger);
  },

  Twig::class => function (Configuration $config) {
    $twig = Twig::create(dirname(__DIR__).'/views', [
      'cache' => dirname(__DIR__).'/var/cache/twig',
      'auto_reload' => true
    ]);

    if (!empty($_SESSION['username'])) {
      $twig->offsetSet('username', $_SESSION['username']);
      $twig->offsetSet('bzid', $_SESSION['bzid']);
      $twig->offsetSet('is_admin', $_SESSION['is_admin']);
    }

    $twig->offsetSet('game_name', $config->get('site.game_name'));
    $twig->offsetSet('site_title', $config->get('site.title'));
    $twig->offsetSet('weblogin_url', $config->get('auth.weblogin_url'));

    return $twig;
  },

  Logger::class => function (Configuration $config): Logger {
    $c = $config->get('logging');
    $logger = new Logger('app');
    $logger->pushHandler(new StreamHandler($c['path'], $c['level']));
    return $logger;
  },

  PHPMailer::class => function (Configuration $config) {
    $smtp = $config->get('email.smtp');

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->SMTPKeepAlive = true;
    $mailer->SMTPSecure = match($smtp['encryption']) {
      'ssl' => PHPMailer::ENCRYPTION_SMTPS,
      'tls' => PHPMailer::ENCRYPTION_STARTTLS,
      default => ''
    };
    $mailer->Host = $smtp['host'];
    $mailer->Port = $smtp['port'];
    $mailer->XMailer = null;
    $mailer->setFrom($config->get('email.from_address'), "{$config->get('site.game_name')} {$config->get('site.title')}");
    $mailer->WordWrap = 80;

    // If we have a username/password, use authentication
    if (!empty($smtp['username']) && !empty($smtp['password'])) {
      $mailer->SMTPAuth = true;
      $mailer->Username = $smtp['username'];
      $mailer->Password = $smtp['password'];
    }

    return $mailer;
  }
];
