<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Repair;

use OCA\Jupyter\AppInfo\Application;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Registers our boolean folder-level metadata key with NC's
 * {@see IFilesMetadataManager}. Once registered, the key:
 *
 *  - is persisted in `oc_files_metadata` per file id;
 *  - is exposed via WebDAV PROPFIND as
 *    `{http://nextcloud.org/ns}metadata-jupyter-has-notebook`;
 *  - is consumed by the frontend on `node.attributes['metadata-jupyter-has-notebook']`,
 *    letting the "Share as JupyterHub webapp" file action's `enabled()`
 *    callback decide synchronously.
 *
 * The actual value is set by {@see \OCA\Jupyter\Listener\HasNotebookMetadataListener}
 * during {@see \OCP\FilesMetadata\Event\MetadataLiveEvent}.
 *
 * Runs as a `post-migration` repair step because `initMetadata()` lazy-
 * loads app config — the NC docs explicitly say not to call it during
 * app boot.
 */
class RegisterFilesMetadata implements IRepairStep
{
  public const KEY = 'jupyter-has-notebook';

  public function __construct(
    private IFilesMetadataManager $metadataManager,
  ) {
  }

  public function getName(): string
  {
    return 'Register integration_jupyterhub files metadata key';
  }

  public function run(IOutput $output): void
  {
    $this->metadataManager->initMetadata(
      self::KEY,
      IMetadataValueWrapper::TYPE_BOOL,
      indexed: false,
      editPermission: IMetadataValueWrapper::EDIT_FORBIDDEN,
    );
    $output->info(sprintf('Registered files metadata key %s for app %s', self::KEY, Application::APP_ID));
  }
}
