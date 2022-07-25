<?php

class MigrationParser
{

    const TS_UPDATE_STRING = 'ON UPDATE CURRENT_TIMESTAMP';

    /**
     * @var string
     */
    protected $version = '2.0.1';

    /**
     * @var array
     */
    protected $structure = [];

    /**
     * @var array
     */
    protected $keys = [];

    /**
     * @var array
     */
    protected $constraints = [];

    /**
     * @var string
     */
    protected $tableCharset;

    /**
     * @var string
     */
    protected $tableCollation;

    /**
     * @var array
     */
    protected $extras = [];

    /**
     * @var array
     */
    protected $integerMaps = [
        'int'       => 'integer',
        'bigint'    => 'bigInteger',
        'mediumint' => 'mediumInteger',
        'smallint'  => 'smallInteger',
        'tinyint'   => 'tinyInteger',
    ];

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var string
     */
    protected $structureFile;

    /**
     * @var string
     */
    protected $keysFile;

    /**
     * @var string
     */
    protected $constraintsFile;

    /**
     * @var string
     */
    protected $tableCharsetAndCollationFile;

    /**
     * @var string
     */
    protected $foreignStructureFile;

    /**
     * @var string
     */
    protected $hasForeign;

    /**
     * MigrationParser constructor.
     *
     * @param string $tableName
     * @param string $structureFile
     * @param string $keysFile
     * @param string $constraintsFile
     * @param string $tableCharsetAndCollationFile
     * @param string $foreignStructureFile
     * @param string $hasForeign
     */
    public function __construct($tableName, $structureFile, $keysFile, $constraintsFile, $tableCharsetAndCollationFile, $foreignStructureFile, $hasForeign)
    {
        $this->tableName = $tableName;
        $this->structureFile = $structureFile;
        $this->keysFile = $keysFile;
        $this->constraintsFile = $constraintsFile;
        $this->tableCharsetAndCollationFile = $tableCharsetAndCollationFile;
        $this->foreignStructureFile = $foreignStructureFile;
        $this->hasForeign = $hasForeign;
    }

    public function makeMigration()
    {
        $this->buildTableCollationAndCharset();
        $this->buildStructure();
        $this->buildKeys();
        $this->buildConstraints();

        $indent8 = str_repeat(' ', 8);
        $indent12 = str_repeat(' ', 12);
        $eol = "\n";

        $structure = trim(implode($eol . $indent12, $this->formatStructure())) . $eol;
        $keys = trim(implode($eol . $indent12, $this->formatKeys())) . $eol;
        $constraints = trim(implode($eol . $indent12, $this->formatConstraints())) . $eol;
        $tableCollationAndCharset = trim(implode($eol . $indent12, $this->formatTableCollationAndCharset())) . $eol;
        $extras = trim(implode($eol . $indent8, $this->formatExtras())) . $eol;

        if ($this->hasForeign === "true") {
            $foreign = trim(implode($eol . $indent12, $this->formatForeign())) . $eol;
            $foreignDrop = trim(implode($eol . $indent12, $this->formatForeignDrop())) . $eol;
            $className = 'AddForeignKeyTo' . $this->studly($this->tableName) . 'Table';
            $output = file_get_contents(__DIR__ . '/foreign_key.stub');
            $output = str_replace(
                [
                    ':VERSION:',
                    'DummyClass',
                    'DummyTable',
                    "// foreign\n",
                    "// foreignDrop\n",
                ],
                [
                    $this->version,
                    $className,
                    $this->tableName,
                    $foreign,
                    $foreignDrop,
                ],
                $output
            );
        } else {
            $className = 'Create' . $this->studly($this->tableName) . 'Table';
            $output = file_get_contents(__DIR__ . '/create.stub');
            $output = str_replace(
                [
                    ':VERSION:',
                    'DummyClass',
                    'DummyTable',
                    "// structure\n",
                    "// keys\n",
                    "// constraints\n",
                    "// tableCollationAndCharset\n",
                    "// extras\n",
                ],
                [
                    $this->version,
                    $className,
                    $this->tableName,
                    $structure,
                    $keys,
                    $constraints,
                    $tableCollationAndCharset,
                    $extras,
                ],
                $output
            );
        }


        $output = preg_replace("/^(\s*\R){2,}/m", "\n", $output);

        return $output;
    }

