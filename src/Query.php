<?php

namespace React\MySQL;

class Query
{
    /**
     * @var string
     */
    private $sql;

    /**
     * helper, to check if the sql-query is build
     *
     * @var null|string
     */
    private $builtSql;

    /**
     * @var array
     */
    private $params = [];

    /**
     * @var array
     */
    private $paramsByNameTmp = [];

    private $escapeChars = [
        "\x00" => "\\0",
        "\r" => "\\r",
        "\n" => "\\n",
        "\t" => "\\t",
        //"\b"   => "\\b",
        //"\x1a" => "\\Z",
        "'" => "\'",
        '"' => '\"',
        "\\" => "\\\\",
        //"%"    => "\\%",
        //"_"    => "\\_",
    ];

    /**
     * Query constructor.
     *
     * @param string $sql
     */
    public function __construct($sql)
    {
        $this->sql = $sql;
        $this->builtSql = $sql;
    }

    /**
     * Binding params for the query, multiple arguments support.
     *
     * @param mixed $param
     *
     * @return $this
     */
    public function bindParams()
    {
        $this->builtSql = null;
        $this->params = \func_get_args();

        return $this;
    }

    /**
     * Binding params for the query, via array as input.
     *
     * @param array $params
     *
     * @return $this
     */
    public function bindParamsFromArray(array $params)
    {
        $this->builtSql = null;
        $this->params = $params;

        return $this;
    }

    /**
     * Binding params for the query, multiple arguments support.
     *
     * @param mixed $param
     *
     * @return $this
     *
     * @deprecated <p>use "bindParams()" or "bindParamsFromArray() instead"</p>
     */
    public function params()
    {
        $this->builtSql = null;
        $this->params = \func_get_args();

        return $this;
    }

    /**
     * @param string $str
     *
     * @return string
     */
    public function escape($str)
    {
        return \strtr($str, $this->escapeChars);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function resolveValueForSql($value)
    {
        $type = \gettype($value);
        switch ($type) {
            case 'boolean':
                $value = (int)$value;
                break;
            case 'double':
            case 'integer':
                break;
            case 'string':
                $value = "'" . $this->escape($value) . "'";
                break;
            case 'array':
                $nvalue = [];
                foreach ($value as $v) {
                    $nvalue[] = $this->resolveValueForSql($v);
                }
                $value = \implode(',', $nvalue);
                break;
            case 'NULL':
                $value = 'NULL';
                break;
            default:
                throw new \InvalidArgumentException(\sprintf('Not supported value type of %s.', $type));
        }

        return $value;
    }

    protected function buildSql()
    {
        $sql = $this->sql;

        if (\count($this->params) > 0) {
            $sql = $this->parseQueryParams($sql);
            $sql = $this->parseQueryParamsByName($sql);
        }

        return $sql;
    }

    /**
     * @param string $sql
     *
     * @return string
     */
    private function parseQueryParams($sql)
    {
        // reset the internal params helper
        $this->paramsByNameTmp = $this->params;

        $params = $this->params;
        $offset = \strpos($sql, '?');

        // is there anything to parse?
        if (
            $offset === false
            ||
            \count($params) === 0
        ) {
            return $sql;
        }

        foreach ($params as $key => $param) {

            if ($offset === false) {
                continue;
            }

            // use this only for not named parameters
            if (!\is_int($key)) {
                continue;
            }
            if (\is_array($param) && \count($param) > 0) {
                foreach ($param as $paramInnerKey => $paramInnerValue) {
                    if (!\is_int($paramInnerKey)) {
                        continue 2;
                    }
                }
            }

            $replacement = $this->resolveValueForSql($param);
            unset($params[$key]);
            $sql = \substr_replace($sql, $replacement, $offset, 1);
            $offset = \strpos($sql, '?', $offset + \strlen((string)$replacement));
        }

        $this->paramsByNameTmp = $params;

        return $sql;
    }

    /**
     * Returns the SQL by replacing :placeholders with SQL-escaped values.
     *
     * @param mixed $sql <p>The SQL string.</p>
     *
     * @return string
     */
    private function parseQueryParamsByName($sql)
    {
        $params = $this->paramsByNameTmp;

        // is there anything to parse?
        if (
            \strpos($sql, ':') === false
            ||
            \count($params) === 0
        ) {
            return $sql;
        }

        foreach ($params as $paramsKey => $paramsInner) {

            // use the key from a named parameter
            if (!\is_int($paramsKey)) {
                $paramsInner = [$paramsKey => $paramsInner];
            }

            $paramsInner = (array)$paramsInner;

            // reset
            $offset = null;
            $replacement = null;
            $name = null;

            foreach ($paramsInner as $name => $param) {
                // use this only for named parameters
                if (\is_int($name)) {
                    continue;
                }

                // add ":" if needed
                if (\strpos($name, ':') !== 0) {
                    $nameTmp = ':' . $name;
                } else {
                    $nameTmp = $name;
                }

                if ($offset === null) {
                    $offset = \strpos($sql, $nameTmp);
                } else {
                    $offset = \strpos($sql, $nameTmp, $offset + \strlen((string)$replacement));
                }

                if ($offset === false) {
                    continue;
                }

                $replacement = $this->resolveValueForSql($param);

                $sql = \substr_replace($sql, $replacement, $offset, \strlen($nameTmp));
            }

            if (
                $offset === false
                &&
                $name !== null
            ) {
                unset($params[$name]);
            }
        }

        return $sql;
    }

    /**
     * Get the constructed and escaped sql string.
     *
     * @return string
     */
    public function getSql()
    {
        if ($this->builtSql === null) {
            $this->builtSql = $this->buildSql();
        }

        return $this->builtSql;
    }
}
