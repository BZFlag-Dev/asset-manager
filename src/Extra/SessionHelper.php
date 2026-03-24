<?php

declare(strict_types=1);

/*
 * BZFlag Asset Manager: Tool to upload and moderate map assets for BZFlag.
 * Copyright (C) 2023  BZFlag & Associates
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Extra;

use League\Config\Configuration;
use Monolog\Logger;

class SessionHelper
{
  private const SESSION_NAME = 'assetmanager';
  public function __construct(protected Configuration $config, protected Logger $logger)
  {
  }

  public function start(): void
  {
    // If the session was already started, bail out
    if (session_status() !== PHP_SESSION_NONE) {
      $this->logger->debug('Session was already started');
      return;
    }

    // Read the session lifetime from the configuration. This supports both strings representing a human-readable
    // relative time (such as '2 hours') or a positive integer for the number of seconds. We need to convert the
    // human-readable version to integer seconds, though.
    $lifetime_config = $this->config->get('session.lifetime');
    if (is_string($lifetime_config)) {
      $lifetime = strtotime($lifetime_config, 0);
      if ($lifetime === false) {
        $this->logger->error('Failed to parse session lifetime setting');
        return;
      }
    } else {
      $lifetime = $lifetime_config;
    }

    // Grab the domain from the base URL
    $domain = parse_url($this->config->get('site.base_url'), PHP_URL_HOST);
    if ($domain === false) {
      $this->logger->error('Failed to parse the session domain name');
      return;
    }

    // Parse out the protocol to know if we should use a secure cookie
    $proto = parse_url($this->config->get('site.base_url'), PHP_URL_SCHEME);

    // Set the session name, which controls the name of the cookie
    session_name(self::SESSION_NAME);

    // Build up the cookie settings, except for the lifetime/expires value, since that key differs
    $settings = [
      'path' => $this->config->get('site.base_path'),
      'domain' => $domain,
      'secure' => ($proto === 'https'),
      'httponly' => true,
      'samesite' => 'Strict'
    ];

    // Adjust some INI settings for the session
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', $lifetime);

    // Set the session save path to a subdirectory
    $session_dir = session_save_path().DIRECTORY_SEPARATOR.self::SESSION_NAME;
    if (!is_dir($session_dir)) {
      if (mkdir($session_dir, 1733, true) === false) {
        $this->logger->error('Failed to create session directory');
        return;
      }
    }
    session_save_path(session_save_path().DIRECTORY_SEPARATOR.self::SESSION_NAME);

    // Set the session cookie parameters
    session_set_cookie_params($settings + ['lifetime' => $lifetime]);

    // Refresh the cookie if it exists
    if (isset($_COOKIE[self::SESSION_NAME])) {
      setcookie(self::SESSION_NAME, $_COOKIE[self::SESSION_NAME], $settings + ['expires' => $lifetime + time()]);
    }

    // Finally, start the session
    session_start();
  }

  public function exists($key): bool
  {
    return array_key_exists($key, $_SESSION);
  }

  public function __isset($key): bool
  {
    return $this->exists($key);
  }

  public function get($key, $default = null): mixed
  {
    return $this->exists($key) ? $_SESSION[$key] : $default;
  }

  public function __get(string $key)
  {
    return $this->get($key);
  }

  public function set($key, $value = null): void
  {
    if ($value === null) {
      $this->unset($key);
    } else {
      $_SESSION[$key] = $value;
    }
  }

  public function __set(string $key, $value): void
  {
    $this->set($key, $value);
  }

  public function unset($key): void
  {
    unset($_SESSION[$key]);
  }

  public function clear(): void
  {
    $_SESSION = [];
  }

  public function destroy(): void
  {
    $this->clear();
    session_unset();
    session_destroy();
    session_write_close();
    $params = session_get_cookie_params();
    setcookie(self::SESSION_NAME, '', [
      'expires' => time() - 3600,
      'path' => $params['path'],
      'domain' => $params['domain'],
      'secure' => $params['secure'],
      'httponly' => $params['httponly'],
      'samesite' => $params['samesite'] ?? 'Lax',
    ]);
  }
}
