<?php
/**
 * Migrador de pedidos JoomShopping -> WooCommerce.
 *
 * @package PatronatoMigrator\Migrators
 */

declare(strict_types=1);

namespace PatronatoMigrator\Migrators;

use PatronatoMigrator\Helpers\StatusMapper;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Migra mjax8_jshopping_orders + mjax8_jshopping_order_item a WC_Order.
 * Conserva los metadatos custom del negocio chileno (Rut, depositante,
 * banco_id, ciudad_envio, documento_venta, observaciones) y mantiene la
 * trazabilidad con _joomla_order_id en cada pedido.
 */
final class OrderMigrator extends AbstractMigrator
{
    private const META_JOOMLA_ORDER_ID   = '_joomla_order_id';
    private const META_JOOMLA_PRODUCT_ID = '_joomla_product_id';
    private const ROLLBACK_BATCH_SIZE    = 200;
    private const DEFAULT_CURRENCY       = 'CLP';

    /**
     * Cache de mapeos joomla_product_id -> wp_post_id por batch.
     *
     * @var array<int, int>
     */
    private array $productMapping = [];

    protected function getModuleName(): string
    {
        return 'orders';
    }

    public function getTotalCount(): int
    {
        $prefix = $this->connector->getTablePrefix();

        $value = $this->connector->selectValue(
            "SELECT COUNT(*) FROM {$prefix}jshopping_orders"
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
            "SELECT order_id, order_number, order_created, order_status,
                    user_id, email,
                    f_name, l_name, m_name, phone, mobil_phone,
                    street, home, city, state, country, zip,
                    d_f_name, d_l_name, d_m_name, d_phone, d_mobil_phone,
                    d_street, d_home, d_city, d_state, d_country, d_zip,
                    order_total, order_subtotal, order_shipping, order_tax, order_discount,
                    payment_method_id, payment_method_name,
                    shipping_method_id, shipping_method_name,
                    `Rut`             AS rut,
                    depositante,
                    banco_id,
                    ciudad_envio,
                    documento_venta,
                    observaciones
             FROM {$prefix}jshopping_orders
             ORDER BY order_id ASC
             LIMIT :limit OFFSET :offset",
            [
                'limit'  => $limit,
                'offset' => $offset,
            ]
        );

        $orderIds = [];
        foreach ($rows as $row) {
            $oid = isset($row['order_id']) ? (int) $row['order_id'] : 0;
            if ($oid > 0) {
                $orderIds[] = $oid;
            }
        }

        $itemsByOrderId = $this->fetchOrderItems($orderIds);
        $this->productMapping = $this->buildProductMapping($itemsByOrderId);

        $processed = 0;
        $errors    = 0;
        $skipped   = 0;

        foreach ($rows as $row) {
            $joomlaId = isset($row['order_id']) ? (int) $row['order_id'] : 0;
            if ($joomlaId <= 0) {
                $errors++;
                $this->log('error', null, null, 'Pedido origen sin order_id valido.');
                continue;
            }

            try {
                $outcome = $this->processSingleOrder($row, $itemsByOrderId[$joomlaId] ?? []);
            } catch (Throwable $e) {
                $errors++;
                $this->log('error', $joomlaId, null, sprintf('Excepcion al insertar pedido: %s', $e->getMessage()));
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
        if (!function_exists('wc_get_orders')) {
            return;
        }

        while (true) {
            $orders = wc_get_orders([
                'limit'        => self::ROLLBACK_BATCH_SIZE,
                'meta_key'     => self::META_JOOMLA_ORDER_ID,
                'meta_compare' => 'EXISTS',
                'return'       => 'ids',
            ]);

            if (!is_array($orders) || $orders === []) {
                break;
            }

            foreach ($orders as $orderId) {
                $order = wc_get_order((int) $orderId);
                if ($order instanceof WC_Order) {
                    $order->delete(true);
                }
            }
        }
    }

    /**
     * Procesa un unico pedido.
     *
     * @param array<string, mixed>           $row
     * @param array<int, array<string, mixed>> $items
     * @return array{status:string, wp_id:?int}
     */
    private function processSingleOrder(array $row, array $items): array
    {
        $joomlaId = (int) $row['order_id'];

        $existing = $this->findExistingByJoomlaId($joomlaId);
        if ($existing !== null) {
            $this->log('skipped', $joomlaId, $existing, 'Pedido ya migrado previamente.');
            return ['status' => 'skipped', 'wp_id' => $existing];
        }

        $email = isset($row['email']) ? sanitize_email((string) $row['email']) : '';

        $order = new WC_Order();
        $order->set_currency(self::DEFAULT_CURRENCY);
        $order->set_created_via('patronato-migrator');
        $order->set_prices_include_tax(false);

        $createdAt = $this->resolveCreatedDate($row);
        if ($createdAt !== '') {
            $order->set_date_created($createdAt);
        }

        $customerId = 0;
        if ($email !== '' && is_email($email)) {
            $user = get_user_by('email', $email);
            if ($user instanceof WP_User) {
                $customerId = (int) $user->ID;
            }
        }
        $order->set_customer_id($customerId);

        $this->applyBilling($order, $row, $email);
        $this->applyShipping($order, $row);

        $hadMissingProducts = $this->applyItems($order, $items, $joomlaId);
        $this->applyShippingLine($order, $row);

        $shipping = $this->resolveAmount($row['order_shipping'] ?? null);
        $tax      = $this->resolveAmount($row['order_tax'] ?? null);
        $discount = $this->resolveAmount($row['order_discount'] ?? null);
        $total    = $this->resolveAmount($row['order_total'] ?? null);

        if ($shipping !== null) {
            $order->set_shipping_total((string) $shipping);
        }
        if ($discount !== null) {
            $order->set_discount_total((string) $discount);
        }
        if ($tax !== null) {
            $order->set_cart_tax((string) $tax);
        }

        // No invocar calculate_totals(): preservar el snapshot historico tal cual.
        if ($total !== null) {
            $order->set_total((string) $total);
        }

        $order->set_status(StatusMapper::fromJoomla($row['order_status'] ?? null));

        $newOrderId = $order->save();
        if (!is_int($newOrderId) || $newOrderId <= 0) {
            $this->log('error', $joomlaId, null, 'WC_Order::save() devolvio id invalido.');
            return ['status' => 'error', 'wp_id' => null];
        }

        $this->applyMetas($newOrderId, $joomlaId, $row);

        $observaciones = isset($row['observaciones']) ? trim((string) $row['observaciones']) : '';
        if ($observaciones !== '') {
            $order->add_order_note(sanitize_textarea_field($observaciones), 0, false);
        }

        if (!StatusMapper::isKnown($row['order_status'] ?? null)) {
            $this->log(
                'warning',
                $joomlaId,
                $newOrderId,
                sprintf('Status Joomla "%s" desconocido; mapeado a on-hold.', (string) ($row['order_status'] ?? ''))
            );
        }

        if ($hadMissingProducts) {
            $this->log(
                'warning',
                $joomlaId,
                $newOrderId,
                'Pedido contenia productos sin equivalente en WP; se anadieron como line items denormalizados.'
            );
        }

        $this->log('success', $joomlaId, $newOrderId, 'Pedido migrado.');

        return ['status' => 'success', 'wp_id' => $newOrderId];
    }

    /**
     * Aplica los datos de billing al pedido.
     *
     * @param array<string, mixed> $row
     */
    private function applyBilling(WC_Order $order, array $row, string $email): void
    {
        $order->set_billing_first_name($this->fieldStr($row, 'f_name'));
        $order->set_billing_last_name($this->fieldStr($row, 'l_name'));
        $order->set_billing_address_1(trim($this->fieldStr($row, 'street') . ' ' . $this->fieldStr($row, 'home')));
        $order->set_billing_city($this->fieldStr($row, 'city'));
        $order->set_billing_state($this->fieldStr($row, 'state'));
        $order->set_billing_postcode($this->fieldStr($row, 'zip'));
        $order->set_billing_country($this->fieldStr($row, 'country', 'CL'));
        $order->set_billing_phone($this->fieldStr($row, 'phone') !== '' ? $this->fieldStr($row, 'phone') : $this->fieldStr($row, 'mobil_phone'));
        if ($email !== '') {
            $order->set_billing_email($email);
        }
    }

    /**
     * Aplica los datos de shipping al pedido.
     *
     * @param array<string, mixed> $row
     */
    private function applyShipping(WC_Order $order, array $row): void
    {
        $hasShipping = false;
        $candidates  = ['d_f_name', 'd_l_name', 'd_street', 'd_city'];
        foreach ($candidates as $field) {
            if ($this->fieldStr($row, $field) !== '') {
                $hasShipping = true;
                break;
            }
        }

        if (!$hasShipping) {
            return;
        }

        $order->set_shipping_first_name($this->fieldStr($row, 'd_f_name'));
        $order->set_shipping_last_name($this->fieldStr($row, 'd_l_name'));
        $order->set_shipping_address_1(trim($this->fieldStr($row, 'd_street') . ' ' . $this->fieldStr($row, 'd_home')));
        $order->set_shipping_city($this->fieldStr($row, 'd_city'));
        $order->set_shipping_state($this->fieldStr($row, 'd_state'));
        $order->set_shipping_postcode($this->fieldStr($row, 'd_zip'));
        $order->set_shipping_country($this->fieldStr($row, 'd_country', 'CL'));
    }

    /**
     * Anade los items del pedido. Devuelve true si algun producto no existe en WP.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function applyItems(WC_Order $order, array $items, int $joomlaOrderId): bool
    {
        $hadMissing = false;

        foreach ($items as $itemRow) {
            $joomlaProductId = isset($itemRow['product_id']) ? (int) $itemRow['product_id'] : 0;
            $quantity        = isset($itemRow['product_quantity']) ? max(1, (int) $itemRow['product_quantity']) : 1;
            $unitPrice       = $this->resolveAmount($itemRow['product_item_price'] ?? null);
            $itemName        = isset($itemRow['name']) ? sanitize_text_field((string) $itemRow['name']) : '';
            $lineTotal       = $unitPrice !== null ? $unitPrice * $quantity : null;

            $wpProductId = $joomlaProductId > 0 && isset($this->productMapping[$joomlaProductId])
                ? $this->productMapping[$joomlaProductId]
                : 0;

            $product = $wpProductId > 0 ? wc_get_product($wpProductId) : null;

            $item = new WC_Order_Item_Product();

            if ($product instanceof WC_Product) {
                $item->set_product($product);
                if ($itemName === '') {
                    $itemName = $product->get_name();
                }
            } else {
                $hadMissing = true;
                if ($itemName === '') {
                    $itemName = sprintf('Producto Joomla #%d', $joomlaProductId);
                }
            }

            $item->set_name($itemName);
            $item->set_quantity($quantity);

            if ($unitPrice !== null) {
                $item->set_subtotal((string) ($lineTotal ?? 0));
                $item->set_total((string) ($lineTotal ?? 0));
            }

            if ($joomlaProductId > 0) {
                $item->add_meta_data(self::META_JOOMLA_PRODUCT_ID, $joomlaProductId, true);
            }

            $order->add_item($item);
        }

        if ($items === []) {
            $this->log('warning', $joomlaOrderId, null, 'Pedido sin items en mjax8_jshopping_order_item.');
        }

        return $hadMissing;
    }

    /**
     * Anade una linea de shipping si la orden tiene shipping_method_name o monto.
     *
     * @param array<string, mixed> $row
     */
    private function applyShippingLine(WC_Order $order, array $row): void
    {
        $methodName = isset($row['shipping_method_name']) ? sanitize_text_field((string) $row['shipping_method_name']) : '';
        $shipping   = $this->resolveAmount($row['order_shipping'] ?? null);

        if ($methodName === '' && ($shipping === null || $shipping <= 0)) {
            return;
        }

        $shippingItem = new WC_Order_Item_Shipping();
        $shippingItem->set_method_title($methodName !== '' ? $methodName : __('Envio', 'patronato-migrator'));
        $shippingItem->set_method_id('joomla-legacy');
        $shippingItem->set_total((string) ($shipping ?? 0));

        $order->add_item($shippingItem);
    }

    /**
     * Aplica los metadatos custom del negocio chileno y la trazabilidad.
     *
     * @param array<string, mixed> $row
     */
    private function applyMetas(int $orderId, int $joomlaId, array $row): void
    {
        update_post_meta($orderId, self::META_JOOMLA_ORDER_ID, $joomlaId);

        $rut = isset($row['rut']) ? sanitize_text_field((string) $row['rut']) : '';
        if ($rut !== '') {
            update_post_meta($orderId, '_billing_rut', $rut);
        }

        $depositante = isset($row['depositante']) ? sanitize_text_field((string) $row['depositante']) : '';
        if ($depositante !== '') {
            update_post_meta($orderId, '_depositante', $depositante);
        }

        $bancoId = isset($row['banco_id']) && $row['banco_id'] !== '' && is_numeric($row['banco_id'])
            ? (int) $row['banco_id']
            : null;
        if ($bancoId !== null) {
            update_post_meta($orderId, '_banco_id', $bancoId);
        }

        $ciudadEnvio = isset($row['ciudad_envio']) ? sanitize_text_field((string) $row['ciudad_envio']) : '';
        if ($ciudadEnvio !== '') {
            update_post_meta($orderId, '_ciudad_envio', $ciudadEnvio);
        }

        $documentoVenta = isset($row['documento_venta']) ? sanitize_text_field((string) $row['documento_venta']) : '';
        if ($documentoVenta !== '') {
            update_post_meta($orderId, '_documento_venta', $documentoVenta);
        }

        $orderNumber = isset($row['order_number']) ? sanitize_text_field((string) $row['order_number']) : '';
        if ($orderNumber !== '') {
            update_post_meta($orderId, '_joomla_order_number', $orderNumber);
        }
    }

    /**
     * Lee los items de todos los pedidos del batch en una sola query.
     *
     * @param array<int, int> $orderIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function fetchOrderItems(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $prefix       = $this->connector->getTablePrefix();
        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $params       = [];
        foreach ($orderIds as $i => $id) {
            $params[$i] = (int) $id;
        }

        $rows = $this->connector->select(
            "SELECT order_id, product_id, product_quantity, product_item_price, name, product_code
             FROM {$prefix}jshopping_order_item
             WHERE order_id IN ({$placeholders})
             ORDER BY order_id ASC, order_item_id ASC",
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            $oid = isset($row['order_id']) ? (int) $row['order_id'] : 0;
            if ($oid > 0) {
                $map[$oid][] = $row;
            }
        }

        return $map;
    }

    /**
     * Construye el mapeo joomla_product_id -> wp_product_id para los items
     * del batch usando una sola consulta a postmeta.
     *
     * @param array<int, array<int, array<string, mixed>>> $itemsByOrderId
     * @return array<int, int>
     */
    private function buildProductMapping(array $itemsByOrderId): array
    {
        $joomlaIds = [];
        foreach ($itemsByOrderId as $items) {
            foreach ($items as $item) {
                $pid = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                if ($pid > 0) {
                    $joomlaIds[$pid] = true;
                }
            }
        }

        if ($joomlaIds === []) {
            return [];
        }

        global $wpdb;

        $ids          = array_keys($joomlaIds);
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $sql = $wpdb->prepare(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s
               AND meta_value IN ({$placeholders})",
            array_merge([self::META_JOOMLA_PRODUCT_ID], $ids)
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        $mapping = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $jid = isset($row['meta_value']) ? (int) $row['meta_value'] : 0;
                $pid = isset($row['post_id']) ? (int) $row['post_id'] : 0;
                if ($jid > 0 && $pid > 0) {
                    $mapping[$jid] = $pid;
                }
            }
        }

        return $mapping;
    }

    /**
     * Busca un WC_Order existente por meta de origen.
     */
    private function findExistingByJoomlaId(int $joomlaId): ?int
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }

