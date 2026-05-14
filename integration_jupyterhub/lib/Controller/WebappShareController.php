<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCloudFederationShare;
use OCA\Jupyter\Share\WebappShareAttributes;
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
 * The frontend submits {path, shareWith, viewMode}. We build a normal
 * federated share via {@see IManager::createShare()} with our webapp
 * attribute set on the share's {@see \OCP\Share\IAttributes}. NC's
 * `FederatedShareProvider` then mints a token, persists the row, and
 * dispatches the outbound OCM payload — our
 * {@see \OCA\Jupyter\Federation\CloudFederationProviderManagerDecorator}
 * intercepts that dispatch and rewrites it to a multi-protocol
 * {@see WebappCloudFederationShare} so one OCM POST carries both the
 * webdav and webapp protocol entries.
 */
class WebappShareController extends Controller
{
  public function __construct(
    IRequest $request,
    private IUserSession $userSession,
    private IRootFolder $rootFolder,
    private IManager $shareManager,
    private LoggerInterface $logger,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @NoAdminRequired
   */
  public function create(string $path, string $shareWith, string $viewMode = WebappCloudFederationShare::VIEW_IFRAME): DataResponse
  {
    $user = $this->userSession->getUser();
    if ($user === null) {
      return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
    }
    $viewMode = $this->normaliseViewMode($viewMode);

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

    $share = $this->shareManager->newShare();
    $share->setNode($node);
    $share->setSharedBy($user->getUID());
    $share->setShareOwner($user->getUID());
    $share->setShareType(IShare::TYPE_REMOTE);
    $share->setSharedWith($shareWith);
    $share->setPermissions(Constants::PERMISSION_READ);

    $attrs = new WebappShareAttributes();
    $attrs->setAttribute(Application::APP_ID, 'webapp', true);
    $attrs->setAttribute(Application::APP_ID, 'viewMode', $viewMode);
    $share->setAttributes($attrs);

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
      'viewMode' => $viewMode,
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

  private function normaliseViewMode(string $mode): string
  {
    return match (strtolower($mode)) {
      WebappCloudFederationShare::VIEW_REDIRECT => WebappCloudFederationShare::VIEW_REDIRECT,
      'new-window', 'newwindow', 'new_window' => WebappCloudFederationShare::VIEW_NEW_WINDOW,
      default => WebappCloudFederationShare::VIEW_IFRAME,
    };
  }
}
