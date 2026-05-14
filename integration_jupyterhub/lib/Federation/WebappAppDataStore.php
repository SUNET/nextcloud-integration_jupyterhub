<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OCA\Jupyter\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Tiny IAppData-backed JSON store for inbound webapp shares.
 *
 * Each record is a single JSON file under a per-user subdirectory:
 *
 *     <appdata-root>/webapp-shares/<localUid>/<token>.json
 *
 * We pick {@see IAppDataFactory} over a custom DB table because OCP gives
 * us per-app blob storage out of the box, and the record set is small
 * (one entry per received share). Keying by share token means the
 * sender's OCM share token round-trips as our local lookup key.
 */
class WebappAppDataStore
{
  private const ROOT = 'webapp-shares';

  public function __construct(
    private IAppDataFactory $appDataFactory,
  ) {
  }

  /**
   * @param array<string, mixed> $record
   */
  public function put(string $localUid, string $token, array $record): void
  {
    $folder = $this->ensureFolder($localUid);
    $name = $this->safeName($token) . '.json';
    if ($folder->fileExists($name)) {
      $file = $folder->getFile($name);
    } else {
      $file = $folder->newFile($name);
    }
    $file->putContent(json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
  }

  /**
   * @return array<string, mixed>|null
   */
  public function get(string $localUid, string $token): ?array
  {
    try {
      $folder = $this->folder($localUid);
      $file = $folder->getFile($this->safeName($token) . '.json');
    } catch (NotFoundException) {
      return null;
    }
    $decoded = json_decode($file->getContent(), true);
    return is_array($decoded) ? $decoded : null;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listForUser(string $localUid): array
  {
    try {
      $folder = $this->folder($localUid);
    } catch (NotFoundException) {
      return [];
    }
    $out = [];
    foreach ($folder->getDirectoryListing() as $file) {
      $decoded = json_decode($file->getContent(), true);
      if (is_array($decoded)) {
        $out[] = $decoded;
      }
    }
    return $out;
  }

  public function delete(string $localUid, string $token): void
  {
    try {
      $folder = $this->folder($localUid);
      $file = $folder->getFile($this->safeName($token) . '.json');
      $file->delete();
    } catch (NotFoundException) {
      // already gone
    }
  }

  /** @throws NotFoundException */
  private function folder(string $localUid): ISimpleFolder
  {
    $root = $this->appDataFactory->get(Application::APP_ID);
    return $root->getFolder(self::ROOT)->getFolder($this->safeName($localUid));
  }

  private function ensureFolder(string $localUid): ISimpleFolder
  {
    $root = $this->appDataFactory->get(Application::APP_ID);
    try {
      $sharesRoot = $root->getFolder(self::ROOT);
    } catch (NotFoundException) {
      $sharesRoot = $root->newFolder(self::ROOT);
    }
    $userKey = $this->safeName($localUid);
    try {
      return $sharesRoot->getFolder($userKey);
    } catch (NotFoundException) {
      return $sharesRoot->newFolder($userKey);
    }
  }

  /**
   * IAppData paths are forgiving but we still want to keep names safe and
   * round-trippable across filesystems.
   */
  private function safeName(string $input): string
  {
    return preg_replace('/[^A-Za-z0-9_.-]/', '_', $input) ?? 'invalid';
  }
}
