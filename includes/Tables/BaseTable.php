<?php

namespace WPE\AtlasContentModeler\ContentConnect\Tables;

abstract class BaseTable {

	public $columns          = array();
	public $keys             = array();
	public $primary_key_name = null;
	public $unique_key_name  = null;
	public $did_schema       = false;
	public $bulk_updater     = null;

	public $inserted = 0;
	public $updated  = 0;
	public $deleted  = 0;

	public function setup() {
		add_action( 'init', [ $this, 'upgrade' ] );
	}

	/**
	 * @return string Version string for table x.x.x
	 */
	abstract function get_schema_version();

	/**
	 * @return string SQL statement to create the table
	 */
	abstract function get_schema();

	/**
	 * @return string table name of the table we're creating
	 */
	abstract function get_table_name();

	function generate_table_name( $table_name ) {
		$db = $this->get_db();
		$prefix = $db->prefix;

		return $prefix . $table_name;
	}

	function get_installed_schema_version() {
		return get_option( $this->get_schema_option_name() );
	}

	function get_schema_option_name() {
		return $this->get_table_name() . '_schema_version';
	}

	function should_upgrade() {
		return version_compare(
			$this->get_schema_version(),
			$this->get_installed_schema_version(),
			'>'
		);
	}

	function upgrade( $fresh = false ) {
		if ( $this->should_upgrade() || $fresh ) {
			$sql = $this->get_schema();

			require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
			dbDelta( $sql );

			update_option(
				$this->get_schema_option_name(),
				$this->get_schema_version(),
				"no"
			);

			return true;
		} else {
			return false;
		}
	}

	public function get_db() {
		global $wpdb;

		return $wpdb;
	}


	/*
	 * Database Methods
	 */

	public function replace( $data, $format = array() ) {
		$db = $this->get_db();

		$db->replace( $this->get_table_name(), $data, $format );
	}


	/**
	 * Bulk replaces records in the database
	 *
	 *       INSERT into `table` (id,fruit)
	 *			VALUES (1,'apple'), (2,'orange'), (3,'peach')
	 *			ON DUPLICATE KEY UPDATE fruit = VALUES(fruit);
	 *
	 * $columns = array(
	 *      'col1' => '%s',
	 *      'col2' => '%d',
	 * );
	 *
	 * $rows = array(
	 *      array(
	 *          'col1' => 'string',
	 *          'col2' => 1
	 *      ),
	 *      array(
	 *          'col1' => 'another string',
	 *          'col2' => 2
	 *      ),
	 * );
	 */
	public function replace_bulk( $columns, $rows ) {
		$db = $this->get_db();
		$table_name = esc_sql( $this->get_table_name() );
		$column_names = $this->get_column_names_query( $columns, $rows );
		$column_updates = $this->get_column_updates_query( $columns );
		$values = $this->get_values_query( $columns, $rows );

		$query = <<<SQL
			INSERT INTO `{$table_name}` {$column_names}
				VALUES {$values}
				ON DUPLICATE KEY UPDATE {$column_updates};
SQL;

		return $db->query( $query );
	}

	public function delete( $where, $where_format = null ) {
		$db = $this->get_db();

		$db->delete( $this->get_table_name(), $where, $where_format );
	}

	function get_column_names_query( &$columns, $rows ) {
		$row = $rows[0];

		foreach ( $columns as $column => $format ) {
			if ( ! array_key_exists( $column, (array) $row ) ) {
				unset( $columns[ $column ]);
			}
		}

		$column_names = array_keys( $columns );
		$column_names = array_map( function( $value ){
			return "`{$value}`";
		}, $column_names );
		return '( ' . implode( ',', array_map( 'esc_sql', $column_names ) ) . ' )';
	}

	function get_column_updates_query( &$columns ) {
		$updates = '';

		foreach ( $columns as $column_name => $column_format ) {
			$column_name = esc_sql( $column_name );
			$updates .= "`{$column_name}` = VALUES(`$column_name`)";
			$updates .= ',';
		}

		$updates = rtrim( $updates, ',' );

		return $updates;
	}

	public function get_values_query( $columns, $rows ) {
		$types = array_values( $columns );

		$values = array();

		foreach( $rows as $data ) {
			/*
			 * $types is an array of values such as %d and %s, used in vsprintf to make sure data is correct format.
			 * Values are escaped for SQL via the array_map( 'esc_sql', $data ) in the same line
			 */
			$values[] = "\n\t('" . vsprintf( implode( "', '", $types ), array_map( 'esc_sql', $data ) ) . "')";
		}

		return implode( ',', $values );
	}

}
