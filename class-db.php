<?php /* MÇ - Database Class with prepared statements - Last Modified at 10-08-2020 */
class db {
    private $connection;
    private $querystring;
    private $query_closed = true;
    private $show_errors = true;
    private $log_errors = true;
    private $error_log_path = "./db-errors.log";
    private $log_queries = true;
    private $queries_log_path = "./db-queries.log";
    private $query_count = 0;
    private $transaction_process = false;

    public function __construct($dbhost = 'localhost', $dbuser = 'root', $dbpass = '', $dbname = '', $charset = 'utf8mb4') {
        $this->connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
        if ($this->connection->connect_error) {
            $this->error('Failed to connect to MySQL - ' . $this->connection->connect_error);
        }
        $this->connection->set_charset($charset);
    }

    public function __destruct() {
        $this->close();
    }

    public function query($querystring) {
        if (!$this->query_closed && !$this->transaction_process) {
            $this->query->close();
        }
        if ($this->query = $this->connection->prepare($querystring)) {
            if (func_num_args() > 1) {
                $x = func_get_args();
                $args = array_slice($x, 1);
                $types = '';
                $args_ref = array();
                foreach ($args as $k => &$arg) {
                    if (is_array($args[$k])) {
                        foreach ($args[$k] as $j => &$a) {
                            $types .= $this->_gettype($args[$k][$j]);
                            $args_ref[] = &$a;
                        }
                    } else {
                        $types .= $this->_gettype($args[$k]);
                        $args_ref[] = &$arg;
                    }
                }
                array_unshift($args_ref, $types);
                call_user_func_array(array($this->query, 'bind_param'), $args_ref);
            }
            $this->query->execute();
            if ($this->query->errno) {
                if ($this->transaction_process) {
                    return false;
                } else {
                    $this->error('[PARAMETERS] - ' . $this->query->error . ' | ' . $querystring . " [" . json_encode($args_ref) . "]");
                }
            }
            $this->query_closed = false;
            $this->query_count++;
            if ($this->log_queries) {
                file_put_contents($this->queries_log_path, "[" . date("d-m-Y H:i:s") . " " . date_default_timezone_get() . "] [" . basename($_SERVER["SCRIPT_FILENAME"]) . "] " . $querystring . " [" . (isset($args_ref) ? json_encode($args_ref) : null) . "]\n", FILE_APPEND);
            }
        } else {
            $this->error('[STATEMENT] - ' . $this->connection->error . ' | ' . $querystring);
        }
        return $this;
    }

    public function fetchAll($callback = null) {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break') {
                    break;
                }
            } else {
                $result[] = $r;
            }
        }
        $this->query->close();
        $this->query_closed = true;
        return $result;
    }

    public function fetchAllObject($callback = null) {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            $r = new stdClass();
            foreach ($row as $key => $val) {
                $r->$key = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break') {
                    break;
                }
            } else {
                $result[] = $r;
            }
        }
        $this->query->close();
        $this->query_closed = true;
        return $result;
    }

    public function singleArray() {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            foreach ($row as $key => $val) {
                $result[$key] = $val;
            }
        }
        $this->query->close();
        $this->query_closed = true;
        return $result;
    }

    public function singleObject() {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            foreach ($row as $key => $val) {
                $result[$key] = $val;
            }
        }
        $this->query->close();
        $this->query_closed = true;
        return (object) $result;
    }

    public function ping() {
        if ($this->connection->ping()) {
            return true;
        } else {
            return false;
        }
    }

    public function close() {
        return $this->connection->close();
    }

    public function numRows() {
        $this->query->store_result();
        return $this->query->num_rows;
    }

    public function affectedRows() {
        return $this->query->affected_rows;
    }

    public function lastInsertID() {
        return $this->connection->insert_id;
    }

    public function queryCount() {
        return $this->query_count;
    }

    public function error($error) {
        if ($this->log_errors) {
            file_put_contents($this->error_log_path, "[" . date("d-m-Y H:i:s") . " " . date_default_timezone_get() . "] " . $error . " [" . basename($_SERVER["SCRIPT_FILENAME"]) . "]\n", FILE_APPEND);
        }
        if ($this->show_errors) {
            exit($error);
        }
    }

    private function _gettype($var) {
        if (is_string($var)) {
            return 's';
        }

        if (is_float($var)) {
            return 'd';
        }

        if (is_int($var)) {
            return 'i';
        }

        return 'b';
    }

    public function begin() {
        $this->transaction_process = true;
        return $this->connection->autocommit(false);
    }

    public function commit() {
        $result = $this->connection->commit();
        $this->connection->autocommit(true); //true set as default
        $this->transaction_process = false;
        return $result;
    }

    public function rollback() {
        $result = $this->connection->rollback();
        $this->connection->autocommit(true); //true set as default
        $this->transaction_process = false;
        return $result;
    }
}