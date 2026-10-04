<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;

/**
 * CarDataTablesService - Server-side DataTables processing for cars
 *
 * Extracted from Car.php to provide focused, testable DataTables logic.
 * Handles server-side processing for cars and factory tables with
 * secure column validation.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarDataTablesService
{
    /** @var array<string, string> Valid table name mapping */
    private const VALID_TABLES = [
        'cars' => 'cars',
        'factory' => 'elan_factory_info'
    ];

    /**
     * Allowed columns per table — dual-purpose, read before editing.
     *
     * Used for BOTH (a) the SELECT list returned to unauthenticated DataTables
     * clients (see getDataTablesData()) and (b) the ORDER BY / WHERE allowlist
     * in validateColumnName(). Adding a column here exposes it publicly.
     *
     * Do not re-add user_id, lat, or lon (removed in #1501 — owner PII and
     * internal identifiers). If a feature needs to sort or filter on a
     * non-public column, split this into separate SELECT and FILTERABLE
     * allowlists rather than widening this one.
     *
     * @var array<string, array<string>>
     */
    private const ALLOWED_COLUMNS = [
        'cars' => [
            'id', 'ctime', 'mtime',
            'model', 'series', 'variant', 'year', 'type', 'chassis', 'color', 'engine',
            'purchasedate', 'solddate', 'comments', 'image', 'fname',
            'join_date', 'city', 'state', 'country', 'website'
        ],
        'elan_factory_info' => [
            'id', 'year', 'month', 'batch', 'type', 'serial', 'suffix',
            'engineletter', 'enginenumber', 'gearbox', 'color', 'builddate', 'note'
        ]
    ];

    /**
     * @param DatabaseInterface $db Database instance
     */
    public function __construct(private DatabaseInterface $db) {}

    /**
     * Get DataTables server-side processing data
     *
     * @param array<string, mixed> $request DataTables request parameters
     * @param string $table Table type ('cars' or 'factory')
     * @return array<string, mixed> DataTables response array
     * @throws CarValidationException If table type is invalid, or start < 0, or length not in [1, 100]
     * @throws CarDatabaseException If a database query fails
     */
    public function getDataTablesData(array $request, string $table): array
    {
        if (!isset(self::VALID_TABLES[$table])) {
            throw new CarValidationException("Invalid table specified");
        }

        $tableName = self::VALID_TABLES[$table];

        $draw = (int) $request['draw'];
        $start = (int) $request['start'];
        $length = (int) $request['length'];

        if ($start < 0) {
            throw new CarValidationException('Invalid start offset: must be >= 0');
        }
        if ($length < 1 || $length > 100) {
            throw new CarValidationException('Invalid length: must be between 1 and 100');
        }

        $searchValue = isset($request['search']['value']) ? trim($request['search']['value']) : '';

        // Build ORDER BY clause
        $orderClauses = [];
        if (isset($request['order']) && is_array($request['order'])) {
            foreach ($request['order'] as $order) {
                $columnIndex = (int) $order['column'];
                $direction = strtoupper($order['dir']) === 'DESC' ? 'DESC' : 'ASC';

                if (isset($request['columns'][$columnIndex]['data'])) {
                    $columnName = $this->validateColumnName($request['columns'][$columnIndex]['data'], $tableName);
                    if ($columnName) {
                        $orderClauses[] = "`{$columnName}` {$direction}";
                    }
                }
            }
        }
        $orderBy = !empty($orderClauses) ? 'ORDER BY ' . implode(', ', $orderClauses) : 'ORDER BY id ASC';

        // Build WHERE clause for search
        $searchWhere = '';
        $searchParams = [];
        if (!empty($searchValue)) {
            $searchConditions = [];
            if (isset($request['columns']) && is_array($request['columns'])) {
                foreach ($request['columns'] as $column) {
                    if (isset($column['searchable']) && $column['searchable'] === 'true' && isset($column['data'])) {
                        $columnName = $this->validateColumnName($column['data'], $tableName);
                        if ($columnName) {
                            $searchConditions[] = "`{$columnName}` LIKE ?";
                            $searchParams[] = "%{$searchValue}%";
                        }
                    }
                }
            }

            if (!empty($searchConditions)) {
                $searchWhere = 'AND (' . implode(' OR ', $searchConditions) . ')';
            }
        }

        // Per-column search — supports series filter pills and future column-specific filters
        $columnSearchClauses = [];
        $columnSearchParams = [];
        if (isset($request['columns']) && is_array($request['columns'])) {
            foreach ($request['columns'] as $column) {
                $colSearch = isset($column['search']['value']) ? trim((string) $column['search']['value']) : '';
                if ($colSearch === '' || !isset($column['data'])) {
                    continue;
                }
                $colName = $this->validateColumnName($column['data'], $tableName);
                if ($colName === false) {
                    continue;
                }
                $columnSearchClauses[] = "`{$colName}` = ?";
                $columnSearchParams[] = $colSearch;
            }
        }
        $columnWhere = !empty($columnSearchClauses) ? 'AND ' . implode(' AND ', $columnSearchClauses) : '';

        $combinedWhere = $searchWhere . ' ' . $columnWhere;
        $combinedParams = array_merge($searchParams, $columnSearchParams);

        // All SQL below is safe: $tableName from VALID_TABLES const map,
        // WHERE/ORDER BY column names validated via validateColumnName() whitelist,
        // search values use prepared statement parameters ($combinedParams)
        $countSql = sprintf('SELECT COUNT(*) as count FROM `%s`', $tableName);
        $countResult = $this->db->query($countSql);
        if ($this->db->error()) {
            throw new CarDatabaseException('DataTables total count query failed: ' . $this->db->errorString());
        }
        $countRow = $countResult->first();
        if (!is_object($countRow)) {
            throw new CarDatabaseException('DataTables total count query returned no rows');
        }
        $totalRecords = $countRow->count;

        $totalFiltered = $totalRecords;
        if (trim($combinedWhere) !== '') {
            $filterSql = sprintf('SELECT COUNT(*) as count FROM `%s` WHERE 1 %s', $tableName, $combinedWhere);
            $filterResult = $this->db->query($filterSql, $combinedParams);
            if ($this->db->error()) {
                throw new CarDatabaseException('DataTables filtered count query failed: ' . $this->db->errorString());
            }
            $filterRow = $filterResult->first();
            if (!is_object($filterRow)) {
                throw new CarDatabaseException('DataTables filtered count query returned no rows');
            }
            $totalFiltered = $filterRow->count;
        }

        // Explicit column list prevents future schema additions from silently exposing
        // new fields; names come from PHP constants, never from user input.
        // For elan_factory_info, also append the car_id lookup subquery
        // (avoids one AJAX chassis-lookup per row, ~25 requests per page turn).
        // For cars, append is_fresh for the Verified badge. It is SELECT-only and
        // not in ALLOWED_COLUMNS, so a client cannot sort or filter on it.
        // The match has no default arm: the VALID_TABLES check above already
        // rejects other tables, and PHPStan proves these two arms cover the
        // rest. A new table in VALID_TABLES fails PHPStan here until it gets
        // its own arm.
        $publicCols = implode(', ', array_map(fn($col) => "`{$col}`", self::ALLOWED_COLUMNS[$tableName]));
        $selectClause = match ($tableName) {
            'elan_factory_info' => $publicCols . ', (SELECT id FROM cars WHERE chassis = elan_factory_info.serial LIMIT 1) AS car_id',
            'cars'              => $publicCols . ', ' . CarRepository::freshnessSql('cars') . ' AS is_fresh',
        };
        $dataSql = sprintf('SELECT %s FROM `%s` WHERE 1 %s %s LIMIT %d, %d', $selectClause, $tableName, $combinedWhere, $orderBy, $start, $length);
        $data = $this->db->query($dataSql, $combinedParams)->results();
        if ($this->db->error()) {
            throw new CarDatabaseException('DataTables data query failed: ' . $this->db->errorString());
        }

        return [
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalFiltered,
            'data' => $data
        ];
    }

    /**
     * Validate column names to prevent SQL injection
     *
     * @param string $columnName Column name to validate
     * @param string $tableName Table name for context
     * @return string|false Validated column name or false if invalid
     */
    private function validateColumnName(string $columnName, string $tableName): string|false
    {
        if (!isset(self::ALLOWED_COLUMNS[$tableName])) {
            return false;
        }

        return in_array($columnName, self::ALLOWED_COLUMNS[$tableName], true) ? $columnName : false;
    }
}
