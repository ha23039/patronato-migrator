<?php
/**
 * Migrador de usuarios Joomla -> WordPress (rol customer).
 *
 * @package PatronatoMigrator\Migrators
 */

declare(strict_types=1);

namespace PatronatoMigrator\Migrators;

use Throwable;
use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Migra mjax8_users (block = 0) a WP users con rol "customer". Conserva
 * el RUT y el ID original como user_meta. Los passwords de Joomla nunca
 * se migran: se genera uno aleatorio y se fuerza reset al primer login
 * via default_password_nag.
 */
final class CustomerMigrator extends AbstractMigrator
{
    private const META_JOOMLA_USER_ID = '_joomla_user_id';
    private const META_RUT            = '_rut';
    private const ROLE_CUSTOMER       = 'customer';
    private const ROLLBACK_BATCH_SIZE = 200;

    protected function getModuleName(): string
    {
        return 'customers';
    }

    public function getTotalCount(): int
    {
        $prefix = $this->connector->getTablePrefix();

        $value = $this->connector->selectValue(
            "SELECT COUNT(*) FROM {$prefix}users WHERE block = 0"
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{processed:int,errors:int,skipped:int}
     */
    protected function processBatch(int $offset, int $limit): array
    {
        $prefix = $this->connector->getTablePrefix();

        $rows = $this->connector->select(
            "SELECT id, email, name, username, `Rut` AS rut, registerDate
             FROM {$prefix}users
             WHERE block = 0
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset",
            [
                'limit'  => $limit,
                'offset' => $offset,
            ]
        );

        $processed = 0;
        $errors    = 0;
        $skipped   = 0;

        foreach ($rows as $row) {
            $joomlaId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($joomlaId <= 0) {
                $errors++;
                $this->log('error', null, null, 'Usuario origen sin id valido.');
                continue;
            }

            try {
                $outcome = $this->processSingleUser($row, $joomlaId);
            } catch (Throwable $e) {
                $errors++;
                $this->log('error', $joomlaId, null, sprintf('Excepcion al insertar usuario: %s', $e->getMessage()));
                continue;
            }

            switch ($outcome['status']) {
                case 'success':
                    $processed++;
                    break;
                case 'skipped':
                    $skipped++;
                    break;
                case 'error':
                default:
                    $errors++;
                    break;
            }
        }

        return [
            'processed' => $processed,
            'errors'    => $errors,
            'skipped'   => $skipped,
        ];
    }

    protected function rollbackModule(): void
    {
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        global $wpdb;

        while (true) {
            $sql = $wpdb->prepare(
                "SELECT user_id
                 FROM {$wpdb->usermeta}
                 WHERE meta_key = %s
                 LIMIT %d",
                self::META_JOOMLA_USER_ID,
                self::ROLLBACK_BATCH_SIZE
            );

            $userIds = $wpdb->get_col($sql);

            if (!is_array($userIds) || $userIds === []) {
                break;
            }

            foreach ($userIds as $userId) {
                wp_delete_user((int) $userId);
            }
        }
    }

    /**
     * Procesa un unico usuario.
     *
     * @param array<string, mixed> $row
     * @return array{status:string, wp_id:?int}
     */
    private function processSingleUser(array $row, int $joomlaId): array
    {
        $rawEmail = isset($row['email']) ? (string) $row['email'] : '';
        $email    = sanitize_email($rawEmail);

        if ($email === '' || !is_email($email)) {
            $this->log('error', $joomlaId, null, sprintf('Email invalido o vacio: "%s".', $rawEmail));
            return ['status' => 'error', 'wp_id' => null];
        }

        $existingByMeta = $this->findExistingByJoomlaId($joomlaId);
        if ($existingByMeta !== null) {
            $this->log('skipped', $joomlaId, $existingByMeta, 'Usuario ya migrado previamente (meta).');
            return ['status' => 'skipped', 'wp_id' => $existingByMeta];
        }

        $existingByEmail = get_user_by('email', $email);
        if ($existingByEmail instanceof WP_User) {
            $existingId = (int) $existingByEmail->ID;
            update_user_meta($existingId, self::META_JOOMLA_USER_ID, $joomlaId);
            $this->log('skipped', $joomlaId, $existingId, sprintf('Email "%s" ya registrado; se enlazo el usuario existente.', $email));
            return ['status' => 'skipped', 'wp_id' => $existingId];
        }

        $login           = $this->resolveUniqueLogin($row, $email, $joomlaId);
        $displayName     = $this->resolveDisplayName($row, $email);
        $registerDateStr = $this->resolveRegisterDate($row);

        $userData = [
            'user_login'      => $login,
            'user_email'      => $email,
            'user_pass'       => wp_generate_password(16, true, true),
            'display_name'    => $displayName,
            'user_registered' => $registerDateStr,
            'role'            => self::ROLE_CUSTOMER,
        ];

        $userId = wp_insert_user($userData);

        if ($userId instanceof WP_Error) {
            $this->log('error', $joomlaId, null, sprintf('wp_insert_user fallo: %s', $userId->get_error_message()));
            return ['status' => 'error', 'wp_id' => null];
        }

        $userId = (int) $userId;

        update_user_meta($userId, self::META_JOOMLA_USER_ID, $joomlaId);
        update_user_meta($userId, 'default_password_nag', true);

        $rut = isset($row['rut']) ? sanitize_text_field((string) $row['rut']) : '';
        if ($rut !== '') {
            update_user_meta($userId, self::META_RUT, $rut);
        }

        $this->log('success', $joomlaId, $userId, 'Usuario migrado.');

        return ['status' => 'success', 'wp_id' => $userId];
    }

    /**
     * Busca un WP user por la meta de origen.
     */
    private function findExistingByJoomlaId(int $joomlaId): ?int
    {
        $users = get_users([
            'meta_key'    => self::META_JOOMLA_USER_ID,
            'meta_value'  => $joomlaId,
            'number'      => 1,
            'fields'      => 'ID',
            'count_total' => false,
        ]);

        if (!is_array($users) || $users === []) {
            return null;
        }

        return (int) $users[0];
    }

    /**
     * Resuelve un user_login unico aplicando sufijo numerico ante colision.
     *
     * @param array<string, mixed> $row
     */
    private function resolveUniqueLogin(array $row, string $email, int $joomlaId): string
    {
        $candidate = isset($row['username']) ? sanitize_user((string) $row['username'], true) : '';
        if ($candidate === '') {
            $candidate = sanitize_user(strtok($email, '@') ?: '', true);
        }
        if ($candidate === '') {
            $candidate = 'user_' . $joomlaId;
        }

        $login  = $candidate;
        $suffix = 2;
        while (username_exists($login) !== null) {
            $login = $candidate . '_' . $suffix;
            $suffix++;
            if ($suffix > 9999) {
                $login = $candidate . '_' . wp_generate_password(6, false, false);
                break;
            }
        }

        return $login;
    }

    /**
     * Resuelve el display_name con cadena de fallback.
     *
     * @param array<string, mixed> $row
     */
    private function resolveDisplayName(array $row, string $email): string
    {
        $candidates = [
            isset($row['name']) ? trim((string) $row['name']) : '',
            isset($row['username']) ? trim((string) $row['username']) : '',
            strtok($email, '@') ?: '',
        ];

        foreach ($candidates as $candidate) {
            $clean = sanitize_text_field($candidate);
            if ($clean !== '') {
                return $clean;
            }
        }

        return $email;
    }

    /**
     * Devuelve un DATETIME MySQL valido o el current_time('mysql') como fallback.
     *
     * @param array<string, mixed> $row
     */
    private function resolveRegisterDate(array $row): string
    {
        $raw = isset($row['registerDate']) ? trim((string) $row['registerDate']) : '';

        if ($raw !== '' && $raw !== '0000-00-00 00:00:00') {
            $timestamp = strtotime($raw);
            if ($timestamp !== false && $timestamp > 0) {
                return gmdate('Y-m-d H:i:s', $timestamp);
            }
        }

        return current_time('mysql', true);
    }
}
