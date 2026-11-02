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

namespace App\Entity;

use Composer\Spdx\SpdxLicenses;
use DateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'asset')]
class Asset
{
  private ?SpdxLicenses $spdx = null;

  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
  private ?int $id = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $path = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $filename = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $bzid = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $username = null;

  #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
  private ?int $file_size = null;

  #[ORM\Column(type: 'string', length: 64)]
  private ?string $hash_sha256 = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $mime_type = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $author = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $source_url = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $license_id = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $license_name = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $license_url = null;

  #[ORM\Column(type: 'text', length: 65535)]
  private ?string $license_text = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $approved_by = null;

  #[ORM\Column(type: 'datetime', nullable: true)]
  private ?DateTime $approved_at = null;

  #[ORM\Column(type: 'datetime', updatable: false)]
  private DateTime $created_at;

  #[ORM\Column(type: 'datetime', nullable: true, updatable: false)]
  private ?DateTime $updated_at = null;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getPath(): ?string
  {
    return $this->path;
  }

  public function setPath(string $path): self
  {
    $this->path = $path;
    return $this;
  }

  public function getFilename(): ?string
  {
    return $this->filename;
  }

  public function setFilename(string $filename): self
  {
    $this->filename = $filename;
    return $this;
  }

  public function getBzid(): ?string
  {
    return $this->bzid;
  }

  public function setBzid(string $bzid): self
  {
    $this->bzid = $bzid;
    return $this;
  }

  public function getUsername(): ?string
  {
    return $this->username;
  }

  public function setUsername(string $username): self
  {
    $this->username = $username;
    return $this;
  }

  public function getFileSize(): ?int
  {
    return $this->file_size;
  }

  public function setFileSize(?int $file_size): self
  {
    $this->file_size = $file_size;
    return $this;
  }

  public function getHashSha256(): ?string
  {
    return $this->hash_sha256;
  }

  public function setHashSha256(string $hash_sha256): self
  {
    $this->hash_sha256 = $hash_sha256;
    return $this;
  }

  public function getMimeType(): ?string
  {
    return $this->mime_type;
  }

  public function setMimeType(string $mime_type): self
  {
    $this->mime_type = $mime_type;
    return $this;
  }

  public function getAuthor(): ?string
  {
    return $this->author;
  }

  public function setAuthor(string $author): self
  {
    $this->author = $author;
    return $this;
  }

  public function getSourceUrl(): ?string
  {
    return $this->source_url;
  }

  public function setSourceUrl(string $source_url): self
  {
    $this->source_url = $source_url;
    return $this;
  }

  public function getLicenseId(): ?string
  {
    return $this->license_id;
  }

  public function setLicenseId(string $license_id): self
  {
    $this->license_id = $license_id;
    return $this;
  }

  public function getLicenseName(): ?string
  {
    return $this->license_name;
  }

  public function setLicenseName(string $license_name): self
  {
    $this->license_name = $license_name;
    return $this;
  }

  public function getLicenseDisplayName(): ?string
  {
    // Initialize the SPDX licenses the first time we use this
    if ($this->spdx === null) {
      $this->spdx = new SpdxLicenses();
    }

    // If this asset has a custom license name, just return that
    if (!empty($this->license_name)) {
      return 'Other - ' . $this->license_name;
    }
    // If not, but it has a valid license ID, return that license name
    elseif ($this->spdx->validate($this->getLicenseId())) {
      return $this->spdx->getLicenseByIdentifier($this->getLicenseId())[0];
    }
    // We don't know what the license is
    else {
      return 'Unknown or invalid';
    }
  }

  public function getLicenseUrl(): ?string
  {
    return $this->license_url;
  }

  public function setLicenseUrl(string $license_url): self
  {
    $this->license_url = $license_url;
    return $this;
  }

  public function getLicenseText(): ?string
  {
    return $this->license_text;
  }

  public function setLicenseText(string $license_text): self
  {
    $this->license_text = $license_text;
    return $this;
  }

  public function getApprovedBy(): ?string
  {
    return $this->approved_by;
  }

  public function setApprovedBy(string $approved_by): self
  {
    $this->approved_by = $approved_by;
    return $this;
  }

  public function getApprovedAt(): ?DateTime
  {
    return $this->approved_at;
  }

  public function setApprovedAtToNow(): self
  {
    $this->approved_at = new DateTime();
    return $this;
  }

  public function getCreatedAt(): ?DateTime
  {
    return $this->created_at;
  }

  public function getUpdatedAt(): ?DateTime
  {
    return $this->updated_at;
  }
}
