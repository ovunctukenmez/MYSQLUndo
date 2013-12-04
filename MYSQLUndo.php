<?php
/**
* @author Ovunc Tukenmez <ovunct@live.com>
*
* This class is used to make possible to undo unwanted data changes in the MYSQL database.
* Logging must be enabled for the table in order to track changes.
* It class creates triggers to store current state of the inserted/updated/deleted row in the time of change.
* Also a stored procedure for the table is created to make possible to get previous state of the rows before the specified time span defined with start_date and end_date parameters.
*/
class MYSQLUndo{
    private $_conn;
    private $_db_host;
    private $_db_name;
    private $_db_username;
    private $_db_password;
    private $_driver_options;
    private $_last_error_msg;
    /**
    * @param $db_host database host
    * @param $db_name database name
    * @param $db_username database user name
    * @param $db_password database user password
    * @param array $driver_options PDO driver options
    */
    public function __construct($db_host, $db_name, $db_username, $db_password, $driver_options = array()){
        $this->_is_mysql_version_older_than_5_0_10 = false;
        $this->setConnectionDetails($db_host, $db_name, $db_username, $db_password, $driver_options);
    }

    /**
    * Sets connection details
    * @param $db_host database host
    * @param $db_name database name
    * @param $db_username database user name
    * @param $db_password database user password
    * @param array $driver_options PDO driver options
    */
    public function setConnectionDetails($db_host, $db_name, $db_username, $db_password, $driver_options = array()){
        $this->_db_host = $db_host;
        $this->_db_name = $db_name;
        $this->_db_username = $db_username;
        $this->_db_password = $db_password;
        $this->_driver_options = $driver_options;
        $this->_conn = null;
    }

    private function getDBConn(){
        if (!is_a($this->_conn, 'PDO')){
            $dsn = 'mysql:dbname=' . $this->_db_name . ';host=' . $this->_db_host;

            try {
                $this->_conn = new PDO($dsn, $this->_db_username, $this->_db_password, $this->_driver_options);
                return $this->_conn;
            } catch (PDOException $e) {
                echo 'Connection failed: ' . $e->getMessage();
                return false;
            }
        }
        else{
            return $this->_conn;
        }
    }

    public function getLastErrorMessage(){
        return $this->_last_error_msg;
    }

    /**
    * calls stored procedure to undo data changes
    *
    * @param $db_table database table name
    * @param string $start_date the start date (UTC) for the changes to be reverted. Expected format: "Y-m-d H:i:s". If null value is passed, all the records in the log table before the $end_date parameter will be processed.
    * @param string $end_date the end date (UTC) for the changes to be reverted. Expected format: "Y-m-d H:i:s". If null value is passed, all the records in the log table after the $start_date parameter will be processed.
    * @param $revert_inserted if the inserted rows should be reverted or not
    * @param $revert_updated if the updated rows should be reverted or not
    * @param $revert_deleted if the deleted rows should be reverted or not
    */
    public function revertChanges($db_table, $start_date = null, $end_date = null, $revert_inserted = true, $revert_updated = true, $revert_deleted = true){
        $stored_procedure_name = '__UndoDataChanges_' . $db_table;

        $stmt = $this->getDBConn()->prepare("CALL {$stored_procedure_name}(:start_date,:end_date,:include_insert,:include_update,:include_delete)");

        $stmt->bindParam(':start_date', $start_date, PDO::PARAM_STR);
        $stmt->bindParam(':end_date', $end_date, PDO::PARAM_STR);
        $stmt->bindParam(':include_insert', $revert_inserted, PDO::PARAM_BOOL);
        $stmt->bindParam(':include_update', $revert_updated, PDO::PARAM_BOOL);
        $stmt->bindParam(':include_delete', $revert_deleted, PDO::PARAM_BOOL);

        $result = $stmt->execute();
        if ($result === false){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }
        return true;
    }

