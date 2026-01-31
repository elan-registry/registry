<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarValidationException;
use DB;

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

    /** @var array<string, array<string>> Allowed columns per table */
    private const ALLOWED_COLUMNS = [
        'cars' => [
            'id', 'ctime', 'mtime', 'vericode', 'last_verified', 'ModifiedBy',
            'model', 'series', 'variant', 'year', 'type', 'chassis', 'color', 'engine',
            'purchasedate', 'solddate', 'comments', 'image', 'user_id', 'email', 'fname',
            'lname', 'join_date', 'city', 'state', 'country', 'lat', 'lon', 'website'
        ],
        'elan_factory_info' => [
            'id', 'year', 'month', 'batch', 'type', 'serial', 'suffix',
            'engineletter', 'enginenumber', 'gearbox', 'color', 'builddate', 'note'
        ]
    ];

    /**
     * Get DataTables server-side processing data
     *
     * @param array<string, mixed> $request DataTables request parameters
     * @param string $table Table type ('cars' or 'factory')
     * @param DB $db Database instance
     * @return array<string, mixed> DataTables response array
     * @throws CarValidationException If table parameter is invalid
     */
    public function getDataTablesData(array $request, string $table, DB $db): array
    {
        if (!isset(self::VALID_TABLES[$table])) {
            throw new CarValidationException("Invalid table specified");
        }

        $tableName = self::VALID_TABLES[$table];

        $draw = (int) $request['draw'];
        $start = (int) $request['start'];
        $length = (int) $request['length'];
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

        // All SQL below is safe: $tableName from VALID_TABLES const map,
        // column names validated via validateColumnName() whitelist,
        // search values use prepared statement parameters ($searchParams)
        $countSql = sprintf('SELECT COUNT(*) as count FROM `%s`', $tableName);
        $totalRecords = $db->query($countSql)->first()->count;

        $totalFiltered = $totalRecords;
        if (!empty($searchWhere)) {
            $filterSql = sprintf('SELECT COUNT(*) as count FROM `%s` WHERE 1 %s', $tableName, $searchWhere);
            $totalFiltered = $db->query($filterSql, $searchParams)->first()->count;
        }

        $dataSql = sprintf('SELECT * FROM `%s` WHERE 1 %s %s LIMIT %d, %d', $tableName, $searchWhere, $orderBy, $start, $length);
        $data = $db->query($dataSql, $searchParams)->results();

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
    public function validateColumnName(string $columnName, string $tableName): string|false
    {
        if (!isset(self::ALLOWED_COLUMNS[$tableName])) {
            return false;
        }

        if (in_array($columnName, self::ALLOWED_COLUMNS[$tableName], true)) {
            return $columnName;
        }

        return false;
    }
}
