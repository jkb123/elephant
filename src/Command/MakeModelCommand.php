<?php

declare(strict_types=1);

namespace Meibuyu\Micro\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Schema\MySqlBuilder;
use Hyperf\DbConnection\Db;
use Hyperf\Utils\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * @Command
 */
class MakeModelCommand extends HyperfCommand
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    private $path;
    private $appPath;

    private $builder;

    private $table = "";
    private $tableIndex = 0;

    private $tables = [];

    private $allTableStructures = [];

    private $currentTableStructure = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->path = __DIR__ . "/stubs/";
        $this->appPath = BASE_PATH . '/app/';
        $this->builder = $this->getSchemaBuilder();
        parent::__construct('mm');
    }

    /**
     * 获取数据库创建者
     * @return MySqlBuilder
     */
    protected function getSchemaBuilder(): MySqlBuilder
    {
        $resolver = $this->container->get(ConnectionResolverInterface::class);
        $connection = $resolver->connection();
        return $connection->getSchemaBuilder();
    }

    public function handle()
    {
        $this->initTableStructures();
        /*$this->info(print_r($this->allTableStructures, true));
        return false;*/
        $tables = [];
        $isAll = $this->input->getOption('database');
        $table = $this->input->getArgument('name');
        if (!$table && !$isAll) {
            $this->error("请输入表名进行单表生成 或者 -d 参数 进行全库生成,或使用 --help查看帮助");
            return false;
        }
        if ($this->input->getOption('database')) {
            $tables = $this->tables;
        } else {
            $tables[] = $this->getTable();
        }
        foreach ($tables as $k => $v) {
            if ($v == 'migrations') {
                continue;
            }
            if (!$this->builder->hasTable($v)) {
                $this->info("表" . $v . "不存在！");
                continue;
            }
            $this->tableIndex = $k;
            $this->input->setArgument("name", $v);
            $this->table = $v;
            $this->currentTableStructure = $this->allTableStructures[$v];
            $this->info("开始生成：" . $v);
            if (!Str::contains($v, "_to_") && ($this->input->getOption('model') || $this->input->getOption('all'))) {
                $this->makeModel();
            }
            if (!Str::contains($v, "_to_") && ($this->input->getOption('controller') || $this->input->getOption('all'))) {
                $this->makeRepositoryInterface();
                $this->makeRepository();
                $this->makeController();
            }
            if (!Str::contains($v, "_to_") && ($this->input->getOption('validator') || $this->input->getOption('all'))) {
                $this->makeValidator();
            }
            if (!Str::contains($v, "_to_") && $this->input->getOption('seeder')) {
                $this->makeSeeder();
            }
            if ($this->input->getOption('migrate') || $this->input->getOption('all')) {
                $this->makeMigrate();
            }
        }
    }

    /**
     *获取表结构
     */
    private function initTableStructures()
    {
        //tables
        $tables = Db::select("select table_name,`engine`,table_comment from  information_schema.tables 
                  where table_schema=database()");
        $tables = array_map('get_object_vars', $tables);
        $tables = collect($tables)->keyBy("table_name")->toArray();

        //fields
        $fields = Db::select("select table_name,column_name,column_default,is_nullable,data_type,collation_name,column_type,column_key,extra,column_comment 
                  from information_schema.columns where table_schema=database()");
        $fieldList = array_map('get_object_vars', $fields);
        //对字段数据进行处理
        foreach ($fieldList as $fk => $fv) {
            $fieldList[$fk]['length'] = trim(str_replace([$fv['data_type'], "(", ")"], "", $fv['column_type']));
            //针对层级处理
            if ($fv['column_name'] == 'parent_id' || $fv['column_name'] == 'pid') {
                $relation = [];
                $relation['function'] = "parent";
                $relation['relation_model_name'] = Str::studly(Str::singular($fv['table_name']));
                $relation['relation_table'] = $fv['table_name'];
                $relation['relation_table_key'] = "id";
                $relation['local_table'] = $fv['table_name'];
                $relation['local_table_key'] = $fv['column_name'];
                $tables[$relation['local_table']]['relations']['belongsTo'][] = $relation;

                $reverseRelation = [];
                $reverseRelation['function'] = 'children';
                $reverseRelation['relation_model_name'] = $relation['relation_model_name'];
                $reverseRelation['relation_table'] = $relation['local_table'];
                $reverseRelation['relation_table_key'] = $relation['local_table_key'];
                $reverseRelation['local_table'] = $relation['relation_table'];
                $reverseRelation['local_table_key'] = $relation['relation_table_key'];
                $tables[$reverseRelation['relation_table']]['relations']['hasMany'][] = $reverseRelation;

            } else if (Str::endsWith($fv['column_name'], '_id')) {
                //关联表处理
                if (Str::contains($fv['table_name'], "_to_")) {
                    $ts = explode("_to_", $fv['table_name']);
                    $ts = collect($ts)->map(function ($item) {
                        return Str::plural($item);
                    })->all();
                    //$this->info(print_r($ts, true));
                    $relation = [];
                    if (Str::singular($ts[0]) . "_id" == $fv['column_name']) {
                        $relation['constraint_table'] = $ts[1];
                        $relation['constraint_table_key'] = Str::singular($ts[1]) . "_id";
                        $relation['local_table'] = $ts[0];
                    } else {
                        $relation['constraint_table'] = $ts[0];
                        $relation['constraint_table_key'] = Str::singular($ts[0]) . "_id";
                        $relation['local_table'] = $ts[1];
                    }
                    $relation['local_table_key'] = $fv['column_name'];
                    $relation['relation_table'] = $fv['table_name'];
                    $relation['function'] = Str::snake($relation['constraint_table']);
                    $relation['relation_model_name'] = Str::studly(Str::singular($relation['constraint_table']));
                    $tables[$relation['local_table']]['relations']['belongsToMany'][] = $relation;
                } else {
                    $relation = [];
                    $relation['relation_table'] = Str::plural(Str::replaceLast("_id", "", $fv['column_name']));
                    if (!isset($tables[$relation['relation_table']])) {
                        continue;
                    }
                    $relation['function'] = Str::snake(Str::singular($relation['relation_table']));
                    $relation['relation_model_name'] = Str::studly(Str::singular($relation['relation_table']));
                    $relation['relation_table_key'] = "id";
                    $relation['local_table'] = $fv['table_name'];
                    $relation['local_table_key'] = $fv['column_name'];
                    $tables[$relation['local_table']]['relations']['belongsTo'][] = $relation;
                    $reverseRelation = [];
                    $reverseRelation['relation_table'] = $relation['local_table'];
                    $reverseRelation['relation_table_key'] = $relation['local_table_key'];
                    $reverseRelation['local_table'] = $relation['relation_table'];
                    $reverseRelation['local_table_key'] = $relation['relation_table_key'];
                    $reverseRelation['function'] = Str::snake($reverseRelation['relation_table']);
                    $reverseRelation['relation_model_name'] = Str::studly(Str::singular($reverseRelation['relation_table']));
                    $tables[$reverseRelation['local_table']]['relations']['hasMany'][] = $reverseRelation;
                }
            }
        }
        $fields = collect($fieldList)->groupBy("table_name")->toArray();

        //constraints
        $constraints = Db::select("select a.constraint_name,a.table_name,b.column_name,a.referenced_table_name,
                        b.referenced_column_name,a.update_rule,a.delete_rule
                        from information_schema.referential_constraints a,information_schema.key_column_usage b 
                        where a.constraint_schema=database() and a.constraint_schema=b.constraint_schema 
                        and a.table_name=b.table_name and a.constraint_name=b.constraint_name");
        $constraints = array_map('get_object_vars', $constraints);
        $constraints = collect($constraints)->groupBy("table_name")->toArray();

        //$indexes
        $indexes = Db::select("select table_name,non_unique,index_name,column_name 
              from information_schema.statistics where table_schema=database()");
        $indexes = array_map('get_object_vars', $indexes);
        $indexes = collect($indexes)->groupBy("table_name")->toArray();
        foreach ($tables as $k => $v) {
            $v['fields'] = $fields[$k] ?? [];
            $v['constraints'] = $constraints[$k] ?? [];
            if (isset($indexes[$k])) {
                $v['indexes'] = collect($indexes[$k])->groupBy("index_name")->toArray();
            } else {
                $v['indexes'] = [];
            }
            $tables[$k] = $v;
        }
        $this->resortTable($tables);
        $this->allTableStructures = $tables;
    }

    private function resortTable($tables)
    {
        $done = false;
        while (!$done) {
            foreach ($tables as $k => $v) {
                if (!$v['constraints']) {
                    $this->tables[] = $k;
                    unset($tables[$k]);
                } else {
                    $p = true;
                    foreach ($v['constraints'] as $cs) {
                        if (!in_array($cs['referenced_table_name'], $this->tables) && $cs['referenced_table_name'] != $k) {
                            $p = false;
                            break;
                        }
                    }
                    if ($p) {
                        $this->tables[] = $k;
                        unset($tables[$k]);
                    }
                }
            }
            if (empty($tables)) {
                $done = true;
            }
        }
    }

    /**
     * 获取当前数据库表名
     * @return string
     */
    private function getTable(): string
    {
        return Str::lower(trim($this->input->getArgument('name')));
    }

    private function makeModel()
    {
        $stubFile = $this->path . 'model.stub';
        $folder = $this->appPath . 'Model';
        $this->makeFolder($folder);
        $table = $this->table;
        $modelName = Str::studly(Str::singular($table));
        $file = $folder . "/" . $modelName . ".php";
        $content = file_get_contents($stubFile);
        $info = $this->currentTableStructure;
        $filterFields = ["id", "created_at", "updated_at", "deleted_at"];
        $fillAble = '';
        $properties = '';
        $timestamps = 0;
        $softDelete = false;
        $list = $info['fields'];
        foreach ($list as $v) {
            $name = $v['column_name'];
            $pc = [
                'bigint' => 'integer',
                'int' => 'integer',
                'tinyint' => 'integer',
                'smallint' => 'integer',
                'mediumint' => 'integer',
                'integer' => 'integer',
                'numeric' => 'integer',
                'float' => 'float',
                'real' => 'double',
                'double' => 'double',
                'decimal' => 'double',
                'bool' => 'bool',
                'boolean' => 'bool',
                'char' => 'string',
                'tinytext' => 'string',
                'text' => 'string',
                'mediumtext' => 'string',
                'longtext' => 'string',
                'year' => 'string',
                'varchar' => 'string',
                'string' => 'string',
                'enum' => 'array',
                'set' => 'array',
                'date' => 'string',
                'datetime' => 'string',
                'custom_datetime' => 'string',
                'timestamp' => 'string',
                'collection' => 'collection',
                'array' => 'array',
                'json' => 'string',
            ];
            $properties .= " * @property " . ($pc[$v['data_type']] ?? "string") . " $" . $name . ($v['column_comment'] ? " " . $v['column_comment'] : "") . "\n";
            if ($name == 'created_at' || $name == 'updated_at') {
                $timestamps++;
            }
            if ($name == 'deleted_at') {
                $softDelete = true;
            }
            if (in_array($name, $filterFields)) {
                continue;
            }
            $fillAble .= "\t\t'" . $name . "'," . "\n";
        }
        $relation = '';
        if (isset($info['relations']) && $info['relations']) {
            $relation .= "\n";
            if (isset($info['relations']['belongsTo'])) {
                foreach ($info['relations']['belongsTo'] as $v) {
                    $relation .= "\n\t/**\n\t* 属于" . $v['relation_model_name'] . "的关联\n\t*/";
                    $relation .= "\n\tpublic function " . $v['function'] . "()";
                    $relation .= "\n\t{";
                    $relation .= "\n\t\t" . 'return $this->belongsTo(' . $v['relation_model_name'] . "::class);";
                    $relation .= "\n\t}";
                    $properties .= " * @property " . $v['relation_model_name'] . " $" . $v['function'] . "\n";
                }
            }
            if (isset($info['relations']['belongsToMany'])) {
                foreach ($info['relations']['belongsToMany'] as $v) {
                    $relation .= "\n\t/**\n\t* 属于很多" . $v['relation_model_name'] . "的关联";
                    $relation .= "\n\t* @return \Hyperf\Database\Model\Relations\BelongsToMany";
                    $relation .= "\n\t**/";
                    $relation .= "\n\tpublic function " . $v['function'] . "()";
                    $relation .= "\n\t{";
                    $relation .= "\n\t\t" . 'return $this->belongsToMany(' . $v['relation_model_name'] . "::class,'"
                        . $v['relation_table'] . "');";
                    $relation .= "\n\t}";
                    $properties .= " * @property " . $v['relation_model_name'] . "[] $" . $v['function'] . "\n";
                }
            }
            if (isset($info['relations']['hasMany'])) {
                foreach ($info['relations']['hasMany'] as $v) {
                    $relation .= "\n\t/**\n\t* 有很多" . $v['relation_model_name'] . "的关联";
                    $relation .= "\n\t* @return \Hyperf\Database\Model\Relations\HasMany";
                    $relation .= "\n\t**/";
                    $relation .= "\n\tpublic function " . $v['function'] . "()";
                    $relation .= "\n\t{";
                    $relation .= "\n\t\t" . 'return $this->hasMany(' . $v['relation_model_name'] . "::class);";
                    $relation .= "\n\t}";
                    $properties .= " * @property " . $v['relation_model_name'] . "[] $" . $v['function'] . "\n";
                }
            }
        }
        $sd = '';
        $sdn = '';
        if ($softDelete) {
            $sd = "use SoftDeletes;\n";
            $sdn = "use Hyperf\Database\Model\SoftDeletes;\n";
        }
        $patterns = ['%namespace%', "%ClassName%", "%fillAble%", '%relations%', '%timestamps%', '%properties%', '%SoftDelete%'];
        $replacements = [$sdn, $modelName, $fillAble, $relation, ($timestamps == 2 ? 'true' : 'false'), $properties, $sd];

        $content = $this->buildField($patterns, $replacements, $content);
        $this->writeToFile($file, $content);
    }

    /**
     * 创建目录
     * @param $folder
     */
    private function makeFolder($folder)
    {
        if (!file_exists($folder)) {
            @mkdir($folder, 0777, true);
        }
    }

    /**
     * 替换文件内容
     * @param array $patterns 被替换的字符数组
     * @param array $replacements 替换的字符数组
     * @param string $content 文件原始内容
     * @return string
     */
    private function buildField(array $patterns, array $replacements, string $content): string
    {
        $author = $this->input->getOption("author");
        $patterns = array_merge($patterns, ['%user%', '%date%', '%time%']);
        $replacements = array_merge($replacements, [$author ?: "Auto generated.", date("Y-m-d"), date("h:i:s")]);
        return str_replace($patterns, $replacements, $content);
    }

    /**
     * 把内容写入文件
     * @param string $file 文件路径和文件名
     * @param string $content 文件内容
     * @return bool 创建是否成功
     */
    private function writeToFile(string $file, string $content): bool
    {
        $force = $this->input->getOption("force");
        if (!$force && file_exists($file)) {
            return false;
        }
        file_put_contents($file, $content);
        $file = pathinfo($file, PATHINFO_FILENAME);
        $this->info("<info>[INFO] Created File:</info> $file");
        return true;
    }

    private function makeRepositoryInterface()
    {
        $stubFile = $this->path . 'repositoryInterface.stub';
        $folder = $this->appPath . '/Repository/Interfaces';
        $this->makeFolder($folder);
        $table = $this->table;
        $className = Str::studly(Str::singular($table)) . "Repository";
        $file = $folder . "/" . $className . ".php";
        $content = file_get_contents($stubFile);

        $patterns = ["%ClassName%"];
        $replacements = [$className];
        $content = $this->buildField($patterns, $replacements, $content);
        $this->writeToFile($file, $content);
    }

    private function makeRepository()
    {
        $stubFile = $this->path . 'repository.stub';
        $folder = $this->appPath . '/Repository/Eloquent';
        $this->makeFolder($folder);

        $table = $this->table;
        $modelClass = Str::studly(Str::singular($table));
        $className = $modelClass . "RepositoryEloquent";
        $file = $folder . "/" . $className . ".php";
        $content = file_get_contents($stubFile);
        $info = $this->currentTableStructure;
        //列表
        $list = "\$conditions = \$this->request->all();\n";
        $list .= "\t\t\$list = \$this->model->where(function (\$q) use (\$conditions) {\n";
        foreach ($info['fields'] as $v) {
            if (Str::endsWith($v['column_name'], "_id")) {
                $list .= "\t\t\tif(isset(\$conditions['" . $v['column_name'] . "']) && \$conditions['" . $v['column_name'] . "'] !== '') {\n";
                $list .= "\t\t\t\t\$q->where('" . $v['column_name'] . "', \$conditions['" . $v['column_name'] . "']);\n";
                $list .= "\t\t\t}\n";
            } else if ($v['column_name'] == 'name' || Str::contains($v['column_name'], "_name")) {
                $list .= "\t\t\tif(isset(\$conditions['" . $v['column_name'] . "']) && \$conditions['keyword'] !== '') {\n";
                $list .= "\t\t\t\t\$q->where('" . $v['column_name'] . "', \$conditions['keyword']);\n";
                $list .= "\t\t\t}\n";
            }
        }
        $list .= "\t\t})";
        //显示
        $show = "\$info = \$this->model\n";
        //新增
        $create = "/** @var $modelClass \$model */\n \t\t\t\$model = parent::create(\$attributes);\n";
        //新增
        $update = "/** @var $modelClass \$model */\n \t\t\t\$model = parent::update(\$attributes, \$id);\n";
        //删除
        $delete = '';
        //关联查询
        $rs = "";
        if (isset($info['relations']) && $info['relations']) {
            if (isset($info['relations']) && $info['relations']) {
                if (isset($info['relations']['belongsTo'])) {
                    $list .= "\n\t\t->with([";
                    $show .= "\n\t\t->with([";
                    $t = [];
                    foreach ($info['relations']['belongsTo'] as $v) {
                        $x = "\n\t\t\t'" . $v['function'] . "' => function (\$q) {\n";
                        $fields = $this->listColumns($v['relation_table']);
                        $fields = collect($fields['fields'])->keyBy('column_name')
                            ->forget(['created_at', 'updated_at', 'deleted_at'])->pluck('column_name')->toArray();
                        $fields = join("','", $fields);
                        $x .= "\t\t\t\t\$q->select(['" . $fields . "']);\n";
                        $x .= "\t\t\t}";
                        $t[] = $x;
                    }
                    $t = join(",", $t) . "])";
                    $list .= $t;
                    $show .= $t;
                }
                if (isset($info['relations']['belongsToMany'])) {
                    $list .= "\n\t\t->with([";
                    $show .= "\n\t\t->with([";
                    $t = [];
                    foreach ($info['relations']['belongsToMany'] as $v) {
                        $x = "\n\t\t\t'" . $v['function'] . "' => function (\$q) {\n";
                        $fields = $this->listColumns($v['constraint_table']);
                        $fields = collect($fields['fields'])->keyBy('column_name')
                            ->forget(['created_at', 'updated_at', 'deleted_at'])->pluck('column_name')->toArray();
                        $fields = join("','", $fields);
                        $x .= "\t\t\t\t\$q->select(['" . $fields . "'])->orderByDesc('id')->limit(20);\n";
                        $x .= "\t\t\t}";
                        $t[] = $x;

                        $create .= "\t\t\tisset(\$attributes['" . $v['constraint_table_key'] . "s']) && \$model->{$v['function']}()->sync(\$attributes['" . $v['constraint_table_key'] . "s']);\n";
                        $update .= "\t\t\tisset(\$attributes['" . $v['constraint_table_key'] . "s']) && \$model->{$v['function']}()->sync(\$attributes['" . $v['constraint_table_key'] . "s']);\n";
                    }
                    $t = join(",", $t) . "])";
                    $list .= $t;
                    $show .= $t;
                }
                if (isset($info['relations']['hasMany'])) {
                    foreach ($info['relations']['hasMany'] as $v) {
                        $f = Str::camel($v['function']);
                        $rs .= "\n\tpublic function $f(\$id): array\n";
                        $rs .= "\t{\n";
                        $rs .= "\t\t\$pageSize = (int)\$this->request->input('page_size', DEFAULT_PAGE_SIZE);\n";
                        $rs .= "\t\treturn \$this->find(\$id)->{$v['function']}()->orderByDesc('id')->paginate(\$pageSize)->toArray();\n";
                        $rs .= "\t}\n";
                    }
                }
            }
        }
        $list .= "\n\t\t->paginate(\$pageSize)\n";
        $list .= "\t\t->toArray();\n";
        $list .= "\t\treturn \$list;";
        $show .= "\n\t\t->find(\$id)\n\t\t->toArray();\n";
        $show .= "\t\treturn \$info;";


        $patterns = ["%ModelClass%", "%list%", "%show%", "%create%", "%update%", "%delete%", "%rs%"];
        $replacements = [$modelClass, $list, $show, $create, $update, $delete, $rs];
        $content = $this->buildField($patterns, $replacements, $content);
        $this->writeToFile($file, $content);
        $this->addDepends($modelClass);
    }

    private function listColumns($table)
    {
        $table = trim($table);
        return $this->allTableStructures[$table] ?? [];
    }

    /**
     * 添加类到依赖注入配置文件
     * @param $modelClass
     */
    private function addDepends($modelClass)
    {
        $file = BASE_PATH . '/config/autoload/dependencies.php';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (strpos($content, "\App\Repository\Interfaces\\" . $modelClass . "Repository::class") !== false) {
                return;
            }
            $content = str_replace("]", "    \\App\\Repository\\Interfaces\\" . $modelClass . "Repository::class => \\App\\Repository\\Eloquent\\" . $modelClass . "RepositoryEloquent::class,\n]", $content);
            $this->writeToFile($file, $content);
        }
    }

    private function makeController()
    {
        $stubFile = $this->path . 'controller.stub';
        $folder = $this->appPath . '/Controller';
        $this->makeFolder($folder);

        $table = $this->table;
        $modelClass = Str::studly(Str::singular($table));
        $className = $modelClass . "Controller";
        $file = $folder . "/" . $className . ".php";
        $content = file_get_contents($stubFile);

        $info = $this->currentTableStructure;
        //关联查询
        $rs = "";
        $routes = [];
        if (isset($info['relations']) && $info['relations']) {
            if (isset($info['relations']) && $info['relations']) {
                if (isset($info['relations']['hasMany'])) {
                    foreach ($info['relations']['hasMany'] as $v) {
                        $f = Str::camel($v['function']);
                        $rs .= "\n\t/**";
                        $rs .= "\n\t * 获取{$v['relation_model_name']}关联列表数据";
                        $rs .= "\n\t * @Perm(\"index\")";
                        $rs .= "\n\t * @param; \$id id编号";
                        $rs .= "\n\t * @return mixed";
                        $rs .= "\n\t */";
                        $rs .= "\n\tpublic function $f(\$id)\n";
                        $rs .= "\t{\n";
                        $rs .= "\t\t\$data = \$this->repository->$f(\$id);\n";
                        $rs .= "\t\treturn success('获取成功', \$data);\n";
                        $rs .= "\t}\n";
                        $routes[] = $v['function'];
                    }
                }
            }
        }

        $patterns = ["%ModelClass%", "%rs%"];
        $replacements = [$modelClass, $rs];
        $content = $this->buildField($patterns, $replacements, $content);
        $this->writeToFile($file, $content);
        $this->addRoutes($modelClass, $routes);
    }

    /**
     * 添加Controller到路由类
     * @param $modelClass
     * @param array $routes
     */
    private function addRoutes($modelClass, $routes = [])
    {
        $file = BASE_PATH . '/config/routes.php';
        if (file_exists($file)) {
            $table = $this->table;
            $content = file_get_contents($file);
            $group = str_replace("_", "/", Str::snake($table));
            if (strpos($content, "Router::addGroup('" . $group . "', function () {") !== false) {
                return;
            }
            $info = $this->currentTableStructure;
            $tableComment = (isset($info['table_comment']) && $info['table_comment']) ? $info['table_comment'] : $table;
            $content .= "\n\t// " . $tableComment;
            $content .= "\n\tRouter::addGroup('" . $group . "', function () {";
            $content .= "\n\t\tRouter::get('', 'App\Controller\\" . $modelClass . "Controller@index');";
            $content .= "\n\t\tRouter::get('/{id:\d+}', 'App\Controller\\" . $modelClass . "Controller@show');";
            $content .= "\n\t\tRouter::post('', 'App\Controller\\" . $modelClass . "Controller@create');";
            $content .= "\n\t\tRouter::patch('/{id:\d+}', 'App\Controller\\" . $modelClass . "Controller@update');";
            $content .= "\n\t\tRouter::delete('/{id:\d+}', 'App\Controller\\" . $modelClass . "Controller@delete');";
            if ($routes) {
                foreach ($routes as $v) {
                    $content .= "\n\t\tRouter::get('/$v/{id:\d+}', 'App\Controller\\" . $modelClass . "Controller@$v');";
                }
            }
            $content .= "\n\t});";
            $this->writeToFile($file, $content);
        }
    }

    /**
     * 创建验证文件
     */
    private function makeValidator()
    {
        $stubFile = $this->path . 'validator.stub';
        $folder = $this->appPath . '/Validators';
        $this->makeFolder($folder);
        $table = $this->table;
        $modelClass = Str::studly(Str::singular($table));
        $className = $modelClass . "Validator";
        $file = $folder . "/" . $className . ".php";
        $content = file_get_contents($stubFile);
        $info = $this->currentTableStructure;
        $filterFields = ["id", "created_at", "updated_at", "deleted_at"];
        $rules = '';
        $attributes = '';
        $list = $info['fields'];
        foreach ($list as $v) {
            $name = $v['column_name'];
            $default = $v['column_default'];
            $type = $v['data_type'];
            $key = $v['column_key'];
            $null = $v['is_nullable'];
            $comment = $v['column_comment'];
            $length = $v['length'];
            if (in_array($name, $filterFields)) {
                continue;
            }
            $rs = [];
            $required = "nullable";
            if ($null !== 'YES') {
                if ($default !== '' && $default !== '0' && !$default) {
                    $required = "required";
                }
            }
            $rs[] = $required;
            switch ($type) {
                case "bigint":
                case "smallint":
                case "tinyint":
                case "mediumint":
                case "int":
                case "integer":
                    $rs[] = 'integer';
                    break;
                case "decimal":
                case "double":
                case "float":
                case "numeric":
                case "real":
                    $rs[] = 'numeric';
                    break;
                case "char":
                case "varchar":
                case "tinytext":
                case "mediumtext":
                case "longtext":
                case "text":
                    $rs[] = 'string';
                    if ($length) {
                        $rs[] = 'max:' . $length;
                    }
                    break;
                case "date":
                case "datetime":
                case "time":
                case "timestamp":
                case "year":
                    $rs[] = 'date';
                    break;
                case "enum":
                case "set":
                    $rs[] = 'in:[' . $length . "]";
                    break;
                default:
                    if (Str::contains($name, "email") || Str::contains($name, "e-mail") || Str::contains($name, "e_mail")) {
                        $rs[] = 'email';
                    } elseif ($name == 'url'
                        || Str::contains($name, "_url")
                        || Str::contains($name, "url_")) {
                        $rs[] = 'url';
                    } elseif ($name == 'date'
                        || Str::contains($name, "_date")
                        || Str::contains($name, "date_")) {
                        $rs[] = 'date';
                    }
                    break;
            }

            if ($key == 'uni') {
                $rs[] = "unique:$table," . $name;
            }
            if ($comment) {
                $attributes .= "\t\t'" . $name . "' => '" . $comment . "'," . "\n";
            }
            $rules .= "\t\t\t'" . $name . "' => '" . implode("|", $rs) . "'," . ($comment ? "// " . $comment . "-" . $type : "//" . $type) . "\n";
        }
        $patterns = ["%ModelClass%", '%createRules%', '%updateRules%', '%attributes%'];
        $createRules = $rules;
        $updateRules = str_replace("nullable", "sometimes|nullable", $rules);
        $updateRules = str_replace("required", "sometimes|required", $updateRules);
        $replacements = [$modelClass, $createRules, $updateRules, $attributes];
        $content = $this->buildField($patterns, $replacements, $content);
        $this->writeToFile($file, $content);
    }

    private function makeSeeder()
    {
        $stubFile = $this->path . 'seeder.stub';
        $folder = BASE_PATH . '/seeders/seeders/';
        $this->makeFolder($folder);
        $table = $this->table;
        $modelClass = Str::studly(Str::singular($table));
        $className = Str::studly($table) . "TableSeeder";
        $file = $folder . "/" . $table . "_table_seeder.php";
        $content = file_get_contents($stubFile);
        $info = $this->currentTableStructure;
        $filterFields = ["id"];
        $fields = [];
        $otherModel = [];
        $generateCount = $this->input->getOption("seeder");
        $generateCount = $generateCount ?: 30;
        $otherProcess = "";
        $list = $info['fields'];
        $maxNumber = 4;
        foreach ($list as $v) {
            $name = $v['column_name'];
            $type = $v['data_type'];
            $length = explode(" ", $v['length']);
            if (in_array($name, $filterFields)) {
                continue;
            }

            switch ($type) {
                case "bigint":
                case "smallint":
                case "tinyint":
                case "mediumint":
                case "int":
                case "integer":
                    if ($name == "sex") {
                        $fields[] = "\t\t\t\t'$name' => \$faker->randomElement([0,1]),";
                    } else if (Str::contains($name, "status")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->randomDigit,";
                    } else if (Str::endsWith($name, "_id")) {
                        $o = str_replace("_id", "", $name);
                        $os = Str::plural($o);
                        if (in_array($os, $this->tables)) {
                            $o = Str::studly($o);
                            $otherModel[] = "\nuse App\Model\\" . $o . ";";
                            $fields[] = "\t\t\t\t'$name' => $o::orderBy(Db::raw('rand()'))->first()->id,";
                        } else {
                            $n = ((isset($length[0]) && $length[0] && $length[0] < $maxNumber) ? $length[0] : $maxNumber);
                            $n = rand(1, $n);
                            $fields[] = "\t\t\t\t'$name' => \$faker->randomNumber($n),";
                        }
                    } else {
                        $n = ((isset($length[0]) && $length[0] < $maxNumber) ? $length[0] : $maxNumber);
                        $n = rand(1, $n);
                        $fields[] = "\t\t\t\t'$name' => \$faker->randomNumber($n),";
                    }
                    break;
                case "decimal":
                case "double":
                case "float":
                case "numeric":
                case "real":
                    $n = ((isset($length[0]) && $length[0] && $length[0] < $maxNumber) ? $length[0] : $maxNumber);
                    $n = rand(1, $n);
                    $n2 = ((isset($length[1]) && $length[1] < $maxNumber) ? $length[1] : 2);
                    $n3 = $n2 + 20;
                    $fields[] = "\t\t\t\t'$name' => \$faker->randomFloat($n,$n2,$n3),";
                    break;
                case "char":
                case "varchar":
                    $n = ((isset($length[0]) && $length[0]) ? $length[0] : 255);
                    if ($name == "ip" || $name == "ip_address" || $name == "ip_addr") {
                        $fields[] = "\t\t\t\t'$name' => \$faker->ipv4,";
                    } else if (Str::contains($name, "email")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->email,";
                    } else if ($name == "userName" || $name == "user_name" || $name == "uname") {
                        $fields[] = "\t\t\t\t'$name' => \$faker->userName,";
                    } else if ($name == "url" || $name == "domain" || Str::endsWith($name, "_url") || Str::startsWith($name, "url_")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->url,";
                    } else if ($name == "company" || $name == "company_name") {
                        $fields[] = "\t\t\t\t'$name' => \$faker->company,";
                    } else if ($name == "gender") {
                        $fields[] = "\t\t\t\t'$name' => \$faker->title(),";
                    } else if ($name == "name") {
                        $fields[] = "\t\t\t\t'$name' => \$faker->name(),";
                    } else if (Str::contains($name, "city")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->city,";
                    } else if (Str::contains($name, "street") || Str::contains($name, "address")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->streetAddress,";
                    } else if (Str::contains($name, "postcode")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->postcode,";
                    } else if (Str::contains($name, "country")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->country,";
                    } else if (Str::contains($name, "phoneNumber") || $name == "tel" || $name == "mobile" || Str::contains($name, "phone")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->phoneNumber,";
                    } else if (Str::contains($name, "color")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->colorName,";
                    } else if (Str::contains($name, "image") || Str::contains($name, "path")) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->imageUrl(640, 480),";
                    } else if ($name == "ean" || $name == "bar_code") {
                        $fields[] = "\t\t\t\t'$name' => \$faker->ean13,";
                    } else if ($n < 10) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->word,";
                    } elseif ($n < 100) {
                        $fields[] = "\t\t\t\t'$name' => \$faker->sentence(6),";
                    } else {
                        $n = rand(2, 5);
                        $fields[] = "\t\t\t\t'$name' => \$faker->paragraph($n, true),";
                    }
                    break;
                case "tinytext":
                case "mediumtext":
                case "longtext":
                case "text":
                    $fields[] = "\t\t\t\t'$name' => \$faker->text,";
                    break;
                case "date":
                    $fields[] = "\t\t\t\t'$name' => \$faker->date('Y-m-d'),";
                    break;
                case "datetime":
                    $fields[] = "\t\t\t\t'$name' => \$faker->date('Y-m-d').' '.\$faker->time('H:i:s'),";
                    break;
                case "time":
                    $fields[] = "\t\t\t\t'$name' => \$faker->time('H:i:s'),";
                    break;
                case "timestamp":
                    if ($name == 'created_at' || $name == 'updated_at' || $name == 'deleted_at') {
                        $fields[] = "\t\t\t\t'$name' => \$faker->date('Y-m-d').' '.\$faker->time('H:i:s'),";
                    } else {
                        $fields[] = "\t\t\t\t'$name' => \$faker->unixTime(),";
                    }
                    break;
                case "year":
                    $fields[] = "\t\t\t\t'$name' => \$faker->year(),";
                    break;
                case "enum":
                case "set":
                    $n = implode(",", $length);
                    $fields[] = "\t\t\t\t'$name' => \$faker->randomElement([$n]),";
                    break;
                default:
                    $fields[] = "\t\t\t\t'$name' => \$faker->word,";
                    break;
            }
        }
        $fields = join("\n", $fields);
        $otherModel = join("", $otherModel);
        $patterns = ["%modelClass%", '%className%', '%otherModel%', '%generateCount%', '%fields%', '%otherProcess%'];
        $replacements = [$modelClass, $className, $otherModel, $generateCount, $fields, $otherProcess];
        $content = $this->buildField($patterns, $replacements, $content);
        $this->writeToFile($file, $content);
        $this->addSeeder();
    }

    private function addSeeder()
    {
        $stubFile = $this->path . 'databaseSeeder.stub';
        $folder = BASE_PATH . '/seeders/';
        $file = $folder . "/DatabaseSeeder.php";
        if (!file_exists($file)) {
            $content = file_get_contents($stubFile);
            $this->writeToFile($file, $content);
        }
        $content = file_get_contents($file);
        if (strpos($content, Str::studly($this->table) . "TableSeeder::class") !== false) {
            return;
        }
        $content = str_replace("];", "\t\t\t" . Str::studly($this->table) . "TableSeeder::class,\n\t\t];", $content);
        $this->writeToFile($file, $content);
    }

    private function makeMigrate()
    {
        $stubFile = $this->path . 'migration.stub';
        $folder = BASE_PATH . '/migrations';
        $this->makeFolder($folder);

        $table = $this->table;
        $className = "Create" . Str::studly($table) . "Table";
        $file = $folder . "/" . $this->getDatePrefix() . "_" . $this->tableIndex . "_create_" . $table . "_table.php";
        $content = file_get_contents($stubFile);
        $info = $this->currentTableStructure;
        $attributes = [];
        $timestamps = 0;
        //$b = new Blueprint('test');
        $softDelete = false;
        $pri = false;
        //生成字段
        foreach ($info['fields'] as $v) {
            $name = $v['column_name'];
            $default = $v['column_default'];
            $type = $v['data_type'];
            $collation = $v['collation_name'];
            $null = $v['is_nullable'];
            $extra = $v['extra'];
            $comment = $v['column_comment'];
            $length = $v['length'];
            if ($name == 'updated_at' || $name == 'created_at') {
                $timestamps++;
            } elseif ($name == 'deleted_at') {
                $softDelete = true;
            } else {
                $t = "\t\t\t\$table->";
                switch ($type) {
                    case "bigint":
                    case "smallint":
                    case "tinyint":
                    case "mediumint":
                    case "int":
                    case "integer":
                        if ($type == 'int' || $type == 'integer') {
                            $t .= "integer('" . $name . "'";
                        } else {
                            $t .= str_replace("int", "", $type) . "Integer('" . $name . "'";
                        }
                        if ($extra == 'auto_increment') {
                            $pri = true;
                            $t .= ", true";
                        } else {
                            $t .= ", false";
                        }
                        if ($length && strpos($length, "unsigned") !== false) {
                            $t .= ", true";
                        }
                        $t .= ")";
                        break;
                    case "decimal":
                    case "double":
                    case "float":
                    case "numeric":
                    case "real":
                        $tc = [
                            'numeric' => 'decimal',
                            'real' => 'double',
                        ];
                        $t .= ($tc[$type] ?? $type) . "('" . $name . "'";
                        if ($length) {
                            $length = explode(" ", $length);
                            $length = explode(",", $length[0]);
                            $t .= ", " . $length[0] . ", " . $length[1];
                        }
                        $t .= ")";
                        break;
                    case "char":
                    case "varchar":
                    case "tinytext":
                    case "mediumtext":
                    case "longtext":
                    case "desc":
                    case "bit":
                    case "boolean":
                    case "text":
                        $tc = [
                            'char' => 'char',
                            'varchar' => 'string',
                            'desc' => 'text',
                            'tinytext' => 'text',
                            'text' => 'text',
                            'bit' => 'boolean',
                            'boolean' => 'boolean',
                            'longtext' => 'longText',
                            'mediumtext' => 'mediumText',
                        ];
                        $t .= $tc[$type] . "('" . $name . "'";
                        if ($length) {
                            $t .= ", " . $length;
                        }
                        $t .= ")";
                        break;
                    case "date":
                    case "datetime":
                    case "time":
                    case "timestamp":
                    case "year":
                        $tc = [
                            'datetime' => 'dateTime',
                        ];
                        $t .= ($tc[$type] ?? $type) . "('" . $name . "')";
                        break;
                    case "binary":
                    case "varbinary":
                    case "longblob":
                    case "blob":
                    case "mediumblob":
                    case "tinyblob":
                        $t .= "binary('" . $name . "')";
                        break;
                    case "enum":
                    case "set":
                        $tc = [
                            'set' => 'enum',
                        ];
                        $t .= ($tc[$type] ?? $type) . "('" . $name . "'";
                        $t .= ", [{
                            $length}])";
                        break;
                    case "geometry":
                    case "geometrycollection":
                    case "json":
                    case "jsonb":
                    case "point":
                    case "polygon":
                    case "linestring":
                    case "multipoint":
                    case "multipolygon":
                    case "multilinestring":
                        $tc = [
                            'geometrycollection' => 'geometryCollection',
                            'linestring' => 'lineString',
                            'multipoint' => 'multiPoint',
                            'multipolygon' => 'multiPolygon',
                            'multilinestring' => 'multiLineString',
                        ];
                        $t .= ($tc[$type] ?? $type) . "('" . $name . "'";
                        if ($length) {
                            $t .= ", " . $length;
                        }
                        $t .= ")";
                        break;
                    default:
                        $t = '';
                        break;
                }
                if ($t) {
                    if ($null == 'YES') {
                        $t .= "->nullable()";
                    }
                    if (($default && $default !== '') || $default === '0') {
                        if ($type !== 'timestamp') {
                            $t .= "->default('$default')";
                        }
                    }
                    if ($collation) {
                        $t .= "->collation('$collation')";
                    }
                    if ($comment) {
                        $t .= "->comment('$comment')";
                    }
                    $t .= ";";
                    $attributes[] = $t;
                }
            }
        }
        if ($timestamps == 2) {
            $attributes[] = "\t\t\t\$table->timestamps();";
        }
        if ($softDelete) {
            $attributes[] = "\t\t\t\$table->softDeletes();";
        }
        //主键及索引
        if ($info['indexes']) {
            foreach ($info['indexes'] as $k => $v) {
                $fields = collect($v)->pluck("column_name")->all();
                $fields = implode("','", $fields);
                if ($k == 'PRIMARY') {
                    if (!$pri) {
                        $attributes[] = "\t\t\t\$table->primary(['$fields']);";
                    }
                } else {
                    if ($v[0]['non_unique'] === 0) {
                        $attributes[] = "\t\t\t\$table->unique(['$fields'], '$k');";
                    } else {
                        $attributes[] = "\t\t\t\$table->index(['$fields'], '$k');";
                    }
                }
            }
        }
        //外键
        if ($info['constraints']) {
            foreach ($info['constraints'] as $v) {
                $t = "\t\t\t\$table->foreign('{$v['column_name']}','{$v['constraint_name']}')->references('{$v['referenced_column_name']}')->on('{$v['referenced_table_name']}')";
                if ($v['delete_rule']) {
                    $t .= "->onDelete('{$v['delete_rule']}')";
                }
                /*if ($v['update_rule']) {
                    $t .= "->onUpdate('{$v['update_rule']}')";
                }*/
                $attributes[] = $t . ";";
            }
        }
        $attributes = implode("\n", $attributes);
        $tableComment = "";
        if (isset($info['table_comment']) && $info['table_comment']) {
            $tableComment = 'Db::statement("alter table `' . $table . '` comment \'' . $info['table_comment'] . '\'");';
        }
        $patterns = ["%ClassName%", '%tableName%', '%attributes%', '%tableComment%'];
        $replacements = [$className, $table, $attributes, $tableComment];
        $content = $this->buildField($patterns, $replacements, $content);
        $this->writeToFile($file, $content);

    }

    /**生成迁移文件的日期格式
     * @return string
     */
    private function getDatePrefix(): string
    {
        return date('Y_m_dHis');
    }

    public function configure()
    {
        parent::configure();
        $this->addOption('all', 'a', InputOption::VALUE_NONE, '生成所有文件');
        $this->addOption('model', 'm', InputOption::VALUE_NONE, '生成model文件');
        $this->addOption('controller', 'c', InputOption::VALUE_NONE, '生成controller文件');
        $this->addOption('migrate', 'i', InputOption::VALUE_NONE, '生成迁移文件');
        $this->addOption('validator', 'l', InputOption::VALUE_NONE, '生成验证文件');
        $this->addOption('author', 'r', InputOption::VALUE_OPTIONAL, '文件作者，后面可跟空格名字，表示生成的文件作者');
        $this->addOption('seeder', 's', InputOption::VALUE_OPTIONAL, '生成数据填充文件,后面可跟空格数字，表示生成的数据量');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, '文件存在是否覆盖');
        $this->addOption('database', 'd', InputOption::VALUE_NONE, '全数据库索引自动生成全站文件');
        $this->setDescription('根据数据表生成model文件和迁移文件和控制器');
    }

    /**
     * 配置文件内容
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['name', InputArgument::OPTIONAL, '数据库表名'],
        ];
    }
}
