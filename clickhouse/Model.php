<?php
/**
 * Created by PhpStorm.
 * User: suport
 * Date: 24.11.2017
 * Time: 15:49
 */

namespace App\Containers\Clickhouse;

use App\Containers\Clickhouse\Concerns\HasRelationships;
use App\Containers\Clickhouse\Relations\Relation;
use ClickHouseDB\Client;
use Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Concerns\HidesAttributes;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use LogicException;

class Model
{
    use HasAttributes,
        HidesAttributes,
        GuardsAttributes,
        HasRelationships;

    protected $db;
    public $table;
    protected $final = false;
    protected $perPage;

    protected $scopesForRelations = [];

    const CREATED_AT = null;
    const UPDATED_AT = null;

    public function __construct(array $attributes = [])
    {
        $this->syncOriginal();

        $this->fill($attributes);
    }

    public static function __callStatic($method, $parameters)
    {
        return (new static)->newQuery()->$method(...$parameters);
    }

    public function __call($method, $parameters)
    {
        return $this->newQuery()->$method(...$parameters);
    }

    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    public function __isset($key)
    {
        return $this->offsetExists($key);
    }

    public function __unset($key)
    {
        $this->offsetUnset($key);
    }

    public function offsetExists($offset)
    {
        return ! is_null($this->getAttribute($offset));
    }

    public function offsetUnset($offset)
    {
        unset($this->attributes[$offset], $this->relations[$offset]);
    }

    public static function query()
    {
        return (new static)->newQuery();
    }

    public static function insert(array $columns, array $values)
    {
        return (new static)->newQuery()->columns($columns)->insert($values);
    }

    public static function raw($sql)
    {
        $db = (new static)->newConnection();
        return $db->write($sql);
    }

    public static function ping()
    {
        return (new static())->newConnection()->ping();
    }

    protected function newQuery()
    {
        $builder = $this->newClickhouseBuilder($this->newQueryBuilder());
        return $builder->setModel($this);
    }

    protected function newQueryBuilder()
    {
        $connection = $this->newConnection();
        return new QueryBuilder($connection);
    }

    protected function newClickhouseBuilder($query)
    {
        return new Builder($query);
    }

    protected function newConnection()
    {
        $config = App::make('config')->get('clickhouse');
        $database = $config['database'];
        unset($config['database']);
        $client = new Client($config);
        $client->database($database);
        return $client;
    }

    public function getPerPage()
    {
        return $this->perPage;
    }

    public function setPerPage($perPage)
    {
        $this->perPage = $perPage;

        return $this;
    }

    public function newCollection(array $models = [])
    {
        return new Collection($models);
    }

    public function scopeApplyFilters($query, $filters, $filtrable)
    {
        $query = $this->applyFilterVariables($query, $filters, $filtrable);
        $query = $this->applyNamedFilters($query, $filters);

        return $query;
    }

    public function newInstance($attributes = [], $exists = false)
    {
        $model = new static((array) $attributes);

        $model->exists = $exists;

        $model->scopesForRelations = $this->scopesForRelations;

        return $model;
    }

    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();

        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            $key = $this->removeTableFromKey($key);

            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded) {
                throw new MassAssignmentException($key);
            }
        }

        return $this;
    }

    protected function removeTableFromKey($key)
    {
        return Str::contains($key, '.') ? last(explode('.', $key)) : $key;
    }

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = $this->newInstance([], true);

        $model->setRawAttributes((array) $attributes, true);

        return $model;
    }

    public function getIncrementing()
    {
        return false;
    }

    public function usesTimestamps()
    {
        return false;
    }

    public function toArray()
    {
        return $this->attributesToArray();
    }

    public function getScopesForRelations()
    {
        return $this->scopesForRelations;
    }

    public function addScopeForRelations($scope, $parameters)
    {
        $scope = lcfirst(str_replace_first('scope', '', $scope));
        $this->scopesForRelations[$scope] = $parameters;

        return $this;
    }

    public function getRelationshipFromMethod($method)
    {
        $relation = $this->$method($this->scopesForRelations);

        if (! $relation instanceof Relation) {
            throw new LogicException(get_class($this).'::'.$method.' must return a relationship instance.');
        }

        return tap($relation->getResults(), function ($results) use ($method) {
            $this->setRelation($method, $results);
        });
    }

    protected function getRelatedCollection($model, $scopes, $foreignKey, $localKey)
    {
        $query = $model::query();

        foreach($scopes as $name => $parameters) {
            if (method_exists($query->getModel(), 'scope'.ucfirst($name))) {
                $query = $query->$name(...array_values($parameters));
            }
        }

        $query = $query->where($foreignKey, $this->$localKey);

        return $query->get();
    }

    /**
     * Применить фильтры которые содержатся в GET строке
     * и указаны в параметре filtrable контроллера
     */
    protected function applyFilterVariables($query, $filters, $filtrable)
    {
        foreach ($filtrable as $key => $value) {

            // если ключ не цифра, значит это фильтр с дефолтовым значением
            if (!is_numeric($key)) {
                $filterName = $key;
                $filterValue = $value;

                // а если цифра, значит это просто поле
                // по которому значение может прийти, а может и не прийти
            } else {
                $filterName = $value;
                $filterValue = null;
            }

            // смотрим, пришло ли значение фильтрации
            if (isset($filters[$filterName])) {
                $filterValue = $filters[$filterName];
            }

            // если есть значение (из запроса либо дефолтовое), то накладываем scope
            if ($filterValue) {
                $scopeName = camel_case($filterName);
                $query = $query->$scopeName($filterValue);
            }
        }

        return $query;
    }

    /**
     * Применить фильтр который содержится в GET параметре filter
     * @todo проверить существование запрошенного scope
     */
    protected function applyNamedFilters($query, $filters)
    {
        $scopeNames = explode(',', $filters['filter']);

        foreach ($scopeNames as $scopeName) {
            if ($scopeName && $scopeName != 'none') {
                $scopeName = camel_case($scopeName);

                $scopeFunctionName = 'scope'.ucfirst($scopeName);
                if (method_exists($this, $scopeFunctionName)) {
                    $query = $query->$scopeName();
                }
            }
        }

        return $query;
    }

    public function isFinal()
    {
        return $this->final;
    }
}