<?php

require_once KYUTILS_PATH . '/modules/Db.php';
require_once KYUTILS_PATH . '/exceptions/SpectroError.php';

class BaseEntity
{
    protected static $entityName;
    private static $GROUP_SEPARATOR = ':-:';
    private static $tableColumnCache = [];

    private static function getTableColumns($tableName)
    {
        if (isset(self::$tableColumnCache[$tableName])) {
            return self::$tableColumnCache[$tableName];
        }

        $columns = Db::all("DESCRIBE `{$tableName}`");
        
        self::$tableColumnCache[$tableName] = $columns;
        return $columns;
    }

    protected static function getDefinition()
    {
        return Entity::getDefinition(static::$entityName);
    }

    private static function getSeparator($dbColumn)
    {
        $definition = static::getDefinition();
        if (isset($definition['separators'][$dbColumn])) {
            return $definition['separators'][$dbColumn];
        }
        return self::$GROUP_SEPARATOR;
    }

    private static function findFieldMapping($fieldName) {
        $definition = static::getDefinition();
        $storage_mode = isset($definition['storage_mode']) ? $definition['storage_mode'] : 'grouped_string';

        if ($storage_mode === 'json') {
            if (in_array($fieldName, isset($definition['fields']['VALUE']) ? $definition['fields']['VALUE'] : [])) {
                return ['type' => 'json', 'column' => 'VALUE', 'field' => $fieldName];
            }
        } else { // grouped_string
            foreach ($definition['fields'] as $dbColumn => $prop) {
                if (is_array($prop)) {
                    $index = array_search($fieldName, $prop);
                    if ($index !== false) {
                        return ['type' => 'grouped', 'column' => $dbColumn, 'index' => $index];
                    }
                } else {
                    if ($prop === $fieldName) {
                        return ['type' => 'direct', 'column' => $dbColumn];
                    }
                }
            }
        }
        return ['type' => 'direct', 'column' => $fieldName];
    }

    protected static function buildWhereClause($conditions, &$paramIndex = 0)
    {
        if (empty($conditions)) {
            return ['1', []];
        }

        $params = [];
        
        $operator = 'AND';
        $first = $conditions[0];
        if (is_string($first) && in_array(strtolower($first), ['and', 'or'])) {
            $operator = strtoupper(array_shift($conditions));
        }

        $sqlParts = [];
        foreach ($conditions as $condition) {
            // Check for nested group
            if (is_array($condition) && isset($condition[0]) && is_string($condition[0]) && in_array(strtoupper($condition[0]), ['AND', 'OR'])) {
                list($nestedSql, $nestedParams) = static::buildWhereClause($condition, $paramIndex);
                if ($nestedSql !== '1') {
                    $sqlParts[] = "({$nestedSql})";
                    $params = array_merge($params, $nestedParams);
                }
            } 
            // Check for simple condition
            else if (is_array($condition) && count($condition) === 3 && is_string($condition[1])) {
                list($field, $op, $value) = $condition;
                
                $paramName = ":param_{$paramIndex}";
                $paramKey = "param_{$paramIndex}";
                $params[$paramKey] = $value;
                $paramIndex++;

                $mapping = static::findFieldMapping($field);
                $fieldSql = '';

                switch ($mapping['type']) {
                    case 'json':
                        $col = $mapping['column'];
                        $fld = $mapping['field'];
                        $fieldSql = "JSON_UNQUOTE(JSON_EXTRACT(`{$col}`, '$.{$fld}'))";
                        break;
                    case 'grouped':
                        $col = $mapping['column'];
                        $idx = $mapping['index'] + 1;
                        $sep = static::getSeparator($col);
                        $fieldSql = "SUBSTRING_INDEX(SUBSTRING_INDEX(`{$col}`, '{$sep}', {$idx}), '{$sep}', -1)";
                        break;
                    case 'direct':
                    default:
                        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $mapping['column']);
                        $fieldSql = "`{$col}`";
                        break;
                }
                
                $sqlParts[] = "{$fieldSql} {$op} {$paramName}";
            } else {
                throw new InvalidArgumentException('Invalid condition format: ' . print_r($condition, true));
            }
        }

        if (empty($sqlParts)) {
            return ['1', []];
        }

