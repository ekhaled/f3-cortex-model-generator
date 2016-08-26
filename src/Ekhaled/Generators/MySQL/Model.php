<?php
namespace Ekhaled\Generators\MySQL;

class Model{

    private $config = array();

    public function __construct($config = array())
    {
        $defaults = array(
            'output'    => 'path/to/output/folder',
            'DB'        => array(),
            'namespace' => 'Models\Base',
            'extends'   => '\\Models\\Base',
            'relationNamespace' => '\Models\Base\\',
            'exclude' => array()
        );

        foreach ($config as $key => $value) {
            //overwrite the default value of config item if it exists
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = $value;
            }
        }

        //store the config back into the class property
        $this->config = $defaults;
    }

    public function generate()
    {
        $schema = $this->getSchema();
        $config = $this->config;

        foreach($schema as $table){
            if(!in_array($table['name'], $config['exclude'])){
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

    private function generateModel($schema, $namespace = null, $extends = null, $relationNamespace = '',$classname = null)
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
        $modelTemplate = str_replace('{{FIELDCONF}}', implode(",\n", $fieldConf), $modelTemplate);

        return $modelTemplate;

    }

    private function getTemplate()
    {
        return <<<PHP
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

    private function getSchema()
    {
        $schemaParser = new \Ekhaled\MysqlSchema\Parser($this->config['DB']);
        return $schemaParser->getSchema();
    }

    private function className($t, $ns = ''){
        return $ns.ucfirst(strtolower($t));
    }

    private function field(array $field, $relationNamespace = ''){
        $template = '        \''.$field['name'].'\' => [
{{VALUES}}
        ]';

        $values = [];

        if(isset($field['relation']) && count($field['relation']) > 0){
            $values[] = '\''.$field['relation']['type'].'\' => \''.$this->className($field['relation']['table'], $relationNamespace).'\'';
        }else{

            $values[] = '\'type\' => \''.$this->extractType($field['type']).'\'';

            if(!empty($field['default'])){
                $values[] = '\'default\' => \''.$field['default'].'\'';
            }

            $values[] = '\'nullable\' => ' . ($field['nullable'] ? 'true' : 'false');
        }

        $template = str_replace('{{VALUES}}', implode(",\n", array_map(function($val){
            return '            '.$val;
        }, $values)), $template);

        return $template;
    }

    private function virtualfield(array $relation, $relationNamespace = ''){
        if(isset($relation['via'])){
            return '        \''.$relation['selfColumn'].'\' => [
                \''.$relation['type'].'\' => [\''.$this->className($relation['table'], $relationNamespace).'\', \''.$relation['column'].'\', \''.$relation['via'].'\']
            ]';
        }else{
            return '        \''.$relation['table'].'\' => [
            \''.$relation['type'].'\' => [\''.$this->className($relation['table'], $relationNamespace).'\', \''.$relation['column'].'\']
        ]';
        }

    }

    private function extractType($dbType){

        $ints = [1,2,4,8];
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

        if($type == 'INT' && in_array($size, $ints)){
            return $type . $size;
        }

        return $type;
    }

    private function output($msg, $err = false){
        if($err){
            echo "\033[1;97;41m" .$msg."\e[0m" . "\n";
        }else{
            echo "\033[1;97;42m" .$msg."\e[0m" . "\n";
        }
    }
}