<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000200Date20260514000000 extends SimpleMigrationStep
{
  public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
  {
    /** @var ISchemaWrapper $schema */
    $schema = $schemaClosure();

    if (!$schema->hasTable('jupyter_webapp_shares')) {
      $table = $schema->createTable('jupyter_webapp_shares');
      $table->addColumn('id', Types::BIGINT, [
        'autoincrement' => true,
        'notnull' => true,
        'length' => 8,
      ]);
      // 'in' = received from a remote, 'out' = sent to a remote
      $table->addColumn('direction', Types::STRING, [
        'notnull' => true,
        'length' => 8,
      ]);
      // local opaque token used as the URL path segment (/ocm/open/{token})
      $table->addColumn('token', Types::STRING, [
        'notnull' => true,
        'length' => 64,
      ]);
      // outbound only: local folder path that was shared
      $table->addColumn('resource_path', Types::STRING, [
        'notnull' => false,
        'length' => 4000,
      ]);
      // remote opener URI (inbound) or remote cloud-id (outbound)
      $table->addColumn('remote_uri', Types::STRING, [
        'notnull' => false,
        'length' => 4000,
      ]);
      $table->addColumn('remote_user', Types::STRING, [
        'notnull' => false,
        'length' => 255,
      ]);
      $table->addColumn('local_user', Types::STRING, [
        'notnull' => false,
        'length' => 64,
      ]);
      $table->addColumn('shared_secret', Types::STRING, [
        'notnull' => false,
        'length' => 255,
      ]);
      // 'iframe' | 'redirect' | 'new-window'
      $table->addColumn('view_mode', Types::STRING, [
        'notnull' => true,
        'length' => 16,
        'default' => 'iframe',
      ]);
      $table->addColumn('permissions', Types::STRING, [
        'notnull' => false,
        'length' => 255,
      ]);
      $table->addColumn('name', Types::STRING, [
        'notnull' => false,
        'length' => 255,
      ]);
      $table->addColumn('mime_type', Types::STRING, [
        'notnull' => false,
        'length' => 128,
      ]);
      $table->addColumn('state', Types::STRING, [
        'notnull' => true,
        'length' => 16,
        'default' => 'pending',
      ]);
      $table->addColumn('created_at', Types::BIGINT, [
        'notnull' => true,
        'length' => 8,
        'default' => 0,
      ]);
      $table->addColumn('accepted_at', Types::BIGINT, [
        'notnull' => false,
        'length' => 8,
      ]);
      $table->setPrimaryKey(['id']);
      $table->addUniqueIndex(['token'], 'jupyter_webapp_tok');
      $table->addIndex(['local_user', 'direction'], 'jupyter_webapp_user_dir');
      return $schema;
    }
    return null;
  }
}
