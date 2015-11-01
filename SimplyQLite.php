<?php

/**
 * SimplyQLite is a little library to help you setup your SQLite with prepared statements.
 *
 * Just push the required data into the function, and it works out of the box.
 * If the database doesn't exist, it'll try to create it and also create the necessary columns.
 *
 * This simplified class will always return all values! Therefor, do not make it publicly addressable!
 */
class SimplyQLite extends SQLite3
{
    /**
     * @var string $table The database we're manipulating.
     */
    protected $table;

    /**
     * @var string $keyField The unique constraint field, needed for updates.
     */
    protected $keyField;

    /**
     * @var string $separator The separator we want to use. AND or OR are available.
     */
    protected $separator = 'AND';

    /**
     * @var string $order The column to sort on, and how. For example "id ASC". Don't add the "ORDER BY" part of the string!
     */
    protected $order = false;

    /**
     * a set of array's we're very often need.
     */
    protected $allowed      = array();
    protected $column       = array();
    protected $insert       = array();
    protected $where        = array();
    protected $allResults   = array();
    protected $whereResults = array();


    /**
     * Setup SimplyQLite.
     * Instantiate with $db = new SimplyQLite('path/to/database.db');
     * Also, we setup the columns we have. So we can check if the column exists in future functions.
     *
     * @param string         $db       The location of the databasefile that we want to use
     * @param string         $table    The table we're going to use.
     * @param string|integer $keyField The field which has the unique constraint.
     */
    public function __construct($db, $table, $keyField = 'id')
    {
        parent::__construct($db);
        $this->table = $table;
        $columns     = $this->query("PRAGMA table_info($table)");
        while ($col = $columns->fetchArray()) {
            var_dump($col);
            $this->allowed[$col['name']] = $col['type'];
        }
        $this->keyField = $keyField;
    }

    /**
     * If you want to use an "OR" method, use $SimplyQLite->setSeparator('OR');
     *
     * @param string $separator Set the separator, default is AND
     */
    public function setSeparator($separator)
    {
        $this->separator = $separator;
    }

    /**
     * To change the sortorder, call $SimplyQLite->setOrder('id ASC');
     * Do not add the "ORDER BY" part to this string. This is included in the function already.
     *
     * @param string $order Change the sortorder.
     */
    public function setOrder($order)
    {
        $this->order = ' ORDER BY ' . $order;
    }

    /**
     * Select all the records and return an SQLite3 Statement which we can loop
     *
     * @return SQLite3Result $result Returns an sqlite query-result.
     */
    private function selectAllStmt()
    {
        $query = 'SELECT * FROM ' . $this->table;
        $query .= ($this->order) ?: '';
        $results = $this->query($query);

        return $results;
    }

    /**
     * This sets up the columns we can select from. We check against the available fields.
     * Also, it can NEVER be the keyField!
     *
     * @param array $data Keyfields we want to use.
     * @return array of columns we can select from.
     */
    private function setupColumns($data)
    {
        $columns = array();
        foreach ($data as $key) {
            if ($key !== $this->keyField && array_key_exists($key, $this->allowed)) {
                $columns[] = $key;
            }
        }

        return $columns;
    }

    /**
     * Prepare the WHERE method to a statement-array
     *
     * @param array $where
     * @return array
     */
    private function prepareColumns(array $where)
    {
        $return = array();
        foreach ($where as $key => $value) {
            if (array_key_exists($key, $this->allowed)) {
                $return[] = $key . ' = :' . $key;
            }
        }

        return $return;
    }

    /**
     * Select all records in the database. This is _NOT_ a limited subset of the columns!
     *
     * @param int $mode The mode, these should be SQLITE3Stmt constants
     * @return array with all the results, loopable with a foreach();
     */
    public function selectAll($mode = SQLITE3_BOTH)
    {
        if (!count($this->allResults)) {
            $all = $this->selectAllStmt();
            while ($result = $all->fetchArray($mode)) {
                $this->allResults[] = $result;
            }
        }

        return $this->allResults;
    }

    /**
     * Select a limited resultset, via a where clause
     * Return ALL results! They are NOT limited to a subset!
     *
     * @param array $where an associative array with a key=>value pair, combining the where column to the value.
     * @return array the results to be looped in a foreach.
     */
    public function selectWhere(array $where = array())
    {
        $wherePrepare = $this->prepareColumns($where);

        $prepare = 'SELECT * FROM ' . $this->table . ' WHERE (' . implode(' ' . $this->separator . ' ', $wherePrepare) . ')';
        $prepare .= ($this->order) ?: '';
        $prepared = $this->prepare($prepare);
        foreach ($where as $key => $value) {
            $prepared->bindValue(':' . $key, $value);
        }
        $results = $prepared->execute();
        while ($result = $results->fetchArray()) {
            $this->whereResults[] = $result;
        }

        return $this->whereResults;
    }

    /**
     * Select a limited subset with where and specific columns.
     *
     * @param array $columns
     * @param array $where
     * @return array
     */
    public function selectLimitedWhere(array $columns, array $where = array())
    {
        $wherePrepare = $this->prepareColumns($where);
        $columns      = $this->setupColumns($columns);
        $stmt         = "SELECT ".implode(',',$columns)." FROM $this->table";
        if(count($where)) {
            $stmt .= "WHERE " . implode(',', $wherePrepare);
        }

        $stmt .= ($this->order) ?: '';
        $prepared = $this->prepare($stmt);
        foreach ($where as $key => $value) {
            $prepared->bindValue(':' . $key, $value);
        }
        $results = $prepared->execute();
        while ($result = $results->fetchArray()) {
            $this->whereResults[] = $result;
        }

        return $this->whereResults;
    }

    /**
     *
     * @param array $data A set of key => value pairs we want to insert.
     * @return SQLite3Result
     */
    public function insertRow($data)
    {
        $column  = $this->setupColumns(array_keys($data));
        $prepare = 'INSERT INTO ' . $this->table . ' (' . implode(',', $column) . ') VALUES (:' . implode(',:', $column) . ')';
        $prepare .= ($this->order) ?: '';
        $statement = $this->prepare($prepare);
        foreach ($column as $key) {
            $statement->bindValue(':' . $key, $data[$key]);
        }
        $result = $statement->execute();

        return $result;
    }

    /**
     * Update a row in the database. Only works via the unique key set in the construct!
     *
     * @param array          $data a key => value pair of what we want to update
     * @param string|integer $id   The unique constraint we want to update. This is ALWAYS by the set keyField!
     * @return SQLite3Result Result of the statement.
     */
    public function update($data, $id)
    {
        $column    = $this->setupColumns(array_keys($data));
        $setValues = array();
        foreach ($data as $key => $value) {
            if ($key !== $this->keyField && in_array($key, $this->allowed, true)) {
                $setValues[] = $key . ' = :' . $key;
            }
        }
        $query     = 'UPDATE ' . $this->table . ' SET ' . implode(',', $setValues) . ' WHERE ' . $this->keyField . ' = :id';
        $statement = $this->prepare($query);
        foreach ($column as $key) {
            $statement->bindValue(':' . $key, $data[$key]);
        }
        $statement->bindValue(':id', $id);

        return $statement->execute();
    }

    /**
     * Delete a row from the database. This only works on the unique key.
     *
     * @param string|integer $id The unique constraint we want to delete
     * @return SQLite3Result Result of the delete action.
     */
    public function delete($id)
    {
        $statement = $this->prepare('DELETE FROM ' . $this->table . ' WHERE (' . $this->keyField . ' = :id');
        $statement->bindValue(':id', $id);

        return $statement->execute();
    }
}
