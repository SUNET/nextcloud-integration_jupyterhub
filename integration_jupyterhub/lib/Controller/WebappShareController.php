<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCapabilityDiscovery;
use OCA\Jupyter\Federation\WebappCloudFederationShare;
use OCA\Jupyter\Federation\WebappShareIntent;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
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
    private LoggerInterface $logger,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @NoAdminRequired
   *
   * @param string|list<string> $target one or more preferred view
   *   targets (iframe / redirect / new-window). The wire-level
   *   `target` field is the intersection of the user's preferences,
   *   this app's full target set, and what the remote advertises in
   *   OCM discovery.
   */
  public function create(string $path, string $shareWith, array|string $target = WebappCloudFederationShare::TARGET_IFRAME): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
    $requestedTargets = $this->normaliseRequestedTargets($target);

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
    $share->setPermissions(Constants::PERMISSION_READ);

    // Announce intent before createShare(): the decorator picks it up
    // by `shareWith` when the outbound OCM share is built. We could
    // also try IShare::setAttributes() but FederatedShareProvider does
    // not persist the attributes JSON, so it'd be gone by the time the
    // decorator's lookup runs.
    $this->intent->announce($shareWith, $targets);

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
      'shareWith' => $shareWith,
    ]);
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
   * @param string|list<string> $target
   * @return list<string>
   */
  private function normaliseRequestedTargets(array|string $target): array
  {
    $raw = is_array($target) ? $target : [$target];
    $out = [];
    foreach ($raw as $t) {
      $value = match (strtolower((string)$t)) {
        WebappCloudFederationShare::TARGET_REDIRECT => WebappCloudFederationShare::TARGET_REDIRECT,
        WebappCloudFederationShare::TARGET_BLANK, 'new-window', 'newwindow', 'new_window' => WebappCloudFederationShare::TARGET_BLANK,
        default => WebappCloudFederationShare::TARGET_IFRAME,
      };
      if (!in_array($value, $out, true)) {
        $out[] = $value;
      }
    }
    return $out === [] ? [WebappCloudFederationShare::TARGET_IFRAME] : $out;
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
