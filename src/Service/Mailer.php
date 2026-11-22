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

use Exception;
use League\Config\Configuration;
use Monolog\Logger;
use PHPMailer\PHPMailer\PHPMailer;

class Mailer
{
  private string $c_SMTPSecure;
  private string $c_SMTPHost;
  private int $c_SMTPPort;
  private string $c_SMTPUser;
  private string $c_SMTPPassword;
  private string $c_FromAddress;
  private string $c_FromName;
  private bool $c_simulate;

  public function __construct(Configuration $config, private readonly Logger $logger)
  {
    $this->c_SMTPSecure = $config->get('email.smtp.encryption');
    $this->c_SMTPHost = $config->get('email.smtp.host');
    $this->c_SMTPPort = $config->get('email.smtp.port');
    $this->c_SMTPUser = $config->get('email.smtp.username');
    $this->c_SMTPPassword = $config->get('email.smtp.password');
    $this->c_FromAddress = $config->get('email.from_address');
    $this->c_FromName = "{$config->get('site.game_name')} {$config->get('site.title')}";
    $this->c_simulate = $config->get('email.simulate');
  }
  public function sendEmail(string|array $recipients, string $subject, string $html_body, string $text_body): void
  {
    try {
      // Set up PHPMailer to use exceptions
      $mailer = new PHPMailer(true);

      // Set up the various SMTP options
      $mailer->isSMTP();
      $mailer->SMTPSecure = match ($this->c_SMTPSecure) {
        'ssl' => PHPMailer::ENCRYPTION_SMTPS,
        'tls' => PHPMailer::ENCRYPTION_STARTTLS,
        default => ''
      };
      $mailer->Host = $this->c_SMTPHost;
      $mailer->Port = $this->c_SMTPPort;

      // If we have a username/password, use authentication
      if (!empty($this->c_SMTPUser) && !empty($this->c_SMTPPassword)) {
        $mailer->SMTPAuth = true;
        $mailer->Username = $this->c_SMTPUser;
        $mailer->Password = $this->c_SMTPPassword;
      }

      // Remove the XMailer header
      $mailer->XMailer = null;

      // Set our From address and name
      $mailer->setFrom($this->c_FromAddress, $this->c_FromName);

      // Wrap text to 78 characters for RFC2822 section 2.1.1 compliance
      $mailer->WordWrap = 78;

      // If $recipient is an array, use BCC to send it to multiple people at once.
      if (is_array($recipients)) {
        foreach ($recipients as $recipient) {
          $mailer->addBCC($recipient);
        }
      } // Otherwise, just add the single address as a normal To address.
      else {
        $mailer->addAddress($recipients);
      }

      // Set the subject and both the HTML and text body of the email
      $mailer->Subject = $subject;
      $mailer->msgHTML($html_body);
      $mailer->AltBody = $text_body;

      // If we're simulating email, such as during development, just write the EML to disk.
      if ($this->c_simulate) {
        if ($mailer->preSend()) {
          $email_directory = dirname(__DIR__, 2) . '/var/email/';
          if (!is_dir($email_directory)) {
            mkdir($email_directory);
          }
          $filename = date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.eml';
          file_put_contents($email_directory . $filename, $mailer->getSentMIMEMessage());
        }
      } // Otherwise, actually send it!
      else {
        $mailer->send();
      }
    } catch (Exception $e) {
      $this->logger->error("Failed to send email.", ['error' => $e->getMessage()]);
    }
  }
}
