<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<WebappShare>
 */
class WebappShareMapper extends QBMapper
{
  public const TABLE = 'jupyter_webapp_shares';

  public function __construct(IDBConnection $db)
  {
    parent::__construct($db, self::TABLE, WebappShare::class);
  }

  /**
   * @throws DoesNotExistException
   */
  public function findByToken(string $token): WebappShare
  {
    $qb = $this->db->getQueryBuilder();
    $qb->select('*')
      ->from(self::TABLE)
      ->where($qb->expr()->eq('token', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)));
    return $this->findEntity($qb);
  }

  /**
   * @return WebappShare[]
   */
  public function findForUser(string $userId, string $direction): array
  {
    $qb = $this->db->getQueryBuilder();
    $qb->select('*')
      ->from(self::TABLE)
      ->where($qb->expr()->eq('local_user', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
      ->andWhere($qb->expr()->eq('direction', $qb->createNamedParameter($direction, IQueryBuilder::PARAM_STR)))
      ->orderBy('created_at', 'DESC');
    return $this->findEntities($qb);
  }
}
