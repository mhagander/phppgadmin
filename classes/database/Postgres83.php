<?php

/**
 * PostgreSQL 8.3 support
 *
 * $Id: Postgres82.php,v 1.10 2007/12/28 16:21:25 ioguix Exp $
 */

include_once('./classes/database/Postgres.php');

class Postgres83 extends Postgres {

	var $major_version = 8.3;

	// List of all legal privileges that can be applied to different types
	// of objects.
	var $privlist = array(
  		'table' => array('SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REFERENCES', 'TRIGGER', 'ALL PRIVILEGES'),
  		'view' => array('SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REFERENCES', 'TRIGGER', 'ALL PRIVILEGES'),
  		'sequence' => array('SELECT', 'UPDATE', 'ALL PRIVILEGES'),
  		'database' => array('CREATE', 'TEMPORARY', 'CONNECT', 'ALL PRIVILEGES'),
  		'function' => array('EXECUTE', 'ALL PRIVILEGES'),
  		'language' => array('USAGE', 'ALL PRIVILEGES'),
  		'schema' => array('CREATE', 'USAGE', 'ALL PRIVILEGES'),
  		'tablespace' => array('CREATE', 'ALL PRIVILEGES')
	);
	// List of characters in acl lists and the privileges they
	// refer to.
	var $privmap = array(
		'r' => 'SELECT',
		'w' => 'UPDATE',
		'a' => 'INSERT',
  		'd' => 'DELETE',
  		'R' => 'RULE',
  		'x' => 'REFERENCES',
  		't' => 'TRIGGER',
  		'X' => 'EXECUTE',
  		'U' => 'USAGE',
 		'C' => 'CREATE',
  		'T' => 'TEMPORARY',
  		'c' => 'CONNECT'
	);

	/**
	 * Constructor
	 * @param $conn The database connection
	 */
	function Postgres83($conn) {
		$this->Postgres($conn);
	}

	// Help functions

	function getHelpPages() {
		include_once('./help/PostgresDoc83.php');
		return $this->help_page;
	}

	// Databse functions

	/**
	 * Return all database available on the server
	 * @param $currentdatabase database name that should be on top of the resultset
	 * 
	 * @return A list of databases, sorted alphabetically
	 */
	function getDatabases($currentdatabase = NULL) {
		global $conf, $misc;

		$server_info = $misc->getServerInfo();

		if (isset($conf['owned_only']) && $conf['owned_only'] && !$this->isSuperUser($server_info['username'])) {
			$username = $server_info['username'];
			$this->clean($username);
			$clause = " AND pr.rolname='{$username}'";
		}
		else $clause = '';

		if ($currentdatabase != NULL)
			$orderby = "ORDER BY pdb.datname = '{$currentdatabase}' DESC, pdb.datname";
		else
			$orderby = "ORDER BY pdb.datname";

		if (!$conf['show_system'])
			$where = ' AND NOT pdb.datistemplate';
		else
			$where = ' AND pdb.datallowconn';

		$sql = "
			SELECT pdb.datname AS datname, pr.rolname AS datowner, pg_encoding_to_char(encoding) AS datencoding,
				(SELECT description FROM pg_catalog.pg_shdescription pd WHERE pdb.oid=pd.objoid) AS datcomment,
				(SELECT spcname FROM pg_catalog.pg_tablespace pt WHERE pt.oid=pdb.dattablespace) AS tablespace,
				pg_catalog.pg_database_size(pdb.oid) as dbsize
			FROM pg_catalog.pg_database pdb LEFT JOIN pg_catalog.pg_roles pr ON (pdb.datdba = pr.oid)
			WHERE true
				{$where}
				{$clause}
			{$orderby}";

		return $this->selectSet($sql);
	}

	// Administration functions

	/**
	 * Returns all available autovacuum per table information.
	 * @return A recordset
	 */
	function getTableAutovacuum($table='') {
		$sql = '';

		if ($table !== '') {
			$this->clean($table);
			$f_schema = $this->_schema;
			$this->fieldClean($f_schema);

			$sql = "
				SELECT vacrelid, nspname, relname, 
					CASE enabled 
						WHEN 't' THEN 'on' 
						ELSE 'off' 
					END AS autovacuum_enabled, vac_base_thresh AS autovacuum_vacuum_threshold,
					vac_scale_factor AS autovacuum_vacuum_scale_factor, anl_base_thresh AS autovacuum_analyze_threshold, 
					anl_scale_factor AS autovacuum_analyze_scale_factor, vac_cost_delay AS autovacuum_vacuum_cost_delay, 
					vac_cost_limit AS autovacuum_vacuum_cost_limit
				FROM pg_autovacuum AS a
					join pg_class AS c on (c.oid=a.vacrelid)
					join pg_namespace AS n on (n.oid=c.relnamespace)
				WHERE c.relname = '{$table}' AND n.nspname = '{$f_schema}'
				ORDER BY nspname, relname
			";
		}
		else {
			$sql = "
				SELECT vacrelid, nspname, relname, 
					CASE enabled 
						WHEN 't' THEN 'on' 
						ELSE 'off' 
					END AS autovacuum_enabled, vac_base_thresh AS autovacuum_vacuum_threshold,
					vac_scale_factor AS autovacuum_vacuum_scale_factor, anl_base_thresh AS autovacuum_analyze_threshold, 
					anl_scale_factor AS autovacuum_analyze_scale_factor, vac_cost_delay AS autovacuum_vacuum_cost_delay, 
					vac_cost_limit AS autovacuum_vacuum_cost_limit
				FROM pg_autovacuum AS a
					join pg_class AS c on (c.oid=a.vacrelid)
					join pg_namespace AS n on (n.oid=c.relnamespace)
				ORDER BY nspname, relname
			";
		}

		return $this->selectSet($sql);
	}
	
