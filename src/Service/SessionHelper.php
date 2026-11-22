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

namespace App\Service;

use League\Config\Configuration;
use Monolog\Logger;

class SessionHelper
{
  private const string SESSION_NAME = 'assetmanager_session';

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
    session_start([
      'use_strict_mode' => true,
      'use_trans_sid' => 0,
      'gc_maxlifetime' => $lifetime,
    ]);

    // If the user agent isn't stored yet, do so now
    if (!$this->exists('user_agent')) {
      $this->set('user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
    // If it exists, verify it matches, and if not, clear the session and regenerate the session ID
    elseif ($this->get('user_agent') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
      $this->clear();
      $this->regenerate_id();
    }
  }

  private function exists($key): bool
  {
    return array_key_exists($key, $_SESSION);
  }

  private function get($key, $default = null): mixed
  {
    return $this->exists($key) ? $_SESSION[$key] : $default;
  }

  private function set($key, $value = null): void
  {
    if ($value === null) {
      $this->unset($key);
    } else {
      $_SESSION[$key] = $value;
    }
  }

  private function unset($key): void
  {
    unset($_SESSION[$key]);
  }

  public function clear(): void
  {
    $_SESSION = [];
  }

  public function regenerate_id(): void
  {
    session_regenerate_id(true);
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

  public function setUserInformation(string $bzid, string $username, string $clean_username): void
  {
    $this->set('bzid', $bzid);
    $this->set('username', $username);
    $this->set('clean_username', $clean_username);
  }

  public function setAdmin($isAdmin = false): void
  {
    $this->set('is_admin', $isAdmin);
  }

  // A valid login session needs three values: bzid, username, and clean_username
  public function isLoggedIn(): bool
  {
    return
      !empty($this->get('bzid', '')) &&
      !empty($this->get('username', '')) &&
      !empty($this->get('clean_username', ''))
    ;
  }

  public function isAdmin(): bool
  {
    return $this->isLoggedIn() && $this->get('is_admin', false);
  }

  public function getBZID(): string
  {
    return $this->get('bzid', '');
  }

  public function getUsername(): string
  {
    return $this->get('username', '');
  }

  public function getCleanUsername(): string
  {
    return $this->get('clean_username', '');
  }
}