        $found = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => self::META_JOOMLA_ORDER_ID,
            'meta_value' => $joomlaId,
            'return'     => 'ids',
        ]);

        if (!is_array($found) || $found === []) {
            return null;
        }

        return (int) $found[0];
    }

    /**
     * Devuelve la fecha de creacion validada o "" si no hay valor utilizable.
     *
     * @param array<string, mixed> $row
     */
    private function resolveCreatedDate(array $row): string
    {
        $candidates = [
            isset($row['order_created']) ? (string) $row['order_created'] : '',
        ];

        foreach ($candidates as $raw) {
            $raw = trim($raw);
            if ($raw === '' || $raw === '0000-00-00 00:00:00') {
                continue;
            }

            $ts = strtotime($raw);
            if ($ts !== false && $ts > 0) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
        }

        return '';
    }

    /**
     * Convierte un valor monetario a float, rechaza no-numericos.
     *
     * @param mixed $value
     */
    private function resolveAmount($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Lee un campo string del row con sanitizacion + fallback.
     *
     * @param array<string, mixed> $row
     */
    private function fieldStr(array $row, string $key, string $default = ''): string
    {
        if (!isset($row[$key]) || $row[$key] === null) {
            return $default;
        }

        $value = sanitize_text_field((string) $row[$key]);

        return $value !== '' ? $value : $default;
    }
}
