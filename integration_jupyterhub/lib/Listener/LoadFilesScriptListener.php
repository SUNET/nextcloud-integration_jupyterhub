<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Jupyter\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads our Files-app extension script (registers the
 * "Share as JupyterHub webapp" file action) whenever the Files app
 * renders.
 *
 * @implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptListener implements IEventListener
{
  public function handle(Event $event): void
  {
    if (!($event instanceof LoadAdditionalScriptsEvent)) {
      return;
    }
    Util::addScript(Application::APP_ID, Application::APP_ID . '-files');
  }
}
