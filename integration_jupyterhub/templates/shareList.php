<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

use OCA\Jupyter\AppInfo\Application;

style(Application::APP_ID, 'main');

/** @var array<int, array<string, mixed>> $shares */
$shares = $_['shares'] ?? [];
?>
<div id="content" class="jupyter-share-list">
  <h2><?php p($l->t('JupyterHub webapp shares')); ?></h2>
  <p class="hint">
    <?php p($l->t('No local JupyterHub is configured on this instance, so the launcher below opens each share in the sender\'s JupyterHub.')); ?>
  </p>

  <?php if (empty($shares)) : ?>
    <p class="empty">
      <?php p($l->t('You have not received any JupyterHub webapp shares yet.')); ?>
    </p>
  <?php else : ?>
    <ul class="share-list">
      <?php foreach ($shares as $share) :
        $name = (string)($share['name'] ?? $l->t('Shared notebook'));
        $sender = (string)($share['remoteSharedBy'] ?? $share['remoteOwner'] ?? '');
        $token = (string)($share['token'] ?? '');
        $targets = (array)($share['webapp']['target'] ?? ['iframe']);
        $targetLabel = implode(', ', $targets);
        $href = \OC::$server->get(\OCP\IURLGenerator::class)->linkToRoute(Application::APP_ID . '.page.ocmOpen', ['token' => $token]);
        ?>
        <li>
          <div class="meta">
            <span class="name"><?php p($name); ?></span>
            <?php if ($sender !== '') : ?>
              <span class="sender"><?php p($l->t('from %s', [$sender])); ?></span>
            <?php endif; ?>
            <span class="mode"><?php p($targetLabel); ?></span>
          </div>
          <a class="button primary"
             href="<?php p($href); ?>"
             <?php if (in_array('blank', $targets, true) && !in_array('iframe', $targets, true)) : ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>>
            <?php p($l->t('Open')); ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<style>
.jupyter-share-list {
  padding: 2em;
  max-width: 720px;
  margin: 0 auto;
}
.jupyter-share-list h2 { margin-bottom: 0.5em; }
.jupyter-share-list .hint { color: var(--color-text-maxcontrast); }
.jupyter-share-list .empty { color: var(--color-text-maxcontrast); margin-top: 2em; }
.jupyter-share-list .share-list { list-style: none; padding: 0; margin-top: 1.5em; }
.jupyter-share-list .share-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1em;
  padding: 0.75em 1em;
  border-bottom: 1px solid var(--color-border);
}
.jupyter-share-list .meta { display: flex; flex-direction: column; gap: 2px; }
.jupyter-share-list .meta .name { font-weight: 600; }
.jupyter-share-list .meta .sender,
.jupyter-share-list .meta .mode {
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
}
.jupyter-share-list .button {
  padding: 6px 14px;
  border-radius: var(--border-radius);
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  text-decoration: none;
  font-weight: 500;
}
.jupyter-share-list .button:hover { background: var(--color-primary-element-hover); }
</style>
