MYSQLUndo
=========

This PHP class is used to make possible to undo unwanted data changes in the MYSQL database.
* Logging must be enabled for the table in order to track changes.
* It class creates triggers to store current state of the inserted/updated/deleted row in the time of change.
* Also a stored procedure for the table is created to make possible to get previous state of the rows before the specified time span defined with start_date and end_date parameters.
