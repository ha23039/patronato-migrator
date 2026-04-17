<?php
/**
 * Mapeo de estados de pedidos JoomShopping -> WooCommerce.
 *
 * @package PatronatoMigrator\Helpers
 */

declare(strict_types=1);

namespace PatronatoMigrator\Helpers;

defined('ABSPATH') || exit;

/**
 * Convierte un order_status numerico de Joomla en el slug WC correspondiente.
 *
 * Por decision de proyecto, los estados desconocidos caen en "on-hold" para
 * forzar revision manual antes de procesar pagos historicos. Nunca caer en
 * "pending" como fallback: pending dispararia recordatorios de pago en algunas
 * configuraciones de WooCommerce.
 */
final class StatusMapper
{
    public const FALLBACK_STATUS = 'on-hold';

    /**
     * Mapa fijo segun docs/ARCHITECTURE.md.
     *
     * @var array<int, string>
     */
    private const MAP = [
        1 => 'pending',
        2 => 'processing',
        3 => 'on-hold',
        5 => 'completed',
        6 => 'cancelled',
        7 => 'refunded',
    ];

    /**
     * Devuelve el slug WC (sin prefijo wc-) correspondiente al status Joomla.
     *
     * @param int|string|null $joomlaStatus Valor crudo del campo order_status.
     */
    public static function fromJoomla(mixed $joomlaStatus): string
    {
        if (!is_numeric($joomlaStatus)) {
            return self::FALLBACK_STATUS;
        }

        $key = (int) $joomlaStatus;

        return self::MAP[$key] ?? self::FALLBACK_STATUS;
    }

    /**
     * Devuelve true si el codigo Joomla esta en el mapa explicito.
     */
    public static function isKnown(mixed $joomlaStatus): bool
    {
        if (!is_numeric($joomlaStatus)) {
            return false;
        }

        return isset(self::MAP[(int) $joomlaStatus]);
    }
}
