<?php

namespace React\MySQL;

class Query
{
    private $sql;

    private $builtSql;

    private $params = [];

    private $escapeChars = array(
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
    );

    public function __construct($sql)
    {
        $this->sql = $this->builtSql = $sql;
    }

    /**
     * Binding params for the query, multiple arguments support.
     *
     * @param  mixed $param
     * @return \React\MySQL\Query
     */
    public function bindParams()
    {
        $this->builtSql = null;
        $this->params = func_get_args();

        return $this;
    }

    public function bindParamsFromArray(array $params)
    {
        $this->builtSql = null;
        $this->params = $params;

        return $this;
    }

    /**
     * Binding params for the query, multiple arguments support.
     *
     * @param  mixed $param
     * @return \React\MySQL\Query
     * @deprecated
     */
    public function params()
    {
        $this->params = func_get_args();
        $this->builtSql = null;

        return $this;
    }

    public function escape($str)
    {
        return strtr($str, $this->escapeChars);
    }

    /**
     * @param  mixed $value
     * @return string
     */
    protected function resolveValueForSql($value)
    {
        $type = gettype($value);
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
                $value = implode(',', $nvalue);
                break;
            case 'NULL':
                $value = 'NULL';
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Not supported value type of %s.', $type));
        }

        return $value;
    }

    protected function buildSql()
    {
        $sql = $this->sql;

        if (\count($this->params) > 0) {
            $parseQueryParams = $this->_parseQueryParams($sql, $this->params);
            $parseQueryParamsByName = $this->_parseQueryParamsByName($parseQueryParams['sql'], $parseQueryParams['params']);
            $sql = $parseQueryParamsByName['sql'];
        }

        return $sql;
    }

    /**
     * @param string $sql
     * @param array $params
     *
     * @return array <p>with the keys -> 'sql', 'params'</p>
     */
    private function _parseQueryParams($sql, array $params = [])
    {
        $offset = \strpos($sql, '?');

        // is there anything to parse?
        if (
            $offset === false
            ||
            \count($params) === 0
        ) {
            return ['sql' => $sql, 'params' => $params];
        }

        foreach ($params as $key => $param) {
            // use this only for not named parameters
            if (!is_int($key)) {
                continue;
            }
            if (is_array($param) && count($param) > 0) {
                foreach ($param as $paramInnerKey => $paramInnerValue) {
                    if (!is_int($paramInnerKey)) {
                        continue 2;
                    }
                }
            }

            if ($offset === false) {
                continue;
            }

            $replacement = $this->resolveValueForSql($param);
            unset($params[$key]);
            $sql = \substr_replace($sql, $replacement, $offset, 1);
            $offset = \strpos($sql, '?', $offset + \strlen((string)$replacement));
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Returns the SQL by replacing :placeholders with SQL-escaped values.
     *
     * @param mixed $sql <p>The SQL string.</p>
     * @param array $params <p>An array of key-value bindings.</p>
     *
     * @return array <p>with the keys -> 'sql', 'params'</p>
     */
    public function _parseQueryParamsByName($sql, array $params = [])
    {
        // is there anything to parse?
        if (
            \strpos($sql, ':') === false
            ||
            \count($params) === 0
        ) {
            return ['sql' => $sql, 'params' => $params];
        }

        foreach ($params as $paramsInner) {

            $offset = null;
            $replacement = null;
            foreach ($paramsInner as $name => $param) {
                // use this only for named parameters
                if (is_int($name)) {
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

            if ($offset === false) {
                unset($params[$name]);
            }
        }

        return ['sql' => $sql, 'params' => $params];
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