    /**
    * enables logging on the specified database table
    * it performs the following operations:
    * - it deletes log table.
    * - it deletes update, delete and insert triggers
    * - it deletes stored procedure from the table (which is used for undo operations)
    *
    * @param $db_table database table name
    */
    public function disableLogging($db_table){
        $log_table = $db_table . '_log';
        $stored_procedure_name = '__UndoDataChanges_' . $db_table;

        $q =<<<EOF
DROP PROCEDURE IF EXISTS $stored_procedure_name;
DROP TRIGGER IF EXISTS {$db_table}_insert_trigger;
DROP TRIGGER IF EXISTS {$db_table}_update_trigger;
DROP TRIGGER IF EXISTS {$db_table}_delete_trigger;
DROP TABLE IF EXISTS $log_table;
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
            return true;
        }
    }

    /**
    * enables logging on the specified database table
    * it performs the following operations:
    * - it creates log table.
    * - it creates update, delete and insert triggers
    * - it creates stored procedure for the table (which is used for undo operations)
    *
    * @param $db_table database table name
    */
    public function enableLogging($db_table){
        $result = true;

        $table_columns = $this->getDBTableColumns($db_table);

        $primary_column_name = '';
        $column_names = array();

        foreach($table_columns as $column){
            $column_names[] = $column['Field'];
            if ($column['Key'] == 'PRI'){
                $primary_column_name = $column['Field'];
            }
        }

        if ($primary_column_name == ''){
            $this->_last_error_msg = "primary column doesn't exist on table $db_table";
            return false;
        }

        if (count(array_intersect($column_names, array('__t', '__is_inserted', '__is_updated', '__is_deleted'))) > 0){
            $this->_last_error_msg = "can't enable logging for the table $db_table. (reserved column names exist)";
            return false;
        }

        $result = $this->createLogTable($db_table, $primary_column_name);

        if (!$result) { return false; }

        $result = $this->createInsertTrigger($db_table, $column_names);

        if (!$result) { return false; }

        $result = $this->createUpdateTrigger($db_table, $column_names);

        if (!$result) { return false; }

        $result = $this->createDeleteTrigger($db_table, $column_names);

        if (!$result) { return false; }

        $result = $this->createUndoDataChangesStoredProcedureTrigger($db_table, $column_names, $primary_column_name);

        return $result;
    }

    /**
    * creates log table if not exists
    * it performs the following operations:
    * - it creates log table to store previous state of the record after the change is made to the record.
    *
    * @param $db_table database table name
    * @param $primary_column_name table's primary column name
    */
    private function createLogTable($db_table, $primary_column_name){
        $log_table = $db_table . '_log';

        $result_array = array();

        $stmt = $this->getDBConn()->query("SHOW TABLES LIKE '" . $log_table . "'");

        if ($stmt)
        {
            $result_array = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (count($result_array) == 0){
            $q =<<<EOF
CREATE TABLE $log_table SELECT * FROM $db_table WHERE 1=0;
ALTER TABLE $log_table ADD __t DATETIME;
ALTER TABLE $log_table ADD __is_inserted BIT NOT NULL DEFAULT 0;
ALTER TABLE $log_table ADD __is_updated BIT NOT NULL DEFAULT 0;
ALTER TABLE $log_table ADD __is_deleted BIT NOT NULL DEFAULT 0;
ALTER TABLE $log_table ADD KEY($primary_column_name);
ALTER TABLE $log_table ADD KEY(__t);
ALTER TABLE $log_table ADD KEY(__is_inserted, __is_updated, __is_deleted);
ALTER TABLE $log_table ADD __id INT UNSIGNED NOT NULL auto_increment PRIMARY KEY;
EOF;
            $stmt = $this->getDBConn()->query($q);
            if (!$stmt){
                $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
                return false;
            }
            else{
                $stmt->closeCursor();
            }
        }
        else{
            // log table already exists
        }

        return true;
    }

    /**
    * creates insert trigger on the specified table
    *
    * @param $db_table database table name
    * @param array $column_names column names of the database table
    */
    private function createInsertTrigger($db_table, $column_names){
        $log_table = $db_table . '_log';

        $str_left_columns = implode(',', $column_names);
        $str_right_columns = "NEW." . implode(',NEW.', $column_names);

        $q =<<<EOF
DROP TRIGGER IF EXISTS {$db_table}_insert_trigger;
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        $q =<<<EOF
CREATE TRIGGER {$db_table}_insert_trigger BEFORE INSERT ON $db_table
FOR EACH ROW
BEGIN
INSERT INTO {$log_table}($str_left_columns, __is_inserted, __t) VALUES($str_right_columns, 1, UTC_TIMESTAMP());
END
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        return true;
    }

    /**
    * creates update trigger on the specified table
    *
    * @param $db_table database table name
    * @param array $column_names column names of the database table
    */
    private function createUpdateTrigger($db_table, $column_names){
        $log_table = $db_table . '_log';

        $str_left_columns = implode(',', $column_names);
        $str_right_columns = "OLD." . implode(',OLD.', $column_names);

        $q =<<<EOF
DROP TRIGGER IF EXISTS {$db_table}_update_trigger;
END
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        $q =<<<EOF
CREATE TRIGGER {$db_table}_update_trigger BEFORE UPDATE ON $db_table
FOR EACH ROW
BEGIN
INSERT INTO {$log_table}($str_left_columns, __is_updated, __t) VALUES($str_right_columns, 1, UTC_TIMESTAMP());
END
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        return true;
    }

    /**
    * creates delete trigger on the specified table
    *
    * @param $db_table database table name
    * @param array $column_names column names of the database table
    */
    private function createDeleteTrigger($db_table, $column_names){
        $log_table = $db_table . '_log';

        $str_left_columns = implode(',', $column_names);
        $str_right_columns = "OLD." . implode(',OLD.', $column_names);

        $q =<<<EOF
DROP TRIGGER IF EXISTS {$db_table}_delete_trigger;
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        $q =<<<EOF
CREATE TRIGGER {$db_table}_delete_trigger BEFORE DELETE ON $db_table
FOR EACH ROW
BEGIN
INSERT INTO {$log_table}($str_left_columns, __is_deleted, __t) VALUES($str_right_columns, 1, UTC_TIMESTAMP());
END
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        return true;
    }

    private function getDBTableColumns($db_table){
        $result_array = array();
        $stmt = $this->getDBConn()->query('SHOW COLUMNS FROM ' . $db_table);

        if ($stmt)
        {
            $result_array = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        }

        return $result_array;
    }

    /**
    * creates stored procedure that is used by the revert operation
    *
    * @param $db_table database table name
    * @param array $column_names column names of the database table
    * @param $primary_column_name table's primary column name
    */
    private function createUndoDataChangesStoredProcedureTrigger($db_table, $column_names, $primary_column_name){
        $log_table = $db_table . '_log';
        $temporary_log_table = '_' . $log_table . '_temp';
        $stored_procedure_name = '__UndoDataChanges_' . $db_table;

        $str_comma_seperated_column_names = implode(',', $column_names);
        $str_update_columns = '';

        foreach ($column_names as $column_name){
            if ($column_name == $primary_column_name){ continue; }

            $str_update_columns .= (strlen($str_update_columns) == 0 ? '' : ', ') . 't1.' . $column_name . ' = t2.' . $column_name;
        }

        $q =<<<EOF
DROP PROCEDURE IF EXISTS $stored_procedure_name;
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        $q =<<<EOF
CREATE PROCEDURE $stored_procedure_name(IN start_date DATETIME, IN end_date DATETIME, IN include_insert BIT, IN include_update BIT, IN include_delete BIT)
    BEGIN
        DECLARE is_finished bit default 0;
        DECLARE c_record_id int default 0;
        DECLARE c_is_inserted bit default 0;
        DECLARE c___id int default 0;
        DECLARE _cursor CURSOR FOR SELECT $primary_column_name, __is_inserted, __id FROM $temporary_log_table;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET is_finished = 1;

        SET @include_insert = include_insert;
        SET @include_update = include_update;
        SET @include_delete = include_delete;
        SET @start_date = start_date;
        SET @end_date = end_date;

        CREATE TEMPORARY TABLE $temporary_log_table LIKE $log_table;
        INSERT INTO $temporary_log_table
        SELECT
        *
        FROM $log_table t1
        WHERE
        ((@include_insert = 0 AND t1.__is_inserted = 0) OR @include_insert <> 0)
        AND ((@include_update = 0 AND t1.__is_updated = 0) OR @include_update <> 0)
        AND ((@include_delete = 0 AND t1.__is_deleted = 0) OR @include_delete <> 0)
        AND (
            (@start_date IS NOT NULL AND @end_date IS NOT NULL AND t1.__t >= @start_date AND t1.__t <= @end_date)
            OR
            (@start_date IS NOT NULL AND @end_date IS NULL AND t1.__t >= @start_date)
            OR
            (@start_date IS NULL AND @end_date IS NOT NULL AND t1.__t <= @end_date)
            OR
            (@start_date IS NULL AND @end_date IS NULL)
        )
        GROUP BY t1.$primary_column_name;

        OPEN _cursor;

        _loop: LOOP
            FETCH _cursor INTO c_record_id, c_is_inserted, c___id;

            IF is_finished = 1 THEN
                LEAVE _loop;
            END IF;

            SET @is_inserted = c_is_inserted;
            SET @__id = c___id;
            SET @record_id = c_record_id;

            IF @is_inserted = 1 THEN
                DELETE FROM $db_table WHERE $primary_column_name = @record_id;
            ELSE
                SET @row_exists = 0;

                SELECT
                1
                INTO @row_exists
                FROM $db_table
                WHERE
                $primary_column_name = @record_id;

                IF @row_exists = 1 THEN
                    UPDATE $db_table t1, $temporary_log_table t2
                    SET
                    $str_update_columns
                    WHERE
                    t1.$primary_column_name = @record_id
                    AND t2.__id = @__id;
                ELSE
                    INSERT INTO $db_table
                    (
                    $str_comma_seperated_column_names
                    )
                    SELECT
                    $str_comma_seperated_column_names
                    FROM $temporary_log_table
                    WHERE
                    __id = @__id;
                END IF;
            END IF;

        END LOOP _loop;

        CLOSE _cursor;

        DROP TABLE $temporary_log_table;

    END
EOF;
        $stmt = $this->getDBConn()->query($q);
        if (!$stmt){
            $this->_last_error_msg = implode(' ', $this->getDBConn()->errorInfo());
            return false;
        }
        else{
            $stmt->closeCursor();
        }

        return true;
    }
}
?>