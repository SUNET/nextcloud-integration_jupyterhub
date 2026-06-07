<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCapabilityDiscovery;
use OCA\Jupyter\Federation\WebappShareIntent;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Sender entry point for the "Share as JupyterHub webapp" flow.
 *
 * Builds a normal federated share via {@see IManager::createShare()};
 * the multi-protocol rewrite happens inside the OCM send pipeline via
 * {@see \OCA\Jupyter\Federation\CloudFederationProviderManagerDecorator}.
 * Correlation between the controller and the decorator goes through
 * {@see WebappShareIntent}: we announce the recipient + viewMode just
 * before `createShare()`, the decorator picks it up when it sees the
 * matching `shareWith` on the outbound share.
 */
class WebappShareController extends Controller
{
  public function __construct(
    IRequest $request,
    private IUserSession $userSession,
    private IRootFolder $rootFolder,
    private IManager $shareManager,
    private WebappShareIntent $intent,
    private WebappCapabilityDiscovery $discovery,
    private IConfig $config,
    private LoggerInterface $logger,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @NoAdminRequired
   *
   * @param string|list<string> $permissions the access the sender grants
   *   the recipient: any of `read` / `write` / `share` (read is always
   *   implied). These set the local share's permission mask and flow to
   *   `protocol.webdav/webapp.permissions`; the receiver's ocm-sync runs
   *   one-way for read and two-way once `write` is present.
   *
   *   The view-target set is not a per-share choice — it's the
   *   admin-configured `webapp_allowed_targets`, intersected with what
   *   the remote advertises in OCM discovery.
   */
  public function create(string $path, string $shareWith, array|string $permissions = ['read']): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
    if ($this->config->getAppValue(Application::APP_ID, 'webapp_sharing_enabled', 'no') !== 'yes') {
      return new DataResponse(['error' => 'webapp sharing is disabled on this instance'], Http::STATUS_FORBIDDEN);
    }
    $requestedTargets = $this->allowedTargets();
    $ocmPermissions = $this->normalisePermissions($permissions);

    try {
      $node = $this->rootFolder->getUserFolder($user->getUID())->get($path);
    } catch (NotFoundException) {
      return new DataResponse(['error' => 'folder not found'], Http::STATUS_NOT_FOUND);
    }
    if (!($node instanceof Folder)) {
      return new DataResponse(['error' => 'path is not a folder'], Http::STATUS_BAD_REQUEST);
    }
    if (!$this->folderHasNotebook($node)) {
      return new DataResponse(['error' => 'folder does not contain any .ipynb file'], Http::STATUS_BAD_REQUEST);
    }

    // Compute the wire target list = user prefs ∩ sender caps ∩ remote caps.
    $remoteHost = $this->extractHost($shareWith);
    $remoteSupported = $remoteHost === null
      ? WebappCapabilityDiscovery::ALL_TARGETS
      : $this->discovery->remoteSupportedTargets($remoteHost);
    $targets = $this->discovery->intersect($requestedTargets, $remoteSupported);
    if ($targets === []) {
      return new DataResponse([
        'error' => 'no overlapping webapp target between this server and ' . ($remoteHost ?? 'remote'),
      ], Http::STATUS_BAD_GATEWAY);
    }

    $share = $this->shareManager->newShare();
    $share->setNode($node);
    $share->setSharedBy($user->getUID());
    $share->setShareOwner($user->getUID());
    $share->setShareType(IShare::TYPE_REMOTE);
    $share->setSharedWith($shareWith);
    $share->setPermissions($this->permissionMask($ocmPermissions));

    // Announce intent before createShare(): the decorator picks it up
    // by `shareWith` when the outbound OCM share is built. We could
    // also try IShare::setAttributes() but FederatedShareProvider does
    // not persist the attributes JSON, so it'd be gone by the time the
    // decorator's lookup runs.
    $this->intent->announce($shareWith, $targets, $ocmPermissions);

    try {
      $created = $this->shareManager->createShare($share);
    } catch (GenericShareException $e) {
      return new DataResponse(['error' => $e->getMessage()], $e->getCode() ?: Http::STATUS_BAD_REQUEST);
    } catch (\Throwable $e) {
      $this->logger->warning('Failed to create webapp share', ['exception' => $e]);
      return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
    }

    return new DataResponse([
      'id' => $created->getId(),
      'token' => $created->getToken(),
      'target' => $targets,
      'permissions' => $ocmPermissions,
      'shareWith' => $shareWith,
    ]);
  }

  /**
   * @NoAdminRequired
   *
   * Probe a folder for any direct `.ipynb` child. Lets the Files-app file
   * action decide whether to open the share dialog when the cached
   * metadata is unknown (which is the common case for sibling rows in a
   * parent listing).
   */
  public function check(string $path): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
    try {
      $node = $this->rootFolder->getUserFolder($user->getUID())->get($path);
    } catch (NotFoundException) {
      return new DataResponse(['hasNotebook' => false], Http::STATUS_NOT_FOUND);
    }
    if (!($node instanceof Folder)) {
      return new DataResponse(['hasNotebook' => false]);
    }
    return new DataResponse(['hasNotebook' => $this->folderHasNotebook($node)]);
  }

  private function folderHasNotebook(Folder $folder): bool
  {
    foreach ($folder->getDirectoryListing() as $child) {
      if ($child instanceof \OCP\Files\File && str_ends_with(strtolower($child->getName()), '.ipynb')) {
        return true;
      }
    }
    return false;
  }

  /**
   * The admin-configured view targets this instance offers. Empty or
   * unset config falls back to every target this app supports, so a
   * fresh install shares without an explicit admin step.
   *
   * @return list<string>
   */
  private function allowedTargets(): array
  {
    $raw = $this->config->getAppValue(Application::APP_ID, 'webapp_allowed_targets', '');
    $configured = WebappCapabilityDiscovery::normaliseTargets(
      $raw === '' ? [] : explode(',', $raw),
    );
    return $configured === [] ? WebappCapabilityDiscovery::ALL_TARGETS : $configured;
  }

  /**
   * Canonicalise the sender's requested permissions to the subset
   * {read, write, share}. `read` is always present (you cannot launch
   * or sync a notebook you cannot read), and ordered first.
   *
   * @param string|list<string> $permissions
   * @return list<string>
   */
  private function normalisePermissions(array|string $permissions): array
  {
    $raw = is_array($permissions) ? $permissions : [$permissions];
    $out = ['read'];
    foreach ($raw as $p) {
      $value = match (strtolower((string)$p)) {
        'write', 'readwrite', 'read-write', 'rw' => 'write',
        'share', 'reshare' => 'share',
        default => null,
      };
      if ($value !== null && !in_array($value, $out, true)) {
        $out[] = $value;
      }
    }
    return $out;
  }

  /**
   * Translate the OCM permission strings into a Nextcloud permission
   * mask for the local federated share. `write` grants the full
   * modify set so the recipient's two-way ocm-sync can push changes
   * back over webdav.
   *
   * @param list<string> $permissions output of {@see normalisePermissions()}
   */
  private function permissionMask(array $permissions): int
  {
    $mask = Constants::PERMISSION_READ;
    if (in_array('write', $permissions, true)) {
      $mask |= Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE;
    }
    if (in_array('share', $permissions, true)) {
      $mask |= Constants::PERMISSION_SHARE;
    }
    return $mask;
  }

  private function extractHost(string $cloudId): ?string
  {
    $at = strrpos($cloudId, '@');
    if ($at === false) {
      return null;
    }
    return substr($cloudId, $at + 1) ?: null;
  }
}