        $where = implode(" {$operator} ", $sqlParts);
        return [$where, $params];
    }

    protected static function hydrate($data)
    {
        if (!$data) {
            return null;
        }
        $definition = static::getDefinition();
        $storage_mode = isset($definition['storage_mode']) ? $definition['storage_mode'] : 'grouped_string';
        $entity = [];

        if (isset($data['ID'])) $entity['id'] = (int)$data['ID'];
        if (isset($data['SEQUENCE'])) $entity['createdAt'] = $data['SEQUENCE'];

        if ($storage_mode === 'json') {
            if (isset($data['VALUE'])) {
                $decoded = json_decode($data['VALUE'], true);
                if (is_array($decoded)) {
                    $entity = array_merge($entity, $decoded);
                }
            }
        } else { // grouped_string
            foreach ($definition['fields'] as $dbColumn => $prop) {
                if (!isset($data[$dbColumn])) continue;

                if (is_array($prop)) {
                    $separator = static::getSeparator($dbColumn);
                    $values = explode($separator, $data[$dbColumn]);
                    foreach ($prop as $index => $propName) {
                        $entity[$propName] = isset($values[$index]) ? $values[$index] : null;
                    }
                } else {
                    $entity[$prop] = $data[$dbColumn];
                }
            }
        }
        
        $entityObject = (object)$entity;
        foreach ($definition['computed'] as $propName => $closure) {
            if ($closure instanceof Closure) {
                $temp = $closure->bindTo($entityObject);
                $entity[$propName] = $temp();
            }
        }

        return $entity;
    }

    protected static function dehydrate($data)
    {
        $definition = static::getDefinition();
        $storage_mode = isset($definition['storage_mode']) ? $definition['storage_mode'] : 'grouped_string';
        $dbData = [];

        if ($storage_mode === 'json') {
            $jsonData = [];
            $field_list = isset($definition['fields']['VALUE']) ? $definition['fields']['VALUE'] : [];
            foreach ($field_list as $field) {
                if (isset($data[$field])) {
                    $jsonData[$field] = $data[$field];
                }
            }
            $dbData['VALUE'] = json_encode($jsonData, JSON_UNESCAPED_UNICODE);
        } else { // grouped_string
            foreach ($definition['fields'] as $dbColumn => $prop) {
                if (is_array($prop)) {
                    $values = [];
                    foreach ($prop as $p) {
                        $values[] = isset($data[$p]) ? $data[$p] : '';
                    }
                    $separator = static::getSeparator($dbColumn);
                    $dbData[$dbColumn] = implode($separator, $values);
                } else {
                    if (isset($data[$prop])) {
                        $dbData[$dbColumn] = $data[$prop];
                    }
                }
            }
        }

        return $dbData;
    }

    public static function create($data)
    {
        $definition = static::getDefinition();
        $table = $definition['table'];
        $dbData = static::dehydrate($data);
        
        $dbData['ID'] = GetUnicalIds($table, 1)[0];
        $dbData['TYPE'] = static::$entityName;
        $dbData['SEQUENCE'] = time();

        $tableColumns = static::getTableColumns($table);

        foreach ($tableColumns as $columnInfo) {
            $columnName = $columnInfo['Field'];

            if (isset($dbData[$columnName]) || $columnInfo['Key'] === 'PRI') {
                continue;
            }

            $columnType = strtolower($columnInfo['Type']);
            
            if (strpos($columnType, 'int') !== false || strpos($columnType, 'decimal') !== false || strpos($columnType, 'float') !== false || strpos($columnType, 'double') !== false) {
                $dbData[$columnName] = 0;
            } else {
                $dbData[$columnName] = '';
            }
        }

        return Db::insert($table, $dbData);
    }

    public static function getOne($conditions)
    {
        $definition = static::getDefinition();
        $table = $definition['table'];
        $params = ['_entity_type' => static::$entityName];
        $typeWhere = "`TYPE` = :_entity_type";
        $userWhere = '1';

        if (is_numeric($conditions)) {
            $userWhere = '`ID` = :id';
            $params['id'] = $conditions;
        } else {
            list($userWhere, $userParams) = static::buildWhereClause($conditions);
            if ($userWhere !== '1') {
                $params = array_merge($params, $userParams);
            }
        }

        $finalWhere = $typeWhere;
        if ($userWhere !== '1') {
            $finalWhere .= " AND ({$userWhere})";
        }

        $row = Db::one("SELECT * FROM `{$table}` WHERE {$finalWhere} LIMIT 1", $params);
        return static::hydrate($row);
    }

    public static function get($conditions = [], $options = [])
    {
        $definition = static::getDefinition();
        $table = $definition['table'];
        
        $params = ['_entity_type' => static::$entityName];
        $typeWhere = "`TYPE` = :_entity_type";

        list($userWhere, $userParams) = static::buildWhereClause($conditions);
        
        $finalWhere = $typeWhere;
        if ($userWhere !== '1') {
            $finalWhere .= " AND ({$userWhere})";
            $params = array_merge($params, $userParams);
        }

        // Get total count for pagination if requested
        if (array_key_exists('pagination', $options)) {
            $countSql = "SELECT COUNT(*) as total FROM `{$table}` WHERE {$finalWhere}";
            $countResult = Db::one($countSql, $params);
            $totalRows = $countResult ? (int)$countResult['total'] : 0;

            if (!is_array($options['pagination'])) {
                $options['pagination'] = [];
            }

            $options['pagination']['rowCount'] = $totalRows;
            
            if (isset($options['per_page']) && is_numeric($options['per_page']) && $options['per_page'] > 0) {
                $options['pagination']['pagesCount'] = (int)ceil($totalRows / (int)$options['per_page']);
            } else {
                $options['pagination']['pagesCount'] = $totalRows > 0 ? 1 : 0;
            }
        }

        // Sorting
        $orderClause = '';
        if (isset($options['sort'])) {
            $sortField = $options['sort'];
            
            $orderDirection = 'ASC';
            if (isset($options['order']) && strtolower($options['order']) === 'desc') {
                $orderDirection = 'DESC';
            }
            
            $mapping = static::findFieldMapping($sortField);
            $sortSql = '';
            switch ($mapping['type']) {
                case 'json':
                    $col = $mapping['column'];
                    $fld = $mapping['field'];
                    $sortSql = "JSON_UNQUOTE(JSON_EXTRACT(`{$col}`, '$.{$fld}'))";
                    break;
                case 'grouped':
                    $col = $mapping['column'];
                    $idx = $mapping['index'] + 1;
                    $sep = static::getSeparator($col);
                    $sortSql = "SUBSTRING_INDEX(SUBSTRING_INDEX(`{$col}`, '{$sep}', {$idx}), '{$sep}', -1)";
                    break;
                case 'direct':
                default:
                    $col = preg_replace('/[^a-zA-Z0-9_]/', '', $mapping['column']);
                    $sortSql = "`{$col}`";
                    break;
            }

            if ($sortSql) {
                 $orderClause = " ORDER BY {$sortSql} {$orderDirection}";
            }
        }

        // Pagination
        $limitClause = '';
        if (isset($options['per_page']) && is_numeric($options['per_page'])) {
            $perPage = (int)$options['per_page'];
            $page = (isset($options['page']) && is_numeric($options['page'])) ? (int)$options['page'] : 1;
            if ($page < 1) $page = 1;
            $offset = ($page - 1) * $perPage;
            $limitClause = " LIMIT {$offset}, {$perPage}";
        }

        $sql = "SELECT * FROM `{$table}` WHERE {$finalWhere}{$orderClause}{$limitClause}";
        $rows = Db::all($sql, $params);

        if (array_key_exists('pagination', $options)) {
            return [
                'items' => array_map([static::class, 'hydrate'], $rows),
                'pagination' => $options['pagination']
            ];
        }
        
        return array_map([static::class, 'hydrate'], $rows);
    }

    public static function all($options = []) {
        return static::get([], $options);
    }

    public static function update($conditions, $data)
    {
        $item = static::getOne($conditions);

        if (!$item) {
            return 0;
        }

        $id = $item['id'];
        
        $definition = static::getDefinition();
        $table = $definition['table'];
        $storage_mode = isset($definition['storage_mode']) ? $definition['storage_mode'] : 'grouped_string';
        $dehydratedData = [];

        $where = "`ID` = :id AND `TYPE` = :_entity_type";
        $params = ['id' => $id, '_entity_type' => static::$entityName];

        if ($storage_mode === 'json') {
            $existing = Db::one("SELECT VALUE FROM `{$table}` WHERE {$where}", $params);
            $existingData = $existing ? json_decode($existing['VALUE'], true) : [];
            $newData = array_merge($existingData, $data);
            $dehydratedData['VALUE'] = json_encode($newData, JSON_UNESCAPED_UNICODE);
        } else { // grouped_string
            $groupedDbColumns = [];
            $directDbColumns = [];

            foreach($definition['fields'] as $dbColumn => $prop) {
                if (is_array($prop)) {
                    $hasData = false;
                    foreach($prop as $p) {
                        if(isset($data[$p])) {
                            $hasData = true;
                            break;
                        }
                    }
                    if($hasData) $groupedDbColumns[] = $dbColumn;
                } else {
                    if(isset($data[$prop])) {
                        $directDbColumns[$dbColumn] = $data[$prop];
                    }
                }
            }
            $dehydratedData = $directDbColumns;

            if (!empty($groupedDbColumns)) {
                $existing = Db::one("SELECT " . implode(',', $groupedDbColumns) . " FROM `{$table}` WHERE {$where}", $params);
                if ($existing) {
                    foreach($groupedDbColumns as $col) {
                        $separator = static::getSeparator($col);
                        $old_values = explode($separator, isset($existing[$col]) ? $existing[$col] : '');
                        $merged_data_for_group = [];
                        
                        foreach($definition['fields'][$col] as $index => $propName) {
                            $merged_data_for_group[$propName] = isset($old_values[$index]) ? $old_values[$index] : null;
                        }
                        foreach($definition['fields'][$col] as $propName) {
                            if (isset($data[$propName])) {
                                $merged_data_for_group[$propName] = $data[$propName];
                            }
                        }

                        $final_values = [];
                        foreach($definition['fields'][$col] as $propName) {
                            $final_values[] = isset($merged_data_for_group[$propName]) ? $merged_data_for_group[$propName] : '';
                        }
                        $dehydratedData[$col] = implode($separator, $final_values);
                    }
                }
            }
        }

        if (empty($dehydratedData)) {
            return 0;
        }

        return Db::update($table, $dehydratedData, $where, $params);
    }
    
    public static function updateAll($conditions, $data)
    {
        $itemsToUpdate = static::get($conditions);
        if (empty($itemsToUpdate)) {
            return 0;
        }

        $updatedCount = 0;
        Db::begin();
        try {
            foreach ($itemsToUpdate as $item) {
                $updateData = [];
                $itemObject = (object)$item;
                foreach ($data as $key => $value) {
                    if ($value instanceof Closure) {
                        $temp = $value->bindTo($itemObject);
                        $updateData[$key] = $temp();
                    } else {
                        $updateData[$key] = $value;
                    }
                }

                if (static::update($item['id'], $updateData)) {
                    $updatedCount++;
                }
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }

        return $updatedCount;
    }

    public static function deleteOne($id)
    {
        $definition = static::getDefinition();
        $table = $definition['table'];
        $where = "`ID` = :id AND `TYPE` = :_entity_type";
        $params = [
            'id' => $id,
            '_entity_type' => static::$entityName
        ];
        return Db::delete($table, $where, $params);
    }

    public static function getVariants($fields = [])
    {
        if (empty($fields) || !is_array($fields)) {
            return [];
        }

        $definition = static::getDefinition();
        $table = $definition['table'];
        $results = [];

        $typeWhere = "`TYPE` = :_entity_type";
        $baseParams = ['_entity_type' => static::$entityName];

        foreach ($fields as $fieldName) {
            $mapping = static::findFieldMapping($fieldName);
            
            if (!$mapping) {
                $results[$fieldName] = [];
                continue;
            }

            // Handle grouped strings separately by processing in PHP
            if ($mapping['type'] === 'grouped') {
                $col = $mapping['column'];
                $idx = $mapping['index'];
                $sep = static::getSeparator($col);

                $query = "SELECT `{$col}` FROM `{$table}` WHERE {$typeWhere} AND `{$col}` IS NOT NULL AND `{$col}` != ''";
                $allColumnValues = Db::all($query, $baseParams);

                $distinctVariants = [];
                foreach ($allColumnValues as $row) {
                    $groupedValue = $row[$col];
                    $parts = explode($sep, $groupedValue);
                    if (isset($parts[$idx]) && $parts[$idx] !== '' && $parts[$idx] !== null) {
                        $distinctVariants[$parts[$idx]] = true;
                    }
                }
                $finalVariants = array_keys($distinctVariants);
                sort($finalVariants);
                $results[$fieldName] = $finalVariants;
                continue; // Move to next field
            }

            // Handle 'direct' and 'json' types with a DISTINCT SQL query
            $fieldSql = '';
            switch ($mapping['type']) {
                case 'json':
                    $col = $mapping['column'];
                    $fld = $mapping['field'];
                    $fieldSql = "JSON_UNQUOTE(JSON_EXTRACT(`{$col}`, '$.{$fld}'))";
                    break;
                case 'direct':
                default:
                    $col = preg_replace('/[^a-zA-Z0-9_]/', '', $mapping['column']);
                    $fieldSql = "`{$col}`";
                    break;
            }

            if (!$fieldSql) {
                $results[$fieldName] = [];
                continue;
            }

            $query = "SELECT DISTINCT {$fieldSql} as variant 
                      FROM `{$table}` 
                      WHERE {$typeWhere} AND {$fieldSql} IS NOT NULL AND {$fieldSql} != ''
                      ORDER BY variant ASC";
            
            $variants = Db::all($query, $baseParams);
            
            $results[$fieldName] = array_column($variants, 'variant');
        }

        return $results;
    }
}