    public function formatForeign()
    {
        $fields = [];

        $rows = file($this->foreignStructureFile);
        array_shift($rows);

        foreach ($rows as $row) {
            list($table, $colName, $constName, $refTable, $refColumnName) = explode("\t",
                $row, 5);
            $fields[] = '$table->foreign(\''. trim($colName) .'\')->references(\''. trim($refColumnName) .'\')->on(\''. trim($refTable) .'\');';
        }
        return array_filter($fields);
    }

    public function formatForeignDrop()
    {
        $fields = [];

        $rows = file($this->foreignStructureFile);
        array_shift($rows);

        foreach ($rows as $row) {
            list($table, $colName, $constName, $refTable, $refColumnName) = explode("\t",
                $row, 5);
            $fields[] = '$table->dropForeign(\''. trim($table) .'_'. trim($colName) .'_foreign\');';
        }
        return array_filter($fields);
    }

    protected function studly($value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    public function buildStructure()
    {
        $this->structure = [];

        $rows = file($this->structureFile);
        array_shift($rows);

        foreach ($rows as $row) {

            list($field, $colType, $null, $key, $default, $characterSet, $collation, $extra, $comment) = explode("\t",
                $row);

            if (preg_match('#^(\w+)(\((.*?)\))?(.*?)?$#', $colType, $matches)) {

                $type = strtolower($matches[1]);
                $args = $matches[3] ?: null;
                $typeExtra = trim($matches[4]) ?: null;

                if (strpos($args, ',') === false) {
                    $args = $args ?: null;
                } else {
                    $args = explode(',', $args);
                }

                $data = [
                    'field'        => $field,
                    'nullable'     => ($null === 'YES'),
                    'default'      => ($default !== 'NULL') ? $default : null,
                    'characterSet' => ($characterSet !== 'NULL' && $characterSet !== $this->tableCharset) ? $characterSet : null,
                    'collation'    => ($collation !== 'NULL' && $collation !== $this->tableCollation) ? $collation : null,
                    '_colType'     => $colType,
                ];

                $method = 'parse' . ucfirst($type);

                if (method_exists($this, $method)) {
                    $data = array_merge(
                        $data,
                        $this->{$method}($type, $args, $typeExtra, $extra)
                    );
                } else {
                    $data['method'] = 'UNKNOWN:' . $type;
                }

                $data['comment'] = trim(str_replace(["\r", "\n"], '', $comment));
                if ($data['comment']==='') {
                    $data['comment'] = null;
                }

                $this->structure[$field] = $data;
            }
        }

        // look for softDeletes
        if (
            array_key_exists('deleted_at', $this->structure)
            && $this->structure['deleted_at']['method'] === 'timestamp'
        ) {
            $this->structure['deleted_at']['method'] = 'softDeletes';
            $this->structure['deleted_at']['args'] = null;
            $this->structure['deleted_at']['default'] = null;
            $this->structure['deleted_at']['nullable'] = false;
            $this->structure['deleted_at']['field'] = null;
        }

        // look for timestamps
        if (
            array_key_exists('created_at', $this->structure)
            && $this->structure['created_at']['method'] === 'timestamp'
            && array_key_exists('updated_at', $this->structure)
            && $this->structure['updated_at']['method'] === 'timestamp'
        ) {
            unset($this->structure['updated_at']);
            $method = $this->structure['created_at']['nullable'] ? 'nullableTimestamps' : 'timestamps';
            $this->structure['created_at']['method'] = $method;
            $this->structure['created_at']['args'] = null;
            $this->structure['created_at']['default'] = null;
            $this->structure['created_at']['nullable'] = false;
            $this->structure['created_at']['field'] = null;
        }

        // look for rememberToken
        if (
            array_key_exists('remember_token', $this->structure)
            && $this->structure['remember_token']['method'] === 'string'
            && $this->structure['remember_token']['nullable'] === true
            && $this->structure['remember_token']['args'] === '100'
        ) {
            $this->structure['remember_token']['method'] = 'rememberToken';
            $this->structure['remember_token']['args'] = null;
            $this->structure['remember_token']['default'] = null;
            $this->structure['remember_token']['nullable'] = false;
            $this->structure['remember_token']['field'] = null;
        }

        // look for id
        foreach ($this->structure as $field=>$struct) {
            if (
                $struct['method'] === 'bigInteger'
                && $struct['autoIncrement'] === true
                && $struct['args'] === null
            ) {
                $this->structure[$field]['method'] = 'id';
                $this->structure[$field]['args'] = null;
                $this->structure[$field]['default'] = null;
                $this->structure[$field]['nullable'] = false;
                $this->structure[$field]['autoIncrement'] = null;
                $this->structure[$field]['unsigned'] = null;
                if ($field==='id') {
                    $this->structure[$field]['field'] = null;
                }
            }
        }
    }

    public function formatStructure()
    {
        $fields = [];
        foreach ($this->structure as $field => $data) {

            $method = $data['method'];
            $isNumeric = $this->isNumeric($method);
            $isInteger = $this->isInteger($method);

            if ($isInteger) {
                if ($data['autoIncrement']) {
                    $method = str_replace('nteger', 'ncrements', $method);
                } elseif ($data['unsigned']) {
                    $method = 'unsigned' . ucfirst($method);
                }
            }

            if ($method === 'timestamp' && $data['args'] === self::TS_UPDATE_STRING) {
                $data['default'] .= ' ' . $data['args'];
                $data['args'] = null;
            }

            $temp = '$table->' . $method;
            if ($data['field']) {
                $temp .= '(\'' . $field . '\'';
                if ($method === 'enum' || $method === 'set') {
                    $temp .= ', [' . implode(', ', (array) $data['args']) . '])';
                } elseif ($data['args']) {
                    $temp .= ', ' . implode(', ', (array) $data['args']) . ')';
                } else {
                    $temp .= ')';
                }
            } else {
                $temp .= '()';
            }
            if (!$isInteger) {
                if ($data['autoIncrement']) {
                    $temp .= '->autoIncrement()';
                }
                if ($data['unsigned']) {
                    $temp .= '->unsigned()';
                }
            }
            if ($data['nullable']) {
                $temp .= '->nullable()';
            }
            if ($data['characterSet']) {
                $temp .= "->charset('" . $data['characterSet'] . "')";
            }
            if ($data['collation']) {
                $temp .= "->collation('" . $data['collation'] . "')";
            }
            if (isset($data['default'])) {
                if ($isNumeric || (($method === 'enum' || $method === 'set') && is_numeric($data['default']))) {
                    $temp .= '->default(' . $data['default'] . ')';
                } elseif ($method === 'boolean') {
                    $temp .= '->default(' . ($data['default'] ? 'true' : 'false') . ')';
                } elseif (stripos(trim($data['default']), 'CURRENT_TIMESTAMP') !== false) {
                    $temp .= '->default(\DB::raw(\'' . trim($data['default']) . '\'))';
                } else {
                    $temp .= '->default(\'' . $this->trimStringQuotes($data['default']) . '\')';
                }
            }

            // If isn't empty, set the comment
            if ($data['comment'] !== null) {
                $temp .= '->comment(\'' . addslashes($data['comment']) . '\')';
            }

            $fields[$field] = $temp . ';';
        }

        return array_filter($fields);
    }

    public function buildKeys()
    {
        $this->keys = [];

        $rows = file($this->keysFile);
        array_shift($rows);

        foreach ($rows as $row) {
            list($table, $nonUnique, $keyName, $seq, $colName, $collation, $cardinality, $subPart, $packed, $null, $indexType, $extra) = explode("\t",
                $row, 12);

            if ($indexType === 'FULLTEXT') {
                if (!array_key_exists($keyName, $this->extras)) {
                    $this->extras[$keyName] = [
                        'method'  => 'fulltext',
                        'table'   => $table,
                        'columns' => [],
                    ];
                    $this->extras[$keyName]['columns'][$seq] = $colName;
                }
            } else {
                if (!array_key_exists($keyName, $this->keys)) {
                    $this->keys[$keyName] = [
                        'method'  => $nonUnique ? 'index' : 'unique',
                        'table'   => $table,
                        'columns' => [],
                    ];
                }
                $this->keys[$keyName]['columns'][$seq] = $colName;
            }
        }

        // if we have a primary key ...
        if (array_key_exists('PRIMARY', $this->keys)) {
            $primary = $this->keys['PRIMARY'];
            // and it's for one columns ...
            if (count($primary['columns']) === 1) {
                $primaryColumn = reset($primary['columns']);
                $field = $this->structure[$primaryColumn];
                // and that column is an "increments" field ...
                if (isset($field['args']['autoIncrement']) && $field['args']['autoIncrement'] === 'true') {
                    // then don't build the primary key, since Laravel takes care of it
                    unset($this->keys['PRIMARY']);
                }
            }
        }
    }

    public function formatKeys()
    {
        $fields = [];

        foreach ($this->keys as $field => $data) {
            $columns = $this->escapeArray($data['columns']);

            if ($field === 'PRIMARY') {
                //$fields[$field] = sprintf('$table->primary(%s);', $columns);
            } else {
                $fields[$field] = sprintf('$table->%s(%s, \'%s\');',
                    $data['method'],
                    $columns,
                    $field
                );
            }
        }

        return array_filter($fields);
    }

    public function formatExtras()
    {
        $fields = [];

        foreach ($this->extras as $field => $data) {
            if ($data['method'] === 'fulltext') {
                $columns = $this->escapeColumnList($data['columns']);
                $fields[$field] = sprintf('\\DB::statement("ALTER TABLE `%s` ADD FULLTEXT INDEX `%s` (%s)");',
                    $data['table'],
                    $field,
                    $columns
                );
            }
        }

        return array_filter($fields);
    }

    public function buildConstraints()
    {
        $this->constraints = [];

        $rows = file($this->constraintsFile);
        array_shift($rows);

        foreach ($rows as $row) {
            $row = preg_replace('/\n+$/', '', $row);
            list($constraint, $colName, $refTable, $refColumn, $updateRule, $deleteRule) = explode("\t", $row);

            if (array_key_exists($constraint, $this->keys)) {
                unset($this->keys[$constraint]);
            }

            $this->constraints[$constraint][] = compact('colName', 'refTable', 'refColumn', 'updateRule', 'deleteRule');
        }
    }

    public function formatConstraints()
    {
        $fields = [];
        foreach ($this->constraints as $field => $data) {
            $colNames = $this->escapeArray(array_map(function($entry) { return $entry['colName']; }, $data));
            $refColumns = $this->escapeArray(array_map(function($entry) { return $entry['refColumn']; }, $data));
            $temp = '$table->foreign(' . $colNames . ', \'' . $field . '\')' .
                '->references(' . $refColumns . ')' .
                '->on(\'' . $data[0]['refTable'] . '\')' .
                '->onDelete(\'' . $data[0]['deleteRule'] . '\')' .
                '->onUpdate(\'' . $data[0]['updateRule'] . '\')';

            $fields[$field] = $temp . ';';
        }

        return array_filter($fields);
    }

    public function buildTableCollationAndCharset()
    {
        $this->constraints = [];

        $rows = file($this->tableCharsetAndCollationFile);
        array_shift($rows);

        if (!empty($rows)) {
            $row = array_shift($rows);
            $row = preg_replace('/\n+$/', '', $row);
            list($this->tableCharset, $this->tableCollation) = explode("\t", $row);
        }
    }

    public function formatTableCollationAndCharset()
    {
        $output = [];

        if ($this->tableCharset) {
            $output[] = '$table->charset = \'' . $this->tableCharset . "';";
        }

        if ($this->tableCollation) {
            $output[] = '$table->collation = \'' . $this->tableCollation . "';";
        }

        return $output;
    }

    protected function copyToClipboard($content)
    {
        $cmd = 'echo ' . escapeshellarg($content) . ' | __CF_USER_TEXT_ENCODING=' . posix_getuid() . ':0x8000100:0x8000100 pbcopy';
        shell_exec($cmd);
    }

    protected function escapeArray($array)
    {
        $array = (array) $array;
        array_walk($array, function(&$value, $idx) {
            if (!is_numeric($value)) {
                $value = '\'' . str_replace('\'', '\\\'', $value) . '\'';
            }
        });

        $string = implode(', ', $array);

        if (count($array) > 1) {
            return '[' . $string . ']';
        }

        return $string;
    }

    protected function escapeColumnList($array)
    {
        $array = (array) $array;
        array_walk($array, function(&$value, $idx) {
            $value = '`' . $value . '`';
        });

        return implode(', ', $array);
    }

    // protected function getAutoIncrementArgument($extra)
    // {
    //     $arguments = [
    //         'autoIncrement' => false,
    //     ];
    //
    //     if (strpos($extra, 'auto_increment') !== false) {
    //         $arguments['autoIncrement'] = true;
    //     }
    //
    //     return $arguments;
    // }
    //

    protected function isAutoIncrement($extra)
    {
        return strpos($extra, 'auto_increment') !== false;
    }

    // protected function getUnsignedArgument($typeExtra)
    // {
    //     $arguments = [
    //         'unsigned' => false
    //     ];
    //
    //     if (strpos($typeExtra, 'unsigned') !== false) {
    //         $arguments['unsigned'] = true;
    //     }
    //
    //     return $arguments;
    // }

    protected function isUnsigned($typeExtra)
    {
        return strpos($typeExtra, 'unsigned') !== false;
    }

    protected function parseInt($type, $args, $typeExtra, $extra)
    {
        return [
            'method'        => $this->integerMaps[$type],
            'args'          => null,
            'autoIncrement' => $this->isAutoIncrement($extra),
            'unsigned'      => $this->isUnsigned($typeExtra),
        ];
    }

    protected function parseBigint($type, $args, $typeExtra, $extra)
    {
        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseMediumint($type, $args, $typeExtra, $extra)
    {
        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseSmallint($type, $args, $typeExtra, $extra)
    {
        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseTinyint($type, $args, $typeExtra, $extra)
    {
        if ($args === 1) {
            $method = 'boolean';
            $args = null;
            $unsigned = false;

            return compact('method', 'args', 'unsigned');
        }

        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseBlob($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('binary', $args);
    }

    protected function parseChar($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('char', $args);
    }

    protected function parseDate($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('date');
    }

    protected function parseDatetime($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('dateTime');
    }

    protected function parseDecimal($type, $args, $typeExtra, $extra)
    {
        return [
            'method'   => 'decimal',
            'args'     => $args,
            'unsigned' => $this->isUnsigned($typeExtra),
        ];
    }

    protected function parseNumeric($type, $args, $typeExtra, $extra)
    {
        return $this->parseDecimal($type, $args, $typeExtra, $extra);
    }

    protected function parseFixed($type, $args, $typeExtra, $extra)
    {
        return $this->parseDecimal($type, $args, $typeExtra, $extra);
    }

    protected function parseDouble($type, $args, $typeExtra, $extra)
    {
        return [
            'method'   => 'double',
            'args'     => $args,
            'unsigned' => $this->isUnsigned($typeExtra),
        ];
    }

    protected function parseDoublePrecision($type, $args, $typeExtra, $extra)
    {
        return $this->parseDouble($type, $args, $typeExtra, $extra);
    }

    protected function parseReal($type, $args, $typeExtra, $extra)
    {
        return $this->parseDouble($type, $args, $typeExtra, $extra);
    }

    protected function parseFloat($type, $args, $typeExtra, $extra)
    {
        return [
            'method'   => 'float',
            'args'     => $args,
            'unsigned' => $this->isUnsigned($typeExtra),
        ];
    }

    protected function parseLongtext($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('longText', $args);
    }

    protected function parseMediumtext($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('mediumText', $args);
    }

    protected function parseTinytext($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('tinyText', $args);
    }

    protected function parseText($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('text', $args);
    }

    protected function parseVarchar($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('string', $args);
    }

    protected function parseEnum($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('enum', $args);
    }

    protected function parseSet($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('set', $args);
    }

    protected function parseTime($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('time', $args);
    }

    protected function parseTimestamp($type, $args, $typeExtra, $extra)
    {
        if (stripos($extra, self::TS_UPDATE_STRING) !== false) {
            $args = self::TS_UPDATE_STRING;
        }
        return $this->defaultParse('timestamp', $args);
    }

    protected function parseJson($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('json', $args);
    }

    private function defaultParse($method, $args = null)
    {
        return compact('method', 'args');
    }

    private function trimStringQuotes($string)
    {
        return trim(
            trim($string, '"\'')
        );
    }

    private function isInteger($method)
    {
        return stripos($method, 'integer') !== false;
    }

    private function isNumeric($method)
    {
        return $this->isInteger($method)
            || $method === 'decimal'
            || $method === 'double'
            || $method === 'float'
            || $method === 'real';
    }
}
