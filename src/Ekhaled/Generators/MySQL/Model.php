<?php

namespace Ekhaled\Generators\MySQL;

use \RuntimeException;
use \PDOException;

class Model
{

    private $_template;

    protected $config = array();

    public function __construct($config = array())
    {
        $defaults = array(
            'output'                => 'path/to/output/folder',
            'DB'                    => array(),
            'namespace'             => 'Models\\Base',
            'extends'               => '\\Models\\Base',
            'relationNamespace'     => '\\Models\Base\\',
            'template'              => '',
            'indentation'           => array(),
            'exclude_views'         => false,
            'exclude_connectors'    => false,
            'exclude'               => array()
        );

        foreach ($config as $key => $value) {
            //overwrite the default value of config item if it exists
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = $value;
            }
        }

        //store the config back into the class property
        $this->config = $defaults;

        clearstatcache();
        try {
            $this->checkConditions();
        } catch (RuntimeException $ex) {
            $message = $ex->getMessage();
            $this->output($message, true);
            exit;
        }
    }

    public function generate()
    {
        try {
            $schema = $this->getSchema();
        } catch (PDOException $ex) {
            $message = $ex->getMessage();
            $this->output("Database connection failed with the message
>> \"" . $message . "\"
Please ensure database connection settings are correct.", true);
            exit;
        }

        $config = $this->config;

        foreach ($schema as $table) {
            if (!in_array($table['name'], $config['exclude'])) {
                if ($config['exclude_views'] && $table['type'] == 'VIEW') {
                    continue;
                }
                if ($config['exclude_connectors'] && $table['is_connector_table']) {
                    continue;
                }
                $className = $this->className($table['name']);
                $h = fopen($config['output'] . $className . '.php', 'w');
                if (fwrite($h, $this->generateModel(
                    $table,
                    $config['namespace'],
                    $config['extends'],
                    $config['relationNamespace']
                ))) {
                    $this->output("Generated " . $className . " model");
                } else {
                    $this->output('Failed to generate ' . $className . ' model', true);
                }

                fclose($h);
                usleep(250000);
            }
        }
    }

    protected function checkConditions()
    {
        $config = $this->config;

        if (!class_exists('\Ekhaled\MysqlSchema\Parser')) {
            throw new RuntimeException('This generator depends on ekhaled/schema-parser-mysql, please ensure that package is loaded');
        }

        if (empty($config['output']) || !(file_exists($config['output']) && is_dir($config['output']) && is_writable($config['output']))) {
            throw new RuntimeException('Please ensure that the output folder exists and is writable.');
        }

        if (!empty($this->config['template'])) {
            if (!(file_exists($this->config['template']) && is_file($this->config['template']))) {
                throw new RuntimeException("The specified template file does not exist.\nPlease leave the `template` option empty if you would like to use the built-in template.");
            }
        }
    }

    protected function generateModel($schema, $namespace = null, $extends = null, $relationNamespace = '', $classname = null)
    {
        $modelTemplate = $this->getTemplate();

        $tablename = strtolower($schema['name']);

        $data = [
            '{{NAMESPACE}}' => '',
            '{{CLASSNAME}}' => '',
            '{{EXTENDS}}' => '',
            '{{TABLENAME}}' => $tablename,
        ];

        if ($namespace) {
            $data['{{NAMESPACE}}'] = 'namespace ' . $namespace . ';';
        }

        if ($extends) {
            $data['{{EXTENDS}}'] = 'extends ' . $extends;
        }

        if (!$classname) {
            $classname = $this->className($tablename);
        }

        $data['{{CLASSNAME}}'] = $classname;

        $fieldConf = [];
        foreach ($schema['columns'] as $column) {
            if (!($column['isPrimaryKey'] && $column['autoIncrement'])) {
                $fieldConf[] = $this->field($column, $relationNamespace);
            }
        }

        foreach ($schema['relations'] as $rel) {
            $fieldConf[] = $this->virtualfield($rel, $relationNamespace);
        }

        $modelTemplate = str_replace(array_keys($data), array_values($data), $modelTemplate);
        $modelTemplate = str_replace('{{FIELDCONF}}', implode(",\n", $fieldConf), $modelTemplate);

        return $modelTemplate;
    }

    protected function getTemplate()
    {
        if (empty($this->_template)) {
            if (!empty($this->config['template'])) {
                $this->_template = file_get_contents($this->config['template']);
            } else {
                $this->_template = <<<PHP
<?php
{{NAMESPACE}}

class {{CLASSNAME}} {{EXTENDS}}
{

    protected \$fieldConf = [
{{FIELDCONF}}
    ],
    \$table = '{{TABLENAME}}';

}
PHP;
            }
        }

        return $this->_template;
    }

    protected function getSchema()
    {
        $schemaParser = new \Ekhaled\MysqlSchema\Parser($this->config['DB']);
        return $schemaParser->getSchema();
    }

    protected function className($t, $ns = '')
    {
        return $ns . ucfirst(strtolower($t));
    }

    protected function field(array $field, $relationNamespace = '')
    {
        $indentConfig = $this->getIndentationConfig();

        $template = $indentConfig['field_name_indent'] . '\'' . $field['name'] . '\' => [
{{VALUES}}
' . $indentConfig['field_name_indent'] . ']';

        $values = [];

        if (isset($field['relation']) && count($field['relation']) > 0) {
            $values[] = '\'' . $field['relation']['type'] . '\' => \'' . $this->className($field['relation']['table'], $relationNamespace) . '\'';
        } else {

            $values[] = '\'type\' => \'' . $this->extractType($field['type']) . '\'';

            if (trim($field['default']) !== '') {
                $values[] = '\'default\' => \'' . $field['default'] . '\'';
            }

            $values[] = '\'nullable\' => ' . ($field['nullable'] ? 'true' : 'false');
        }

        $template = str_replace('{{VALUES}}', implode(",\n", array_map(function ($val) use ($indentConfig) {
            return $indentConfig['values_indent'] . $val;
        }, $values)), $template);

        return $template;
    }

    protected function virtualfield(array $relation, $relationNamespace = '')
    {
        $indentConfig = $this->getIndentationConfig();
        if (isset($relation['via'])) {
            return $indentConfig['field_name_indent'] . '\'' . $relation['selfColumn'] . '\' => [
' . $indentConfig['values_indent'] . '\'' . $relation['type'] . '\' => [\'' . $this->className($relation['table'], $relationNamespace) . '\', \'' . $relation['column'] . '\', \'' . $relation['via'] . '\']
' . $indentConfig['field_name_indent'] . ']';
        } else {
            return $indentConfig['field_name_indent'] . '\'' . (isset($relation['key']) ? $relation['key'] : $relation['table']) . '\' => [
' . $indentConfig['values_indent'] . '\'' . $relation['type'] . '\' => [\'' . $this->className($relation['table'], $relationNamespace) . '\', \'' . $relation['column'] . '\']
' . $indentConfig['field_name_indent'] . ']';
        }
    }

    protected function extractType($dbType)
    {

        $ints = [
            1 => 1,
            2 => 1,
            3 => 1,
            4 => 1,
            5 => 2,
            6 => 2,
            7 => 2,
            8 => 4,
            9 => 4,
            10 => 4,
            11 => 4,
            20 => 8
        ];
        $varchars = [128, 256, 512];

        $size = null;
        if (strpos($dbType, '(') && preg_match('/\((.*)\)/', $dbType, $matches)) {
            $values = explode(',', $matches[1]);
            $size = $values[0];
        }

        if (stripos($dbType, 'tinyint') !== false)
            $type = 'INT1';
        elseif (stripos($dbType, 'int') !== false && stripos($dbType, 'unsigned int') === false)
            $type = 'INT';
        elseif (stripos($dbType, 'longtext') !== false)
            $type = 'LONGTEXT';
        elseif (stripos($dbType, 'text') !== false)
            $type = 'TEXT';
        elseif (stripos($dbType, 'bool') !== false)
            $type = 'BOOLEAN';
        elseif (stripos($dbType, 'datetime') !== false)
            $type = 'DATETIME';
        elseif (stripos($dbType, 'date') !== false)
            $type = 'DATE';
        elseif (stripos($dbType, 'timestamp') !== false)
            $type = 'TIMESTAMP';
        elseif (preg_match('/(real|floa|doub)/i', $dbType))
            $type = 'DOUBLE';
        else
            $type = 'VARCHAR';

        if ($type == 'VARCHAR' && in_array($size, $varchars)) {
            return $type . $size;
        } elseif ($type == 'VARCHAR') {
            return 'VARCHAR128';
        }

        if ($type == 'INT') {
            if (in_array($size, array_keys($ints))) {
                return $type . $ints[$size];
            } else {
                return $type . '8';
            }
        }

        return $type;
    }

    protected function getIndentationConfig()
    {
        $indentation = $this->config['indentation'];
        $start_level = isset($indentation['start_level']) ? $indentation['start_level'] : 3;
        $unit = isset($indentation['unit']) ? $indentation['unit'] : '  ';


        $fieldNameIndent = str_repeat($unit, $start_level);
        $valuesIndent = $fieldNameIndent . $unit;

        return [
            'field_name_indent' => $fieldNameIndent,
            'values_indent' => $valuesIndent
        ];
    }

    protected function output($msg, $err = false)
    {
        if ($err) {
            echo "\033[1;97;41m" . $msg . "\e[0m" . "\n";
        } else {
            echo "\033[1;97;42m" . $msg . "\e[0m" . "\n";
        }
    }
}