class Entity
{
    private static $entities = [];

    public static function register($entityName, $definition, $options = [])
    {
        if (!is_string($entityName) || empty($entityName)) {
            throw new InvalidArgumentException('Entity name must be a non-empty string.');
        }

        $className = ucfirst($entityName) . 'Entity';
        if (class_exists($className)) {
            return;
        }

        $is_simple_array = empty(array_filter(array_keys($definition), 'is_string'));

        if ($is_simple_array) {
            $table = isset($options['table']) ? $options['table'] : PREFIX . 'BLOCKS';
            if ($table !== PREFIX . 'BLOCKS') {
                throw new InvalidArgumentException("Simple array definition is only allowed for the 'BLOCKS' table.");
            }

            $fields = array_filter($definition, 'is_string');
            $computed_array = array_filter($definition, 'is_array');
            $computed = !empty($computed_array) ? array_pop($computed_array) : [];
            
            $field_mapping = ['VALUE' => $fields];

            self::$entities[$entityName] = [
                'name' => $entityName,
                'fields' => $field_mapping,
                'computed' => $computed,
                'table' => $table,
                'storage_mode' => 'json'
            ];
        } else {
            $fields = [];
            $computed = [];
            
            $unnamed_definitions = [];
            foreach ($definition as $key => $value) {
                if (is_int($key)) {
                    $unnamed_definitions[$key] = $value;
                }
            }

            if (!empty($unnamed_definitions)) {
                $computed = array_pop($unnamed_definitions);
            }

            $named_definitions = array_filter($definition, 'is_string', ARRAY_FILTER_USE_KEY);
            foreach ($definition as $key => $value) {
                if (is_string($key)) {
                    $named_definitions[$key] = $value;
                }
            }
            $fields = $named_definitions;
            $separators = [];

            foreach ($fields as $dbColumn => &$prop) {
                if (is_array($prop) && !empty($prop)) {
                    $first = reset($prop);
                    if (is_string($first) && !preg_match('/[a-zA-Z]/', $first)) {
                        $separators[$dbColumn] = array_shift($prop);
                    }
                }
            }
            unset($prop);

            self::$entities[$entityName] = [
                'name' => $entityName,
                'fields' => $fields,
                'computed' => $computed,
                'table' => isset($options['table']) ? $options['table'] : PREFIX . 'BLOCKS',
                'storage_mode' => 'grouped_string',
                'separators' => $separators
            ];
        }

        $classCode = "class {$className} extends BaseEntity { protected static \$entityName = '{$entityName}'; }" ;
        eval($classCode);

        self::postInstall($entityName, $options);
    }

