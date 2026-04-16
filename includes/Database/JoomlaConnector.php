<?php

declare(strict_types=1);

namespace PatronatoMigrator\Database;

use PatronatoMigrator\Helpers\Encryptor;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Conexion PDO de solo lectura a la base de datos de Joomla origen.
 *
 * Las credenciales se leen cifradas desde wp_options('pm_joomla_credentials')
 * y se descifran al instanciar el conector. El acceso queda limitado a
 * sentencias SELECT mediante metodos publicos cerrados; no se expone ningun
 * mecanismo para ejecutar SQL arbitrario.
 */
final class JoomlaConnector
{
    public const TABLE_PREFIX  = 'mjax8_';
    public const OPTION_KEY    = 'pm_joomla_credentials';

    private const DEFAULT_PORT    = 3306;
    private const DEFAULT_CHARSET = 'utf8mb4';

    private ?PDO $pdo = null;

    /**
     * @var array{host:string,port:int,database:string,user:string,password:string,charset:string}|null
     */
    private ?array $credentials = null;

    /**
     * @param array{host?:string,port?:int|string,database?:string,user?:string,password?:string,charset?:string}|null $credentials
     *        Credenciales explicitas (uso para tests o configuracion manual).
     *        Si se omiten, se cargan desde la opcion cifrada en wp_options.
     */
    public function __construct(?array $credentials = null)
    {
        if ($credentials !== null) {
            $this->credentials = $this->normalizeCredentials($credentials);
        }
    }

    /**
     * Intenta abrir la conexion y devuelve true si es exitosa, false en
     * cualquier caso de error. No propaga excepciones al caller.
     */
    public function test(): bool
    {
        try {
            $this->connect();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Ejecuta un SELECT parametrizado y devuelve todas las filas como array
     * asociativo. La query debe ser una constante de codigo, nunca debe
     * recibir input del usuario sin parametrizar.
     *
     * @param string               $sql      Sentencia SELECT con placeholders nombrados.
     * @param array<string, mixed> $params   Bindings para los placeholders.
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException Si la sentencia no es de solo lectura o
     *                          falla la ejecucion.
     */
    public function select(string $sql, array $params = []): array
    {
        $this->assertReadOnly($sql);

        $stmt = $this->connect()->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Ejecuta un SELECT y devuelve solo la primera fila o null si no hay
     * resultados. La query debe ser una constante de codigo.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException Si la sentencia no es de solo lectura o
     *                          falla la ejecucion.
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $this->assertReadOnly($sql);

        $stmt = $this->connect()->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Devuelve un escalar (primera columna de la primera fila) o null.
     *
     * @param array<string, mixed> $params
     *
     * @throws RuntimeException Si la sentencia no es de solo lectura o
     *                          falla la ejecucion.
     */
    public function selectValue(string $sql, array $params = []): mixed
    {
        $this->assertReadOnly($sql);

        $stmt = $this->connect()->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * Devuelve el prefijo de tabla utilizado por la instalacion Joomla.
     */
    public function getTablePrefix(): string
    {
        return self::TABLE_PREFIX;
    }

    /**
     * Establece (o reutiliza) la conexion PDO.
     *
     * @throws RuntimeException Si las credenciales no estan disponibles o
     *                          la conexion falla.
     */
    private function connect(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $creds = $this->getCredentials();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $creds['host'],
            $creds['port'],
            $creds['database'],
            $creds['charset']
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $creds['user'],
                $creds['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . self::DEFAULT_CHARSET,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                'No se pudo conectar a la base de datos Joomla.',
                (int) $e->getCode()
            );
        }

        return $this->pdo;
    }

    /**
     * Carga las credenciales desde memoria o desde la opcion cifrada.
     *
     * @return array{host:string,port:int,database:string,user:string,password:string,charset:string}
     *
     * @throws RuntimeException Si la opcion no existe o esta corrupta.
     */
    private function getCredentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        if (!function_exists('get_option')) {
            throw new RuntimeException('WordPress no esta cargado: get_option() no disponible.');
        }

        $stored = get_option(self::OPTION_KEY);

        if (!is_string($stored) || $stored === '') {
            throw new RuntimeException('Credenciales de Joomla no configuradas.');
        }

        try {
            $decrypted = Encryptor::decrypt($stored);
        } catch (Throwable $e) {
            throw new RuntimeException('Credenciales de Joomla corruptas o ilegibles.');
        }

        $decoded = json_decode($decrypted, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Credenciales de Joomla con formato invalido.');
        }

        $this->credentials = $this->normalizeCredentials($decoded);

        return $this->credentials;
    }

    /**
     * Valida y normaliza el array de credenciales.
     *
     * @param array<string, mixed> $raw
     * @return array{host:string,port:int,database:string,user:string,password:string,charset:string}
     *
     * @throws RuntimeException Si falta algun campo obligatorio.
     */
    private function normalizeCredentials(array $raw): array
    {
        $required = ['host', 'database', 'user', 'password'];

        foreach ($required as $field) {
            if (!isset($raw[$field]) || !is_scalar($raw[$field]) || (string) $raw[$field] === '') {
                throw new RuntimeException(
                    sprintf('Credencial Joomla requerida ausente o vacia: %s.', $field)
                );
            }
        }

        $port    = isset($raw['port']) ? (int) $raw['port'] : self::DEFAULT_PORT;
        $charset = isset($raw['charset']) && is_string($raw['charset']) && $raw['charset'] !== ''
            ? $raw['charset']
            : self::DEFAULT_CHARSET;

        return [
            'host'     => (string) $raw['host'],
            'port'     => $port > 0 ? $port : self::DEFAULT_PORT,
            'database' => (string) $raw['database'],
            'user'     => (string) $raw['user'],
            'password' => (string) $raw['password'],
            'charset'  => $charset,
        ];
    }

    /**
     * Vincula los parametros usando el tipo PDO apropiado.
     *
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);

            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_BOOL);
            } elseif ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
            }
        }
    }

    /**
     * Garantiza que la sentencia es un SELECT puro. Bloquea cualquier
     * verbo de escritura, DDL o multi-statement.
     *
     * @throws RuntimeException Si la sentencia no es de solo lectura.
     */
    private function assertReadOnly(string $sql): void
    {
        $trimmed = ltrim($sql);

        // Eliminar comentarios de linea y bloque al inicio para evaluar el verbo real.
        $trimmed = preg_replace('#^(?:\s|/\*.*?\*/|--[^\n]*\n|\#[^\n]*\n)+#s', '', $trimmed) ?? $trimmed;

        if (stripos($trimmed, 'SELECT') !== 0 && stripos($trimmed, 'WITH') !== 0) {
            throw new RuntimeException('JoomlaConnector solo admite sentencias SELECT.');
        }

        $forbidden = [
            '/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER|CREATE|RENAME|GRANT|REVOKE|LOCK|UNLOCK|CALL|HANDLER|LOAD)\b/i',
        ];

        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                throw new RuntimeException('JoomlaConnector rechaza sentencias con verbos de escritura.');
            }
        }

        if (str_contains($sql, ';')) {
            // Permitir un punto y coma final, rechazar multi-statement.
            $withoutTrailing = rtrim($sql, "; \t\n\r\0\x0B");
            if (str_contains($withoutTrailing, ';')) {
                throw new RuntimeException('JoomlaConnector no admite multi-statement queries.');
            }
        }
    }
}
