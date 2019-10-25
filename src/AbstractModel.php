<?php


namespace EasySwoole\ORM;

use ArrayAccess;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\ORM\Db\Result;
use EasySwoole\ORM\Exception\Exception;
use EasySwoole\ORM\Utility\PreProcess;
use EasySwoole\ORM\Utility\Schema\Table;
use EasySwoole\ORM\Utility\TableObjectGeneration;
use EasySwoole\Utility\Str;
use JsonSerializable;

/**
 * 抽象模型
 * Class AbstractMode
 * @package EasySwoole\ORM
 */
abstract class AbstractModel implements ArrayAccess, JsonSerializable
{

    private $lastQueryResult;
    private $lastQuery;
    /* 快速支持连贯操作 */
    private $fields = "*";
    private $limit  = NULL;
    private $withTotalCount = FALSE;
    private $order  = NULL;
    private $where  = [];
    private $join   = NULL;
    private $group  = NULL;
    /** @var array 关联模型数据 */
    private $_joinMap = [];
    /** @var string 表名 */
    protected $tableName = '';
    /** @var Table */
    private static $schemaInfoList;
    /**
     * 当前连接驱动类的名称
     * 继承后可以覆盖该成员以指定默认的驱动类
     * @var string
     */
    protected $connectionName = 'default';
    /*
     * 临时设定的链接
     */
    private $tempConnectionName = null;
    /**
     * 当前的数据
     * @var array
     */
    private $data;
    /**
     * 模型的原始数据
     * 未应用修改器和获取器之前的原始数据
     * @var array
     */
    private $originData;
    /* 回调事件 */
    private $onQuery;


    /**
     * getSchemaInfo
     * @param bool $isCache
     * @return Table
     * @author Tioncico
     * Time: 15:21
     */
    public function schemaInfo(bool $isCache = true): Table
    {
        if (isset(self::$schemaInfoList[$this->tableName]) && self::$schemaInfoList[$this->tableName] instanceof Table && $isCache == true) {
            return self::$schemaInfoList[$this->tableName];
        }

        if ($this->tempConnectionName) {
            $connectionName = $this->tempConnectionName;
        } else {
            $connectionName = $this->connectionName;
        }
        $tableObjectGeneration = new TableObjectGeneration(DbManager::getInstance()->getConnection($connectionName), $this->tableName);
        $schemaInfo = $tableObjectGeneration->generationTable();
        self::$schemaInfoList[$this->tableName] = $schemaInfo;

        return self::$schemaInfoList[$this->tableName];
    }


    /*  ==============    回调事件    ==================   */
    public function onQuery(callable $call)
    {
        $this->onQuery = $call;
        return $this;
    }
    /*  ==============    快速支持连贯操作    ==================   */
    /**
     * @param mixed ...$args
     * @return AbstractModel
     */
    public function order(...$args)
    {
        $this->order = $args;
        return $this;
    }
    /**
     * @param int $one
     * @param int|null $two
     * @return $this
     */
    public function limit(int $one, ?int $two = null)
    {
        if ($two !== null) {
            $this->limit = [$one, $two];
        } else {
            $this->limit = $one;
        }
        return $this;
    }
    /**
     * @param $fields
     * @return $this
     */
    public function field($fields)
    {
        if (!is_array($fields)) {
            $fields = [$fields];
        }
        $this->fields = $fields;
        return $this;
    }
    /**
     * @return $this
     */
    public function withTotalCount()
    {
        $this->withTotalCount = true;
        return $this;
    }
    /**
     * @param $where
     * @return $this
     */
    public function where($where)
    {
        $this->where[] = $where;
        return $this;
    }
    /**
     * @param string $group
     * @return $this
     */
    public function group(string $group)
    {
        $this->group = $group;
        return $this;
    }
    /**
     * @param $joinTable
     * @param $joinCondition
     * @param string $joinType
     * @return $this
     */
    public function join($joinTable, $joinCondition, $joinType = '')
    {
        $this->join[] = [$joinTable, $joinCondition, $joinType];
        return $this;
    }

    public function max($field)
    {
        return $this->queryPolymerization('max', $field);
    }

    public function min($field)
    {
        return $this->queryPolymerization('min', $field);
    }

    public function count($field = null)
    {
        return $this->queryPolymerization('count', $field);
    }

    public function avg($field)
    {
        return $this->queryPolymerization('avg', $field);
    }

    public function sum($field)
    {
        return $this->queryPolymerization('sum', $field);
    }

    /*  ==============    Builder 和 Result    ==================   */
    public function lastQueryResult(): ?Result
    {
        return $this->lastQueryResult;
    }
    public function lastQuery(): ?QueryBuilder
    {
        return $this->lastQuery;
    }

