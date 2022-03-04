<?php
namespace Ekhaled\Generators\MySQL;

use \RuntimeException;
use \PDOException;

class Model{

    private $_template;
    private $_fieldconf_template;

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
            'fieldconf_template'    => '',
            'exclude_views'         => false,
            'exclude_connectors'    => true,
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
        try{
            $this->checkConditions();
        }catch(RuntimeException $ex){
            $message = $ex->getMessage();
            $this->output($message, true);
            exit;
        }

    }

    public function generate()
    {
        try{
            $schema = $this->getSchema();
        }catch(PDOException $ex){
            $message = $ex->getMessage();
            $this->output("Database connection failed with the message
>> \"" . $message . "\"
Please ensure database connection settings are correct.", true);
            exit;
        }

        $config = $this->config;

        foreach($schema as $table){
            if(!in_array($table['name'], $config['exclude'])){
                if($config['exclude_views'] && $table['type'] == 'VIEW'){
                    continue;
                }
                if($config['exclude_connectors'] && $table['is_connector_table']){
                    continue;
                }
                $className = $this->className($table['name']);
                $h = fopen($config['output'].$className.'.php' , 'w');
                if(fwrite($h, $this->generateModel(
                    $table,
                    $config['namespace'],
                    $config['extends'],
                    $config['relationNamespace']
                ))){
                    $this->output("Generated " .$className." model");
                }else{
                    $this->output('Failed to generate '.$className.' model', true);
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

    protected function generateModel($schema, $namespace = null, $extends = null, $relationNamespace = '',$classname = null)
    {
        $modelTemplate = $this->getTemplate();

        $tablename = strtolower($schema['name']);

        $data = [
            '{{NAMESPACE}}' => '',
            '{{CLASSNAME}}' => '',
            '{{EXTENDS}}' => '',
            '{{TABLENAME}}' => $tablename,
        ];

        if($namespace){
            $data['{{NAMESPACE}}'] = 'namespace '.$namespace.';';
        }

        if($extends){
            $data['{{EXTENDS}}'] = 'extends '.$extends;
        }

        if(!$classname){
            $classname = $this->className($tablename);
        }

        $data['{{CLASSNAME}}'] = $classname;

        $fieldConf = [];
        foreach($schema['columns'] as $column){
            if(!($column['isPrimaryKey'] && $column['autoIncrement'])){
                $fieldConf[] = $this->field($column, $relationNamespace);
            }
        }

        foreach($schema['relations'] as $rel){
            $fieldConf[] = $this->virtualfield($rel, $relationNamespace);
        }

        $modelTemplate = str_replace(array_keys($data), array_values($data), $modelTemplate);
        $modelTemplate = str_replace('{{FIELDCONF}}', implode("\n", $fieldConf), $modelTemplate);

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

    protected function getFieldConfTemplate()
    {
        if (empty($this->_fieldconf_template)) {
            if (!empty($this->config['fieldconf_template'])) {
                $this->_fieldconf_template = file_get_contents($this->config['fieldconf_template']);
            } else {
                $this->_fieldconf_template = <<<PHP
{{FIELDNAME}} => [
  {{KEY}} => {{VALUE}}
],
PHP;
            }
        }

        return $this->_fieldconf_template;
    }

    protected function getSchema()
    {
        $schemaParser = new \Ekhaled\MysqlSchema\Parser($this->config['DB']);
        return $schemaParser->getSchema();
    }

    protected function className($t, $ns = ''){
        return $ns.ucfirst(strtolower($t));
    }

    protected function field(array $field, $relationNamespace = ''){
        // place holders
        $FIELDNAME = "/^\s*\{\{FIELDNAME\}\}/m";
        $KEY = "/^\s*\{\{KEY\}\}/m";
        $VALUE = "/^\s*\{\{VALUE\}\}/m";

        $fieldConfTemplate = $this->getFieldConfTemplate();

        $fieldConfTemplate = preg_replace(
          $FIELDNAME,
          $this->getIndentation('FIELDNAME').'\''.$field['name'].'\'',
          $fieldConfTemplate
        );

        $values = [];

        if(isset($field['relation']) && count($field['relation']) > 0){
            $values[] = '\''.$field['relation']['type'].'\' => \''.$this->className($field['relation']['table'], $relationNamespace).'\'';
        }else{

            $values[] = '\'type\' => \''.$this->extractType($field['type']).'\'';

            if (trim($field['default']) !== '') {
                $values[] = '\'default\' => \'' . $field['default'] . '\'';
            }

            $values[] = '\'nullable\' => ' . ($field['nullable'] ? 'true' : 'false');
        }

        $fieldConfTemplate = preg_replace('/^\s*\{\{VALUES\}\}/m', implode(",\n", array_map(function($val){
            return $this->getIndentation('VALUES').$val;
        }, $values)), $fieldConfTemplate);

        return $fieldConfTemplate;
    }

    protected function virtualfield(array $relation, $relationNamespace = ''){
        if(isset($relation['via'])){
            return '        \''.$relation['selfColumn'].'\' => [
                \''.$relation['type'].'\' => [\''.$this->className($relation['table'], $relationNamespace).'\', \''.$relation['column'].'\', \''.$relation['via'].'\']
            ]';
        }else{
            return '        \''.(isset($relation['key']) ? $relation['key'] : $relation['table']).'\' => [
            \''.$relation['type'].'\' => [\''.$this->className($relation['table'], $relationNamespace).'\', \''.$relation['column'].'\']
        ]';
        }

    }

    protected function extractType($dbType){

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
        if(strpos($dbType,'(') && preg_match('/\((.*)\)/',$dbType,$matches)){
            $values = explode(',',$matches[1]);
            $size = $values[0];
        }

        if(stripos($dbType,'tinyint')!==false)
            $type = 'INT1';
        elseif(stripos($dbType,'int')!==false && stripos($dbType,'unsigned int')===false)
            $type = 'INT';
        elseif(stripos($dbType,'longtext')!==false)
            $type = 'LONGTEXT';
        elseif(stripos($dbType,'text')!==false)
            $type = 'TEXT';
        elseif(stripos($dbType,'bool')!==false)
            $type = 'BOOLEAN';
        elseif(stripos($dbType,'datetime')!==false)
            $type = 'DATETIME';
        elseif(stripos($dbType,'date')!==false)
            $type = 'DATE';
        elseif(stripos($dbType,'timestamp')!==false)
            $type = 'TIMESTAMP';
        elseif(preg_match('/(real|floa|doub)/i',$dbType))
            $type = 'DOUBLE';
        else
            $type = 'VARCHAR';

        if($type == 'VARCHAR' && in_array($size, $varchars)){
            return $type . $size;
        }elseif($type == 'VARCHAR'){
            return 'VARCHAR128';
        }

        if($type == 'INT'){
            if(in_array($size, array_keys($ints))){
                return $type . $ints[$size];
            }else{
                return $type . '8';
            }
        }

        return $type;
    }

    protected function getIndentation($placeholder)
    {
      preg_match("/^\s*\{\{$placeholder\}\}/m", $this->_fieldconf_template, $match);
      if(!empty($match)){
        return str_repeat(' ', strlen($match[0]) - strlen(ltrim($match[0])));
      }
      return '';
    }

    protected function output($msg, $err = false){
        if($err){
            echo "\033[1;97;41m" .$msg."\e[0m" . "\n";
        }else{
            echo "\033[1;97;42m" .$msg."\e[0m" . "\n";
        }
    }
}
