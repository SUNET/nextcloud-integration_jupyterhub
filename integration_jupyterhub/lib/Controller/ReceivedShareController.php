<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappAppDataStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only lookups for the receiver-side sidebar.
 *
 * Given a local filesystem path (one that's mounted via a federated
 * remote share carrying a webapp protocol entry), return the webapp
 * launcher options so the UI can render "Open in JupyterHub".
 */
class ReceivedShareController extends Controller
{
  public function __construct(
    IRequest $request,
    private IUserSession $userSession,
    private IRootFolder $rootFolder,
    private IMountManager $mountManager,
    private WebappAppDataStore $store,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @NoAdminRequired
   */
  public function showAt(string $path): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
    }

    try {
      $node = $this->rootFolder->getUserFolder($user->getUID())->get($path);
    } catch (NotFoundException) {
      return new DataResponse(['webapp' => null], Http::STATUS_NOT_FOUND);
    }

    // Walk up to the federated-share mount point: a webapp share is
    // attached to the *mount root*, not to a child of the mount.
    $mount = $this->mountManager->find($node->getPath());
    if ($mount === null) {
      return new DataResponse(['webapp' => null]);
    }
    $mountStorage = $mount->getStorage();
    if ($mountStorage === null) {
      return new DataResponse(['webapp' => null]);
    }
    // ExternalStorage from OCA\Files_Sharing\External\Storage exposes the
    // share id via storage cache or storage->getId(); we just brute-force
    // by listing all of our records and matching on fileShareId == this
    // mount's share-id-ish marker.
    $externalShareId = $this->extractExternalShareId($mountStorage);
    if ($externalShareId === null) {
      return new DataResponse(['webapp' => null]);
    }

    $record = $this->store->get($user->getUID(), 'file-share-' . $externalShareId);
    if ($record === null) {
      return new DataResponse(['webapp' => null], Http::STATUS_NOT_FOUND);
    }

    return new DataResponse([
      'webapp' => $record['webapp'] ?? null,
      'name' => $record['name'] ?? null,
      'token' => $record['token'] ?? null,
      'state' => $record['state'] ?? null,
    ]);
  }

  /**
   * NC's federated storage stores the external-share id; reach it via
   * the storage cache id, which is conventionally `shared::<id>` for
   * federated shares. Fall back to null when we can't recognise it.
   */
  private function extractExternalShareId(\OCP\Files\Storage\IStorage $storage): ?string
  {
    $id = $storage->getId();
    // Federated mounts have ids like "shared::<remote>". The numeric
    // external-share id is in the storage's cache. We pull from there:
    if (!method_exists($storage, 'getShareId')) {
      return null;
    }
    /** @var mixed $shareId */
    $shareId = $storage->getShareId();
    return $shareId !== null ? (string)$shareId : null;
  }
}