    function __construct(array $data = [])
    {
        //初始化表名
        $this->tableNameInit();
        $this->data($data);
    }


    function connection(string $name, bool $isTemp = false): AbstractModel
    {
        if ($isTemp) {
            $this->tempConnectionName = $name;
        } else {
            $this->connectionName = $name;
        }
        return $this;
    }

    public function getAttr($attrName)
    {
        $method = 'get' . str_replace( ' ', '', ucwords( str_replace( ['-', '_'], ' ', $this->__toString() ) ) ) . 'Attr';
        if (method_exists($this, $method)) {
            return $this->$method($this->data[$attrName] ?? null, $this->data);
        }
        // 判断是否有关联查询
        if (method_exists($this, $attrName)) {
            return $this->$attrName();
        }
        return $this->data[$attrName] ?? null;
    }


    public function setAttr($attrName, $attrValue): bool
    {
        if (isset($this->schemaInfo()->getColumns()[$attrName])) {
            $col = $this->schemaInfo()->getColumns()[$attrName];
            $attrValue = PreProcess::dataValueFormat($attrValue, $col);
            $method = 'set' . str_replace( ' ', '', ucwords( str_replace( ['-', '_'], ' ', $this->__toString() ) ) ) . 'Attr';
            if (method_exists($this, $method)) {
                $attrValue = $this->$method($attrValue, $this->data);
            }
            $this->data[$attrName] = $attrValue;
            return true;
        } else {
            return false;
        }
    }

    public function data(array $data)
    {
        foreach ($data as $key => $value) {
            $this->setAttr($key, $value);
        }
        $this->originData = $this->data;
        return $this;
    }

    /**
     * @param null $where
     * @param bool $allow 是否允许没有主键删除
     * @return int|null
     * @throws Exception
     * @throws \Throwable
     */
    public function destroy($where = null, $allow = false): ?int
    {
        $builder = new QueryBuilder();
        $primaryKey = $this->schemaInfo()->getPkFiledName();

        if (is_null($where) && $allow == false) {
            if (empty($primaryKey)) {
                throw new Exception('Table not have primary key, so can\'t use Model::get($pk)');
            } else {
                $whereVal = $this->getAttr($primaryKey);
                if (empty($whereVal)) {
                    throw new Exception('Table not have primary value');
                }
                $builder->where($primaryKey, $whereVal);
            }
        }

        PreProcess::mappingWhere($builder, $where, $this);
        $this->preHandleQueryBuilder($builder);
        $builder->delete($this->schemaInfo()->getTable(), $this->limit);
        $this->query($builder);
        return $this->lastQueryResult()->getAffectedRows();
    }

    /**
     * 保存 插入
     * @param bool $notNul
     * @throws Exception
     * @throws \Throwable
     * @return bool|int
     */
    public function save($notNul = false)
    {
        $builder = new QueryBuilder();
        $primaryKey = $this->schemaInfo()->getPkFiledName();
        if (empty($primaryKey)) {
            throw new Exception('save() needs primaryKey for model ' . static::class);
        }
        $rawArray = $this->toArray($notNul);
        $builder->insert($this->schemaInfo()->getTable(), $rawArray);
        $this->preHandleQueryBuilder($builder);
        $this->query($builder);
        if ($this->lastQueryResult()->getResult() === false) {
            return false;
        }

        if ($this->lastQueryResult()->getLastInsertId()) {
            $this->data[$primaryKey] = $this->lastQueryResult()->getLastInsertId();
            $this->originData = $this->data;
            return $this->lastQueryResult()->getLastInsertId();
        }
        return true;
    }

    /**
     * 获取数据
     * @param null $where
     * @return AbstractModel|null
     * @throws Exception
     * @throws \Throwable
     */
    public function get($where = null)
    {
        $modelInstance = new static;
        $builder = new QueryBuilder;
        $builder = PreProcess::mappingWhere($builder, $where, $modelInstance);
        $this->preHandleQueryBuilder($builder);
        $builder->getOne($modelInstance->schemaInfo()->getTable(), $this->fields);
        $res = $this->query($builder);
        if (empty($res)) {
            return null;
        }
        $modelInstance->data($res[0]);
        $modelInstance->lastQuery = $this->lastQuery();
        return $modelInstance;
    }


