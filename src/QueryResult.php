<?php

namespace React\MySQL;

class QueryResult
{
    /**
     * last inserted ID (if any)
     * @var int|null
     */
    public $insertId;

    /**
     * number of affected rows (for UPDATE, DELETE etc.)
     *
     * @var int|null
     */
    public $affectedRows;

    /**
     * result set fields (if any)
     *
     * @var array|null
     */
    public $resultFields;

    /**
     * result set rows (if any)
     *
     * @var array|null
     */
    public $resultRows;

    /**
     * number of warnings (if any)
     * @var int|null
     */
    public $warningCount;
}
