<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Jupyter\Repair\RegisterFilesMetadata;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\FilesMetadata\Event\MetadataLiveEvent;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

/**
 * Computes the {@see RegisterFilesMetadata::KEY} boolean metadata for
 * folders so the Files-app file action can decide synchronously
 * whether to offer the "Share as JupyterHub webapp" entry.
 *
 * Two flows feed into the value:
 *
 *  - {@see MetadataLiveEvent}: NC asks every metadata provider to fill
 *    in values for a node. We respond only when the node is a folder.
 *
 *  - {@see NodeCreatedEvent} / {@see NodeWrittenEvent} /
 *    {@see NodeRenamedEvent} / {@see NodeDeletedEvent}: when a `.ipynb`
 *    file is added/changed/removed, we refresh the parent folder's
 *    metadata so the cached `has-notebook` value stays accurate.
 *
 * @implements IEventListener<MetadataLiveEvent|NodeCreatedEvent|NodeWrittenEvent|NodeRenamedEvent|NodeDeletedEvent>
 */
class HasNotebookMetadataListener implements IEventListener
{
  public function __construct(
    private IFilesMetadataManager $metadataManager,
  ) {
  }

  public function handle(Event $event): void
  {
    if ($event instanceof MetadataLiveEvent) {
      $this->annotate($event);
      return;
    }
    if ($event instanceof NodeCreatedEvent
      || $event instanceof NodeWrittenEvent
      || $event instanceof NodeRenamedEvent
      || $event instanceof NodeDeletedEvent
    ) {
      $this->refreshParentForNotebookChange($event);
      return;
    }
  }

  /**
   * Read the folder's direct children, set the boolean iff any of them
   * has a `.ipynb` filename.
   */
  private function annotate(MetadataLiveEvent $event): void
  {
    $node = $event->getNode();
    if (!($node instanceof Folder)) {
      return;
    }
    $event->getMetadata()->setBool(RegisterFilesMetadata::KEY, $this->folderHasNotebook($node));
  }

  /**
   * If a `.ipynb` was just created/written/renamed/deleted, the parent
   * folder's cached `has-notebook` might be stale. Trigger NC to
   * recompute by calling `refreshMetadata()` on the parent.
   */
  private function refreshParentForNotebookChange(Event $event): void
  {
    $node = $this->affectedNode($event);
    if ($node === null) {
      return;
    }
    if (!$this->looksLikeNotebook($node)) {
      return;
    }
    try {
      $parent = $node->getParent();
    } catch (\Throwable) {
      return;
    }
    if (!($parent instanceof Folder)) {
      return;
    }
    try {
      $this->metadataManager->refreshMetadata($parent, IFilesMetadataManager::PROCESS_LIVE);
    } catch (\Throwable) {
      // best-effort; missing metadata just means the action shows up
      // (or doesn't) on the next folder visit instead of immediately.
    }
  }

  private function affectedNode(Event $event): ?Node
  {
    if (method_exists($event, 'getNode')) {
      $node = $event->getNode();
      return $node instanceof Node ? $node : null;
    }
    if ($event instanceof NodeRenamedEvent && method_exists($event, 'getTarget')) {
      $node = $event->getTarget();
      return $node instanceof Node ? $node : null;
    }
    return null;
  }

  private function looksLikeNotebook(Node $node): bool
  {
    return str_ends_with(strtolower($node->getName()), '.ipynb');
  }

  private function folderHasNotebook(Folder $folder): bool
  {
    try {
      foreach ($folder->getDirectoryListing() as $child) {
        if ($child instanceof \OCP\Files\File && $this->looksLikeNotebook($child)) {
          return true;
        }
      }
    } catch (\Throwable) {
      return false;
    }
    return false;
  }
}