    /**
     * 批量查询
     * @param null $where
     * @param bool $returnAsArray
     * @return array
     * @throws Exception
     * @throws \Throwable
     */
    public function all($where = null, bool $returnAsArray = false): array
    {
        $builder = new QueryBuilder;
        $builder = PreProcess::mappingWhere($builder, $where, $this);
        $this->preHandleQueryBuilder($builder);
        $builder->get($this->schemaInfo()->getTable(), $this->limit, $this->fields);
        $results = $this->query($builder);
        $resultSet = [];
        if (is_array($results)) {
            foreach ($results as $result) {
                if ($returnAsArray) {
                    $resultSet[] = $result;
                } else {
                    $resultSet[] = static::create($result);
                }
            }
        }
        return $resultSet;
    }

    /**
     * 批量查询 不映射对象  返回数组
     * @param null $where
     * @return array
     * @throws Exception
     * @throws \Throwable
     */
    public function select($where = null):array
    {
        return $this->all($where, true);
    }

    public static function create(array $data = []): AbstractModel
    {
        return new static($data);
    }


    /**
     * 更新
     * @param array $data
     * @param null  $where
     * @return bool
     * @throws Exception
     * @throws \Throwable
     */
    public function update(array $data = [], $where = null)
    {
        if (empty($data)) {
            // $data = $this->toArray();
            $data = array_diff($this->data, $this->originData);
            if (empty($data)) {
                return true;
            }
        }
        $builder = new QueryBuilder();
        if ($where) {
            PreProcess::mappingWhere($builder, $where, $this);
        } else {
            $pk = $this->schemaInfo()->getPkFiledName();
            if (isset($this->data[$pk])) {
                $pkVal = $this->data[$pk];
                $builder->where($pk, $pkVal);
            } else {
                throw new Exception("update error,pkValue is require");
            }
        }
        $this->preHandleQueryBuilder($builder);
        $builder->update($this->schemaInfo()->getTable(), $data);
        $results = $this->query($builder);

        return $results ? true : false;
    }

    /**
     * ArrayAccess Exists
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->getAttr($offset);
    }

    public function offsetSet($offset, $value)
    {
        return $this->setAttr($offset, $value);
    }


    public function offsetUnset($offset)
    {
        return $this->setAttr($offset, null);
    }

    /**
     * json序列化方法
     * @return array|mixed
     */
    public function jsonSerialize()
    {
        $return = [];
        foreach ($this->data as $key => $data){
            $return[$key] = $this->getAttr($key);
        }
        return $return;
    }

    public function toArray($notNul = false): array
    {
        $temp = $this->data;
        if ($notNul) {
            foreach ($temp as $key => $value) {
                if ($value === null) {
                    unset($temp[$key]);
                }
            }
            return $temp;
        }
        if (is_array($this->fields)) {
            foreach ($temp as $key => $value) {
                if (in_array($key, $this->fields)) {
                    unset($temp[$key]);
                }
            }
        }
        return $temp;
    }

