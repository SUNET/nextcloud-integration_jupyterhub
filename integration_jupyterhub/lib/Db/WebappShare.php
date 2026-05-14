<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getDirection()
 * @method void setDirection(string $direction)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string|null getResourcePath()
 * @method void setResourcePath(?string $path)
 * @method string|null getRemoteUri()
 * @method void setRemoteUri(?string $uri)
 * @method string|null getRemoteUser()
 * @method void setRemoteUser(?string $user)
 * @method string|null getLocalUser()
 * @method void setLocalUser(?string $user)
 * @method string|null getSharedSecret()
 * @method void setSharedSecret(?string $secret)
 * @method string getViewMode()
 * @method void setViewMode(string $mode)
 * @method string|null getPermissions()
 * @method void setPermissions(?string $perms)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getMimeType()
 * @method void setMimeType(?string $mime)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int|null getAcceptedAt()
 * @method void setAcceptedAt(?int $ts)
 */
class WebappShare extends Entity
{
  public const DIRECTION_IN = 'in';
  public const DIRECTION_OUT = 'out';

  public const VIEW_IFRAME = 'iframe';
  public const VIEW_REDIRECT = 'redirect';
  public const VIEW_NEW_WINDOW = 'new-window';

  protected string $direction = '';
  protected string $token = '';
  protected ?string $resourcePath = null;
  protected ?string $remoteUri = null;
  protected ?string $remoteUser = null;
  protected ?string $localUser = null;
  protected ?string $sharedSecret = null;
  protected string $viewMode = self::VIEW_IFRAME;
  protected ?string $permissions = null;
  protected ?string $name = null;
  protected ?string $mimeType = null;
  protected string $state = 'pending';
  protected int $createdAt = 0;
  protected ?int $acceptedAt = null;

  public function __construct()
  {
    $this->addType('createdAt', 'integer');
    $this->addType('acceptedAt', 'integer');
  }
}