	function saveAutovacuum($table, $vacenabled, $vacthreshold, $vacscalefactor, $anathresold, 
		$anascalefactor, $vaccostdelay, $vaccostlimit) 
	{
		$defaults = $this->getAutovacuum();
		$c_schema = $this->_schema;
		$this->clean($c_schema);
		
		$rs = $this->selectSet("
			SELECT c.oid 
			FROM pg_catalog.pg_class AS c 
				LEFT JOIN pg_catalog.pg_namespace AS n ON (n.oid=c.relnamespace)
			WHERE 
				c.relname = '{$table}' AND n.nspname = '{$c_schema}'
		");
		
		if ($rs->EOF)
			return -1;
			
		$toid = $rs->fields('oid');
		unset ($rs);
			
		if (empty($_POST['autovacuum_vacuum_threshold']))
			$_POST['autovacuum_vacuum_threshold'] = $defaults['autovacuum_vacuum_threshold'];
		
		if (empty($_POST['autovacuum_vacuum_scale_factor']))
			$_POST['autovacuum_vacuum_scale_factor'] = $defaults['autovacuum_vacuum_scale_factor'];
		
		if (empty($_POST['autovacuum_analyze_threshold']))
			$_POST['autovacuum_analyze_threshold'] = $defaults['autovacuum_analyze_threshold'];
		
		if (empty($_POST['autovacuum_analyze_scale_factor']))
			$_POST['autovacuum_analyze_scale_factor'] = $defaults['autovacuum_analyze_scale_factor'];
		
		if (empty($_POST['autovacuum_vacuum_cost_delay']))
			$_POST['autovacuum_vacuum_cost_delay'] = $defaults['autovacuum_vacuum_cost_delay'];
		
		if (empty($_POST['autovacuum_vacuum_cost_limit']))
			$_POST['autovacuum_vacuum_cost_limit'] = $defaults['autovacuum_vacuum_cost_limit'];
		
		if (empty($_POST['vacuum_freeze_min_age']))
			$_POST['vacuum_freeze_min_age'] = $defaults['vacuum_freeze_min_age'];
		
		if (empty($_POST['autovacuum_freeze_max_age']))
			$_POST['autovacuum_freeze_max_age'] = $defaults['autovacuum_freeze_max_age'];
		

		$rs = $this->selectSet("SELECT vacrelid 
			FROM \"pg_catalog\".\"pg_autovacuum\" 
			WHERE vacrelid = {$toid};");
		
		$status = -1; // ini
		if (isset($rs->fields['vacrelid']) and ($rs->fields['vacrelid'] == $toid)) {
			// table exists in pg_autovacuum, UPDATE
			$sql = sprintf("UPDATE \"pg_catalog\".\"pg_autovacuum\" SET 
						enabled = '%s',
						vac_base_thresh = %s,
						vac_scale_factor = %s,
						anl_base_thresh = %s,
						anl_scale_factor = %s,
						vac_cost_delay = %s,
						vac_cost_limit = %s,
						freeze_min_age = %s,
						freeze_max_age = %s
					WHERE vacrelid = {$toid};
				",
				($_POST['autovacuum_enabled'] == 'on')? 't':'f',
				$_POST['autovacuum_vacuum_threshold'],
				$_POST['autovacuum_vacuum_scale_factor'],
				$_POST['autovacuum_analyze_threshold'],
				$_POST['autovacuum_analyze_scale_factor'],
				$_POST['autovacuum_vacuum_cost_delay'],
				$_POST['autovacuum_vacuum_cost_limit'],
				$_POST['vacuum_freeze_min_age'],
				$_POST['autovacuum_freeze_max_age']
			);
			$status = $this->execute($sql);
		}
		else {
			// table doesn't exists in pg_autovacuum, INSERT
			$sql = sprintf("INSERT INTO \"pg_catalog\".\"pg_autovacuum\" 
				VALUES (%s, '%s', %s, %s, %s, %s, %s, %s, %s, %s )
				WHERE 
					c.relname = '{$table}' AND n.nspname = '{$c_schema}';",
				$toid,
				($_POST['autovacuum_enabled'] == 'on')? 't':'f',
				$_POST['autovacuum_vacuum_threshold'],
				$_POST['autovacuum_vacuum_scale_factor'],
				$_POST['autovacuum_analyze_threshold'],
				$_POST['autovacuum_analyze_scale_factor'],
				$_POST['autovacuum_vacuum_cost_delay'],
				$_POST['autovacuum_vacuum_cost_limit'],
				$_POST['vacuum_freeze_min_age'],
				$_POST['autovacuum_freeze_max_age']
			);
			$status = $this->execute($sql);
		}
		
		return $status;
	}

	function dropAutovacuum($table) {
		$c_schema = $this->_schema;
		$this->clean($c_schema);
		$this->clean($table);
		
		$rs = $this->selectSet("
			SELECT c.oid 
			FROM pg_catalog.pg_class AS c 
				LEFT JOIN pg_catalog.pg_namespace AS n ON (n.oid=c.relnamespace)
			WHERE 
				c.relname = '{$table}' AND n.nspname = '{$c_schema}'
		");
		
		return $this->deleteRow('pg_autovacuum', array('vacrelid' => $rs->fields['oid']), 'pg_catalog');
	}

	function hasQueryKill() { return false; }
	function hasDatabaseCollation() { return false; }

}

?>