    public function __toString()
    {
        return json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    function __set($name, $value)
    {
        $this->setAttr($name, $value);
    }

    function __get($name)
    {
        return $this->getAttr($name);
    }

    protected function reset()
    {
        $this->tempConnectionName = null;

        $this->fields = "*";
        $this->limit  = NULL;
        $this->withTotalCount = FALSE;
        $this->order  = NULL;
        $this->where  = [];
        $this->join   = NULL;
        $this->group  = NULL;
    }

    /**
     * @param string        $class
     * @param callable|null $where
     * @param null          $pk
     * @param null          $joinPk
     * @param string        $joinType
     * @return mixed|null
     * @throws Exception
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws \ReflectionException
     * @throws \Throwable
     */
    protected function hasOne(string $class, callable $where = null, $pk = null, $joinPk = null, $joinType = '')
    {
        if (isset($this->_joinMap[$class])) {
            return $this->_joinMap[$class];
        }

        $ref = new \ReflectionClass($class);

        if (!$ref->isSubclassOf(AbstractModel::class)) {
            throw new Exception("relation class must be subclass of AbstractModel");
        }

        /** @var AbstractModel $ins */
        $ins = $ref->newInstance();
        $builder = new QueryBuilder();

        if ($pk === null) {
            $pk = $this->schemaInfo()->getPkFiledName();
        }
        if ($joinPk === null) {
            $joinPk = $ins->schemaInfo()->getPkFiledName();
        }

        $targetTable = $ins->schemaInfo()->getTable();
        $currentTable = $this->schemaInfo()->getTable();
        // 支持复杂的构造
        if ($where) {
            $builder = call_user_func($where, $builder);
            $this->preHandleQueryBuilder($builder);
            $builder->getOne($targetTable);
        } else {
            $builder->join($targetTable, "{$targetTable}.{$joinPk} = {$currentTable}.{$pk}", $joinType)
                ->where("{$currentTable}.{$pk}", $this->$pk);
            $this->preHandleQueryBuilder($builder);
            $builder->getOne($currentTable);
        }
        $result = $this->query($builder);
        if ($result) {
            $this->data($result[0]);
            $ins->data($result[0]);
            $this->_joinMap[$class] = $ins;

            return $this->_joinMap[$class];
        }
        return null;

    }

    /**
     * 一对多关联
     * @param string        $class
     * @param callable|null $where
     * @param null          $pk
     * @param null          $joinPk
     * @param string        $joinType
     * @return mixed|null
     * @throws Exception
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws \ReflectionException
     * @throws \Throwable
     */
    protected function hasMany(string $class, callable $where = null, $pk = null, $joinPk = null, $joinType = '')
    {
        if (isset($this->_joinMap[$class])) {
            return $this->_joinMap[$class];
        }

        $ref = new \ReflectionClass($class);

        if (!$ref->isSubclassOf(AbstractModel::class)) {
            throw new Exception("relation class must be subclass of AbstractModel");
        }

        /** @var AbstractModel $ins */
        $ins = $ref->newInstance();
        $builder = new QueryBuilder();

        if ($pk === null) {
            $pk = $this->schemaInfo()->getPkFiledName();
        }
        if ($joinPk === null) {
            $joinPk = $ins->schemaInfo()->getPkFiledName();
        }

        $targetTable = $ins->schemaInfo()->getTable();
        $currentTable = $this->schemaInfo()->getTable();
        // 支持复杂的构造
        if ($where) {
            $builder = call_user_func($where, $builder);
            $this->preHandleQueryBuilder($builder);
            $builder->get($targetTable);
        } else {
            $builder->join($targetTable, "{$targetTable}.{$joinPk} = {$currentTable}.{$pk}", $joinType)
                ->where("{$currentTable}.{$pk}", $this->$pk);
            $this->preHandleQueryBuilder($builder);
            $builder->get($currentTable);
        }
        $result = $this->query($builder);
        if ($result) {
            $return = [];
            foreach ($result as $one) {
                $return[] = ($ref->newInstance())->data($one);
            }
            $this->_joinMap[$class] = $return;

            return $this->_joinMap[$class];
        }
        return null;
    }

    protected function query(QueryBuilder $builder, bool $raw = false)
    {
        $start = microtime(true);
        $this->lastQuery = clone $builder;
        if ($this->tempConnectionName) {
            $connectionName = $this->tempConnectionName;
        } else {
            $connectionName = $this->connectionName;
        }
        try {
            $ret = null;
            $ret = DbManager::getInstance()->query($builder, $raw, $connectionName);
            $builder->reset();
            $this->lastQueryResult = $ret;
            return $ret->getResult();
        } catch (\Throwable $throwable) {
            throw $throwable;
        } finally {
            $this->reset();
            if ($this->onQuery) {
                $temp = clone $builder;
                call_user_func($this->onQuery, $ret, $temp, $start);
            }
        }
    }

    protected function tableNameInit()
    {
        if (empty($this->tableName)) {
            $className = get_called_class();
            $classNameArr = explode('\\', $className);
            //切割当前类名
            $className = $classNameArr[count($classNameArr) - 1];
            //去掉Model
            // $tableName = str_replace('Model', '', $className);
            $tableName = $className;
            //驼峰转下划线
            $tableName = Str::snake($tableName);
            $this->tableName = $tableName;
        }
    }

    /**
     * 连贯操作预处理
     * @param QueryBuilder $builder
     * @throws Exception
     * @throws \EasySwoole\Mysqli\Exception\Exception
     */
    protected function preHandleQueryBuilder(QueryBuilder $builder)
    {
        // 快速连贯操作
        if ($this->withTotalCount) {
            $builder->withTotalCount();
        }
        if ($this->order) {
            $builder->orderBy(...$this->order);
        }
        if ($this->where) {
            $whereModel = new static();
            foreach ($this->where as $whereOne){
                $builder = PreProcess::mappingWhere($builder, $whereOne, $whereModel);
            }
        }
        if($this->group){
            $builder->groupBy($this->group);
        }
        if($this->join){
            foreach ($this->join as $joinOne) {
                $builder->join($joinOne[0], $joinOne[1], $joinOne[2]);
            }
        }
    }

    private function queryPolymerization($type, $field = null)
    {
        if ($field === null){
            $field = $this->schemaInfo()->getPkFiledName();
        }
        $fields = "$type(`{$field}`)";
        $this->fields = $fields;
        $this->limit = 1;
        $res = $this->all(null, true);

        if (!empty($res[0][$fields])){
            return $res[0][$fields];
        }

        return null;
    }
}