# F3 Cortex model generator
Generates [F3 Cortex](https://github.com/ikkez/f3-cortex) models by reverse engineering existing database schema.

**Currently only supports MySQL.**

## Installation
Please add
```php
"ekhaled/f3-cortex-model-generator": "1.0"
```
to your composer file.

## Usage
Create an executable PHP file with the following contents
```php
#!/usr/bin/env php
<?php
require_once(__DIR__ . '/../src/vendor/autoload.php');

$config = [
    'output'            => 'path/to/output/folder/',
    'DB'                => array(), //DB connection params
    'namespace'         => 'Models\\Base',
    'extends'           => '\\Models\\Base',
    'relationNamespace' => '\\Models\Base\\',
    'template'          => 'path/to/template/file',
    'exclude'           => array()
];

$generator = new \Ekhaled\Generators\MySQL\Model($config);
$generator->generate();
```
and, just run the file from the command line.

## Options
 - `output` - specifies the folder where models will be output to.
 - `DB` - an array in the following format ['host' => 'host.com', 'username' => '', 'password' => '', 'dbname' => 'name_of_database',]
 - `namespace` - Namespace of the generated models
 - `extends` - if you have a base model, you can make the generated model extend that model by specifying it here.
 - `relationNamespace` - Namespace of the connected classes that constitute relationships with a given model, usually it's the same as `namespace`
 - `template` - Path to file containing a custom template, if not specified a built-in template will be used.
 - `exclude_views` - Whether to generate models for Views too, defaults to _false_.
 - `exclude_connectors` - Whether to generate stub models for many-to-many connector tables, defaults to _false_. (Sometimes you might need these models to create db tables, for example for automated tests in test databases).
 - `exclude` - An array containing all tables that you would like to exclude while generating models. For example: `array('migrations')`.

## Custom templates
A typical custom template would look like:
```php
<?php
{{NAMESPACE}}

class {{CLASSNAME}} {{EXTENDS}}
{

    protected $fieldConf = [
{{FIELDCONF}}
    ],
    $table = '{{TABLENAME}}';

}
```
Just ensure that the placeholders are in place, and they will get replaced during model generation.

Supported placeholders are:
 - `{{NAMESPACE}}`
 - `{{CLASSNAME}}`
 - `{{EXTENDS}}`
 - `{{FIELDCONF}}`
 - `{{TABLENAME}}`

TODO - Add support for custom templates for fieldconf.
