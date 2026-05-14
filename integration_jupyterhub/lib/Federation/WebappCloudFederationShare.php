<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\Federation\CloudFederationShare;
use OCA\Jupyter\AppInfo\Application;

/**
 * OCM federation share of resource type {@see Application::WEBAPP_RESOURCE_TYPE}.
 *
 * Extends Nextcloud's {@see CloudFederationShare} (which already implements
 * {@see \OCP\Federation\ICloudFederationShare}) so we reuse the serializer
 * and wire format. Specialises it for the OCM webapp draft:
 *
 *  - Forces resourceType = "webapp".
 *  - Replaces the parent's single-protocol setter with a multi-protocol
 *    representation: the share's `protocol` field is a list of
 *    `{name, options}` entries, allowing peers to advertise webdav and
 *    webapp alongside each other.
 *
 * See https://github.com/cs3org/OCM-API/blob/develop/work/webapps/webapp-sharing.md
 */
class WebappCloudFederationShare extends CloudFederationShare
{
  public const VIEW_IFRAME = 'iframe';
  public const VIEW_REDIRECT = 'redirect';
  public const VIEW_NEW_WINDOW = 'new-window';

  public function __construct(
    string $shareWith = '',
    string $name = '',
    string $description = '',
    string $providerId = '',
    string $owner = '',
    string $ownerDisplayName = '',
    string $sharedBy = '',
    string $sharedByDisplayName = '',
    string $shareType = 'user',
  ) {
    parent::__construct(
      $shareWith,
      $name,
      $description,
      $providerId,
      $owner,
      $ownerDisplayName,
      $sharedBy,
      $sharedByDisplayName,
      $shareType,
      Application::WEBAPP_RESOURCE_TYPE,
    );
    // The parent constructor wires up a webdav protocol entry by default;
    // we own the protocol field for webapp shares, so start from scratch.
    $this->setProtocol(['name' => 'multi', 'options' => []]);
  }

  /**
   * Add a webapp protocol entry to the share. Idempotent: replaces any
   * existing webapp entry in place.
   *
   * @param list<string> $permissions
   */
  public function setWebappProtocol(
    string $uri,
    string $sharedSecret,
    string $viewMode,
    array $permissions = ['read'],
    ?string $appName = null,
    ?string $mimeType = null,
  ): void {
    $options = [
      'uri' => $uri,
      'sharedSecret' => $sharedSecret,
      'viewMode' => $this->normaliseViewMode($viewMode),
      'permissions' => $permissions,
    ];
    if ($appName !== null) {
      $options['name'] = $appName;
    }
    if ($mimeType !== null) {
      $options['mimeType'] = $mimeType;
    }
    $this->upsertProtocolEntry(Application::WEBAPP_RESOURCE_TYPE, $options);
  }

  /**
   * Add a webdav protocol entry alongside webapp so the receiver can
   * still browse the underlying directory through standard federated
   * sharing. Mirrors the shape NC's CloudFederationShare uses for
   * resourceType=file.
   */
  public function setWebdavProtocol(string $sharedSecret, string $permissionsXml = '{http://open-cloud-mesh.org/ns}share-permissions'): void
  {
    $this->upsertProtocolEntry('webdav', [
      'sharedSecret' => $sharedSecret,
      'permissions' => $permissionsXml,
    ]);
  }

  /**
   * Return the options block of the webapp entry, or null if none set.
   *
   * @return array<string, mixed>|null
   */
  public function getWebappOptions(): ?array
  {
    foreach ($this->protocolEntries() as $entry) {
      if (($entry['name'] ?? null) === Application::WEBAPP_RESOURCE_TYPE && is_array($entry['options'] ?? null)) {
        return $entry['options'];
      }
    }
    return null;
  }

  /**
   * Upsert one named protocol entry into the multi-protocol options list.
   *
   * @param array<string, mixed> $options
   */
  private function upsertProtocolEntry(string $name, array $options): void
  {
    $protocol = $this->getProtocol();
    $entries = $this->protocolEntries();
    $replaced = false;
    foreach ($entries as $i => $entry) {
      if (($entry['name'] ?? null) === $name) {
        $entries[$i] = ['name' => $name, 'options' => $options];
        $replaced = true;
        break;
      }
    }
    if (!$replaced) {
      $entries[] = ['name' => $name, 'options' => $options];
    }
    $this->setProtocol(['name' => 'multi', 'options' => $entries]);
  }

  /**
   * Normalise the entries list out of whatever shape the parent's
   * setProtocol() last accepted (we always write the `multi` shape, but
   * be forgiving on read in case a subclass or test set a flat entry).
   *
   * @return list<array{name:string, options:array<string, mixed>}>
   */
  private function protocolEntries(): array
  {
    $protocol = $this->getProtocol();
    if (($protocol['name'] ?? null) === 'multi' && is_array($protocol['options'] ?? null)) {
      return array_values(array_filter(
        $protocol['options'],
        fn ($e) => is_array($e) && isset($e['name']) && is_array($e['options'] ?? null),
      ));
    }
    if (isset($protocol['name']) && is_array($protocol['options'] ?? null)) {
      return [['name' => $protocol['name'], 'options' => $protocol['options']]];
    }
    return [];
  }

  private function normaliseViewMode(string $mode): string
  {
    return match (strtolower($mode)) {
      self::VIEW_REDIRECT => self::VIEW_REDIRECT,
      'new-window', 'newwindow', 'new_window' => self::VIEW_NEW_WINDOW,
      default => self::VIEW_IFRAME,
    };
  }
}
