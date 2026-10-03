<?php

namespace Drupal\tc_auth\Service;

use Drupal\Core\Database\Connection;

/**
 * Service to record and query user login history.
 */
class LoginHistoryManager {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs a LoginHistoryManager object.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Records a login event in the database safely.
   *
   * @param int $uid
   * @param string|null $ip_address
   * @param string|null $user_agent
   * @param string $auth_method
   *
   * @return int
   */
  public function recordLogin(int $uid, ?string $ip_address = NULL, ?string $user_agent = NULL, string $auth_method = 'oauth2_password'): int {
    if ($uid <= 0) {
      return 0;
    }

    try {
      $schema = $this->database->schema();
      if (!$schema->tableExists('tc_auth_login_history')) {
        // Auto-create table if missing
        module_load_include('install', 'tc_auth');
        $module_schema = tc_auth_schema();
        if (isset($module_schema['tc_auth_login_history'])) {
          $schema->createTable('tc_auth_login_history', $module_schema['tc_auth_login_history']);
        }
      }

      $now = \Drupal::time()->getRequestTime();
      $ip = $ip_address ?: (\Drupal::request()->getClientIp() ?: '127.0.0.1');
      $ua = $user_agent ?: (\Drupal::request()->headers->get('User-Agent') ?: 'Unknown');

      return (int) $this->database->insert('tc_auth_login_history')
        ->fields([
          'uid' => $uid,
          'timestamp' => $now,
          'ip_address' => substr($ip, 0, 45),
          'user_agent' => $ua,
          'auth_method' => $auth_method,
        ])
        ->execute();
    }
    catch (\Exception $e) {
      \Drupal::logger('tc_auth')->error('Error recording login history: @msg', ['@msg' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Retrieves login history for a given user ID.
   *
   * @param int $uid
   * @param int $limit
   * @param int $offset
   *
   * @return array
   */
  public function getUserHistory(int $uid, int $limit = 20, int $offset = 0): array {
    try {
      $schema = $this->database->schema();
      if (!$schema->tableExists('tc_auth_login_history')) {
        return [];
      }

      $query = $this->database->select('tc_auth_login_history', 'h')
        ->fields('h', ['id', 'timestamp', 'ip_address', 'user_agent', 'auth_method'])
        ->condition('uid', $uid)
        ->orderBy('timestamp', 'DESC')
        ->range($offset, $limit);

      $results = $query->execute()->fetchAll();
      $formatted = [];

      foreach ($results as $row) {
        $formatted[] = [
          'id' => (int) $row->id,
          'timestamp' => (int) $row->timestamp,
          'datetime_iso' => date('c', (int) $row->timestamp),
          'datetime_formatted' => date('Y-m-d H:i:s', (int) $row->timestamp),
          'ip_address' => $row->ip_address,
          'user_agent' => $row->user_agent,
          'auth_method' => $row->auth_method,
        ];
      }

      return $formatted;
    }
    catch (\Exception $e) {
      \Drupal::logger('tc_auth')->error('Error fetching login history: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

}