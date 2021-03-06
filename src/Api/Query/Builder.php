<?php namespace Phonedotcom\Sdk\Api\Query;

use Phonedotcom\Sdk\Api\Client;

class Builder
{
    /**
     * @var Client
     */
    protected $client;

    public $from;

    public $wheres;

    public $orders;

    public $limit;

    public $offset;

    protected $operators = [
        // zero-argument
        'empty', 'not-empty',

        // one-argument
        'eq', 'ne', 'lt', 'gt', 'lte', 'gte',
        'starts-with', 'ends-with', 'contains', 'not-starts-with', 'not-ends-with', 'not-contains',

        // two-argument
        'between', 'not-between'
    ];

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function select()
    {
        return $this;
    }

    public function addSelect($column)
    {
        $column = is_array($column) ? $column : func_get_args();

        $this->columns = array_merge((array) $this->columns, $column);

        return $this;
    }

    public function from($pathInfo, array $params = [])
    {
        $this->from = [$pathInfo, $params];

        return $this;
    }

    public function where($column, $operator = null, $value = null)
    {
        if (is_array($column)) {
            foreach ($column as $key => $value) {
                $this->where($key, 'eq', $value);
            }
            return;
        }

        $zeroParameterOperators = ['empty', 'not-empty'];

        if (func_num_args() == 2) {
            if (!in_array($operator, $zeroParameterOperators)) {
                $value = $operator;
                $operator = 'eq';
            }

        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        $this->wheres[] = compact('column', 'operator', 'value');

        return $this;
    }

    public function whereEmpty($column)
    {
        return $this->where($column, 'empty');
    }

    public function whereNotEmpty($column)
    {
        return $this->where($column, 'not-empty');
    }

    public function whereIn($column, $values)
    {
        return $this->where($column, 'in', $values);
    }

    public function whereNotIn($column, $values)
    {
        return $this->where($column, 'not-in', $values);
    }

    public function getCountForPagination()
    {
        return $this->count();
    }

    protected function invalidOperatorAndValue($operator, $value)
    {
        $isOperator = in_array($operator, $this->operators);

        return $isOperator && $operator != 'eq' && is_null($value);
    }

    public function whereBetween($column, array $values, $not = false)
    {
        return $this->where($column, 'between', $values);
    }

    public function whereNotBetween($column, array $values)
    {
        return $this->where($column, 'not-between', $values);
    }

    public function orderBy($column, $direction = 'asc')
    {
        $direction = strtolower($direction) == 'asc' ? 'asc' : 'desc';

        $this->orders[] = compact('column', 'direction');

        return $this;
    }

    public function offset($value)
    {
        $this->offset = max(0, $value);

        return $this;
    }

    public function skip($value)
    {
        return $this->offset($value);
    }

    public function limit($value)
    {
        if ($value > 0) {
            $this->limit = $value;
        }

        return $this;
    }

    public function take($value)
    {
        return $this->limit($value);
    }

    public function forPage($page, $perPage = 15)
    {
        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }

    public function find($id)
    {
        return $this->where('id', 'eq', $id)->first();
    }

    public function first()
    {
        $results = $this->take(1)->get();

        return count($results) > 0 ? reset($results) : null;
    }

    public function get()
    {
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;
        $this->offset = ($originalOffset ?: 0);
        $this->limit = ($originalLimit ?: 100);

        $items = [];
        do {
            $data = $this->runSelect();
            $items = array_merge($items, $data->items);
            $this->offset += $this->limit;
        } while (count($data->items) == $this->limit && !$originalLimit);

        $this->limit = $originalLimit;
        $this->offset = $originalOffset;

        return $items;
    }

    public function getWithTotal()
    {
        $result = $this->runSelect();

        return [(array)$result->items, (int)$result->total];
    }

    protected function runSelect()
    {
        $url = $this->compileUrl($this->from[0], @$this->from[1]);

        $options = ['query' => []];

        foreach (['wheres', 'orders', 'limit', 'offset'] as $component) {
            $method = 'compile' . ucfirst($component);
            $this->$method($options['query']);
        }

        return $this->client->select($url, $options);
    }

    public function compileUrl($pathInfo, $params = [])
    {
        $path = $pathInfo;
        if (is_array($params)) {
            foreach ($params as $param => $value) {
                $path = preg_replace("/\{" . preg_quote($param) . "(\:[^\}]+)?\}/", (string)$value, $path);
            }
        }

        return $path;
    }

    protected function compileWheres(array &$options)
    {
        if (!is_null($this->wheres)) {
            $options['filters'] = [];

            foreach ($this->wheres as $where) {
                $string = $where['operator'];

                $value = $where['value'];
                if ($value !== null) {
                    $string .= ':' . (is_scalar($value) ? $value : join(',', $value));
                }

                $options['filters'][$where['column']][] = $string;
            }
        }
    }

    protected function compileOrders(array &$options)
    {
        if (!is_null($this->orders)) {
            $options['sort'] = [];

            foreach ($this->orders as $order) {
                $options['sort'][$order['column']] = $order['direction'];
            }
        }
    }

    protected function compileLimit(array &$options)
    {
        if ($this->limit !== null) {
            $options['limit'] = (int)$this->limit;
        }
    }

    protected function compileOffset(array &$options)
    {
        if ($this->offset !== null) {
            $options['offset'] = (int)$this->offset;
        }
    }

    public function chunk($count, callable $callback)
    {
        $this->limit($count);
        $offset = 0;
        do {
            $results = $this->offset($offset)->get();
            $returnValue = call_user_func($callback, $results);
            $offset += $count;
        } while (count($results) == $count && $returnValue !== false);
    }

    public function exists()
    {
        $limit = $this->limit;

        $result = ($this->limit(1)->count() > 0);

        $this->limit($limit);

        return $result;
    }

    public function count()
    {
        $result = $this->limit(1)->runSelect();

        return (int)$result->total;
    }

    public function insert(array $values)
    {
        if (empty($values)) {
            return [];
        }

        if (!is_array(reset($values))) {
            $values = [$values];

        } else {
            foreach ($values as $key => $value) {
                ksort($value);
                $values[$key] = $value;
            }
        }

        $responses = [];
        foreach ($values as $row) {
            $url = $this->compileUrl($this->from[0], @$this->from[1]);
            $options = ['json' => $row];

            $responses[] = $this->client->insert($url, $options);
        }

        return $responses;
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array   $values
     * @param  string  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $url = $this->compileUrl($this->from[0], @$this->from[1]);
        $options = ['json' => $values];

        $data = $this->client->insert($url, $options);

        $sequence || $sequence = 'id';

        $id = $data->{$sequence};

        return is_numeric($id) ? (int) $id : $id;
    }

    public function insertCollection(array $values)
    {
        $url = $this->compileUrl($this->from[0], @$this->from[1]);
        $options = ['json' => $values];

        $data = $this->client->insert($url, $options);

        return $data->items;
    }

    public function update(array $values)
    {
        $chunkSize = 50;

        $objects = [];
        $this->chunk($chunkSize, function ($rows) use ($values, &$objects) {
            foreach ($rows as $existing) {
                $url = $existing->{'@controls'}->self->href;

                $newValues = $existing;
                $this->removeMasonPropertiesFromObject($newValues);

                $options = ['json' => array_merge((array)$newValues, $values)];

                $objects[] = $this->client->update($url, $options);
            }
        });

        return $objects;
    }

    public function delete($id = null)
    {
        if (!is_null($id)) {
            $this->where('id', 'eq', $id);
        }

        $chunkSize = 50;

        $this->chunk($chunkSize, function ($rows) {
            foreach ($rows as $existing) {
                $url = $existing->{'@controls'}->self->href;
                $this->client->delete($url, []);
            }
        });

        return true;
    }

    public function newQuery()
    {
        return new static($this->client);
    }

    public function getClient()
    {
        return $this->client;
    }

    private function removeMasonPropertiesFromArray(array &$value)
    {
        foreach ($value as $index => $subvalue) {
            if (is_array($subvalue)) {
                $this->removeMasonPropertiesFromArray($subvalue);

            } elseif (is_object($subvalue)) {
                $this->removeMasonPropertiesFromObject($subvalue);
            }
        }
    }

    private function removeMasonPropertiesFromObject(\stdClass $data)
    {
        foreach ($data as $property => $value) {
            if (substr($property, 0, 1) == '@') {
                unset($data->{$property});
                continue;
            }

            if (is_array($value)) {
                $this->removeMasonPropertiesFromArray($value);

            } elseif (is_object($value)) {
                $this->removeMasonPropertiesFromObject($value);
            }
        }

        return $data;
    }
}
