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

        foreach($schema as $table){
            if(!in_array($table['name'], $this->config['exclude'])){
                $className = $this->className($table['name']);
                $h = fopen($config['output'].$className.'.php' , 'w');
                if(fwrite($h, $this->generateModel(
                    $table,
                    $config['namespace'],
                    $config['extends'],
                    $config['relationNamespace']
                ))){
                    output("Generated " .$className." model");
                }else{
                    output('Failed to generate '.$className.' model', true);
                }

                fclose($h);
                usleep(250000);
            }
        }

    }

    private function getSchema()
    {
        $schemaParser = new \Ekhaled\MysqlSchema\Parser($this->config['DB']);
        return $schemaParser->getSchema();
    }

    private function className($t, $ns = ''){
        return $ns.ucfirst(strtolower($t));
    }

    private function output($msg, $err = false){
        if($err){
            echo "\033[1;97;41m" .$msg."\e[0m" . "\n";
        }else{
            echo "\033[1;97;42m" .$msg."\e[0m" . "\n";
        }
    }
}