    private static function postInstall($entityName, $options) {
        $className = ucfirst($entityName) . 'Entity';

        $hasCatalog = is_array($options['catalog']);
        $hasDetailPage = is_array($options['detail']) || $options['detail'] === true;

        $catalogUrl = $options['catalog']['url'] ? $options['catalog']['url'] : '/' . $entityName;
        $detailUrl = $catalogUrl . '/:id';
        $editUrl = $detailUrl . '/edit';
        $deleteUrl = $detailUrl . '/delete';
        $createUrl = $catalogUrl . '/new';
        $per_page = isset($options['catalog']['per_page']) ? $options['catalog']['per_page'] : 5;

        if ($hasCatalog) {
            Route::page($catalogUrl, function($page = 1, $filter = []) use ($entityName, $className, $options, $hasDetailPage, $detailUrl, $createUrl, $per_page) {
                $t = template('entity', KYUTILS_PATH);

                if (!empty($filter)) {
                    $preparedFilter = ['AND'];


                    foreach ($filter as $key => $value) {
                        if (!array_key_exists($key, $options['filter']) || empty($value)) continue;

                        $settings = $options['filter'][$key];
                        $strict = $settings[0] === '!';
                        $settings = str_replace('!', '', $settings);
                        
                        switch ($settings) {
                            case 'input': 
                                $operator = $strict ? '=' : 'LIKE'; 
                                if (!$strict) $value = '%' . $value . '%'; break;
                            case 'checkbox': {
                                $op = $strict ? 'AND' : 'OR';
                                $suboperator = [$op];

                                foreach ($value as $v) {
                                    $suboperator[] = [$key, '=', $v];
                                }
                                $preparedFilter[] = $suboperator;
                                $skip = true;
                                break;
                            }
                            case 'radio':
                            case 'select':
                                $operator = '=';
                            default:
                                $operator = $strict ? '=' : 'LIKE';
                        }

                        if ($skip) continue;

                        $preparedFilter[] = [$key, $operator, $value];
                    }

                    $result = $className::get($preparedFilter,
                    [
                        'page' => $page,
                        'per_page' => $per_page,
                        'pagination' => true
                    ]);
                } else {
                    $result = $className::all([
                        'page' => $page,
                        'per_page' => $per_page,
                        'pagination' => true
                    ]);
                }
                

                if ($options['filter']) {
                    $variants = $className::getVariants(array_keys($options['filter']));
                }

                $items = $result['items'];
                $pagination = $result['pagination'];

                $title = $options['catalog']['title'] ? $options['catalog']['title'] : $entityName;
                $fields = $options['fields'] ? $options['fields'] : [];

                foreach ($items as $i => &$item) {
                    $item['detailUrl'] = str_replace(':id', $item['id'], $detailUrl);
                    $item['editUrl'] = $item['detailUrl'] . '/edit';
                    $item['deleteUrl'] = $item['detailUrl'] . '/delete';
                }

                $isAdmin = User::isAdmin();

                return view($t['catalog'], [
                    'entityName' => $entityName,
                    'items' => $items,
                    'title' => $title,
                    'fields' => $fields,
                    'options' => [
                        'name' => $entityName,
                        'hasDetail' => $hasDetailPage,
                        'isAdmin' => $isAdmin,
                        'createUrl' => $createUrl
                    ],
                    'pagination' => $pagination,
                    'filter' => [
                        'labels' => $fields,
                        'fields' => $options['filter'],
                        'variants' => $variants
                    ],
                    'sort' => [
                        'fields' => $options['sort']
                    ]
                ]);
            });

            Route::page($editUrl, function($id) use ($entityName, $options, $className) {
                $t = template('entity', KYUTILS_PATH);
                $item = $className::getOne($id);
                $fields = $options['fields'] ? $options['fields'] : [];
                return view($t['edit'], [
                    'entityName' => $entityName,
                    'item' => $item,
                    'fields' => $fields,
                    'options' => [
                        'name' => $entityName,
                        'hasDetail' => $hasDetailPage,
                        'isAdmin' => $isAdmin
                    ]
                ]);
            });

            Route::page($deleteUrl, function($id) use ($entityName, $className, $catalogUrl) {
                $className::deleteOne($id);
                Route::redirect($catalogUrl);
            });
        }

        if ($hasDetailPage) {
            Route::page($detailUrl, function($id) use ($entityName, $options, $className) {
                $t = template('entity', KYUTILS_PATH);
                $item = $className::getOne($id);
                $fields = $options['fields'] ? $options['fields'] : [];
                return view($t['detail'], [
                    'entityName' => $entityName,
                    'item' => $item,
                    'fields' => $fields
                ]);
            });
        }

        Action::handle("edit-{$entityName}", function($id, $field) use ($className, $catalogUrl) {
            $className::update($id, $field);
            Route::redirect($catalogUrl);
        });

        Action::handle("create-{$entityName}", function($id, $field) use ($className, $catalogUrl) {
            $className::create($field);
            Route::redirect($catalogUrl);
        });

        Route::page($createUrl, function() use ($entityName, $options, $className) {
            $t = template('entity', KYUTILS_PATH);
            $fields = $options['fields'] ? $options['fields'] : [];
            return view($t['create'], [
                'entityName' => $entityName,
                'fields' => $fields,
                'options' => [
                    'name' => $entityName,
                    'hasDetail' => $hasDetailPage,
                    'isAdmin' => $isAdmin
                ]
            ]);
        });
    }

    public static function getDefinition($entityName)
    {
        return isset(self::$entities[$entityName]) ? self::$entities[$entityName] : null;
    }
}