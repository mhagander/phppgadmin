<?php

	/**
	 * Process an arbitrary SQL query - tricky!  The main problem is that
	 * unless we implement a full SQL parser, there's no way of knowing
	 * how many SQL statements have been strung together with semi-colons
	 * @param $_SESSION['sqlquery'] The SQL query string to execute
	 *
	 * $Id: sql.php,v 1.43 2008/01/10 20:19:27 xzilla Exp $
	 */

	// Prevent timeouts on large exports (non-safe mode only)
	if (!ini_get('safe_mode')) set_time_limit(0);

	// Include application functions
	include_once('./libraries/lib.inc.php');

	/**
	 * This is a callback function to display the result of each separate query
	 * @param ADORecordSet $rs The recordset returned by the script execetor
	 */
	function sqlCallback($query, $rs, $lineno) {
		global $data, $misc, $lang, $_connection;
		// Check if $rs is false, if so then there was a fatal error
		if ($rs === false) {
			echo htmlspecialchars($_FILES['script']['name']), ':', $lineno, ': ', nl2br(htmlspecialchars($_connection->getLastError())), "<br/>\n";
		}
		else {
			// Print query results
			switch (pg_result_status($rs)) {
				case PGSQL_TUPLES_OK:
					// If rows returned, then display the results
					$num_fields = pg_numfields($rs);
					echo "<p><table>\n<tr>";
					for ($k = 0; $k < $num_fields; $k++) {
						echo "<th class=\"data\">", $misc->printVal(pg_fieldname($rs, $k)), "</th>";
					}
		
					$i = 0;
					$row = pg_fetch_row($rs);
					while ($row !== false) {
						$id = (($i % 2) == 0 ? '1' : '2');
						echo "<tr>\n";
						foreach ($row as $k => $v) {
							echo "<td class=\"data{$id}\" style=\"white-space:nowrap;\">", $misc->printVal($v, pg_fieldtype($rs, $k), array('null' => true)), "</td>";
						}							
						echo "</tr>\n";
						$row = pg_fetch_row($rs);
						$i++;
					};
					echo "</table><br/>\n";
					echo $i, " {$lang['strrows']}</p>\n";
					break;
				case PGSQL_COMMAND_OK:
					// If we have the command completion tag
					if (version_compare(phpversion(), '4.3', '>=')) {
						echo htmlspecialchars(pg_result_status($rs, PGSQL_STATUS_STRING)), "<br/>\n";
					}
					// Otherwise if any rows have been affected
					elseif ($data->conn->Affected_Rows() > 0) {
						echo $data->conn->Affected_Rows(), " {$lang['strrowsaff']}<br/>\n";
					}
					// Otherwise output nothing...
					break;
				case PGSQL_EMPTY_QUERY:
					break;
				default:
					break;
			}
		}
	}

	// We need to store the query in a session for editing purposes
	// We avoid GPC vars to avoid truncating long queries
	// If we came from a report, we need to look up the query
	if (isset($_REQUEST['subject']) && $_REQUEST['subject'] == 'report' ) {
		global $data, $misc;
		include_once('./classes/Reports.php');
		$reportsdb = new Reports($status);
		$report = $reportsdb->getReport($_REQUEST['reportid']);
		$_SESSION['sqlquery'] = $report->fields['report_sql'];	
	} 
	elseif (isset($_POST['query'])) {
		// Or maybe we came from an sql form
		$_SESSION['sqlquery'] = $_POST['query'];
	} else {
		echo "could not find the query!!";
	}
	
	// Pagination maybe set by a get link that has it as FALSE,
	// if that's the case, unset the variable.

	if (isset($_REQUEST['paginate']) && $_REQUEST['paginate'] == 'f') {
		unset($_REQUEST['paginate']);
		unset($_POST['paginate']);
		unset($_GET['paginate']);
	}
	// Check to see if pagination has been specified. In that case, send to display
	// script for pagination
	/* if a file is given or the request is an explain, do not paginate */
	if (isset($_REQUEST['paginate']) && !(isset($_FILES['script']) && $_FILES['script']['size'] > 0)
			&& (preg_match('/^\s*explain/i', $_SESSION['sqlquery']) == 0)) {
		include('./display.php');
		exit;
	}
	
	$subject = isset($_REQUEST['subject'])? $_REQUEST['subject'] : '';
	$misc->printHeader($lang['strqueryresults']);
	$misc->printBody();
	$misc->printTrail('database');
	$misc->printTitle($lang['strqueryresults']);

	// Set the schema search path
	if (isset($_REQUEST['search_path'])) {
		if ($data->setSearchPath(array_map('trim',explode(',',$_REQUEST['search_path']))) != 0) {
			$misc->printFooter();
			exit;
		}
	}

	// May as well try to time the query
	if (function_exists('microtime')) {
		list($usec, $sec) = explode(' ', microtime());
		$start_time = ((float)$usec + (float)$sec);
	}
	else $start_time = null;
	// Execute the query.  If it's a script upload, special handling is necessary
	if (isset($_FILES['script']) && $_FILES['script']['size'] > 0)
		$data->executeScript('script', 'sqlCallback');
	else {
		// Set fetch mode to NUM so that duplicate field names are properly returned
		$data->conn->setFetchMode(ADODB_FETCH_NUM);
		$rs = $data->conn->Execute($_SESSION['sqlquery']);

		// $rs will only be an object if there is no error
		if (is_object($rs)) {
			// Request was run, saving it in history
			if(!isset($_REQUEST['nohistory']))
				$misc->saveScriptHistory($_SESSION['sqlquery']);

			// Now, depending on what happened do various things
	
			// First, if rows returned, then display the results
			if ($rs->recordCount() > 0) {
				echo "<table>\n<tr>";
				foreach ($rs->fields as $k => $v) {
					$finfo = $rs->fetchField($k);
					echo "<th class=\"data\">", $misc->printVal($finfo->name), "</th>";
				}
                                echo "</tr>\n";	
				$i = 0;		
				while (!$rs->EOF) {
					$id = (($i % 2) == 0 ? '1' : '2');
					echo "<tr>\n";
					foreach ($rs->fields as $k => $v) {
						$finfo = $rs->fetchField($k);
						echo "<td class=\"data{$id}\" style=\"white-space:nowrap;\">", $misc->printVal($v, $finfo->type, array('null' => true)), "</td>";
					}							
					echo "</tr>\n";
					$rs->moveNext();
					$i++;
				}
				echo "</table>\n";
				echo "<p>", $rs->recordCount(), " {$lang['strrows']}</p>\n";
			}
			// Otherwise if any rows have been affected
			elseif ($data->conn->Affected_Rows() > 0) {
				echo "<p>", $data->conn->Affected_Rows(), " {$lang['strrowsaff']}</p>\n";
			}
			// Otherwise nodata to print
			else echo '<p>', $lang['strnodata'], "</p>\n";
		}
	}

	// May as well try to time the query
	if ($start_time !== null) {
		list($usec, $sec) = explode(' ', microtime());
		$end_time = ((float)$usec + (float)$sec);	
		// Get duration in milliseconds, round to 3dp's	
		$duration = number_format(($end_time - $start_time) * 1000, 3);
	}
	else $duration = null;

	// Reload the browser as we may have made schema changes
	$_reload_browser = true;

	// Display duration if we know it
	if ($duration !== null) {
		echo "<p>", sprintf($lang['strruntime'], $duration), "</p>\n";
	}
	
	echo "<p>{$lang['strsqlexecuted']}</p>\n";
			
	echo "<ul class=\"navlink\">\n";
	
	// Return
	if (isset($_REQUEST['return_url']) && isset($_REQUEST['return_desc']))
		echo "\t<li><a href=\"{$_REQUEST['return_url']}\">{$_REQUEST['return_desc']}</a></li>\n";

	// Edit		
	echo "\t<li><a href=\"database.php?database=", urlencode($_REQUEST['database']),
		"&amp;server=", urlencode($_REQUEST['server']), "&amp;action=sql\">{$lang['streditsql']}</a></li>\n";
				
	// Create report
	if (($subject !== 'report') && $conf['show_reports'] && isset($rs) && is_object($rs) && $rs->recordCount() > 0)
		echo "\t<li><a href=\"reports.php?{$misc->href}&amp;action=create&amp;report_sql=",
			urlencode($_SESSION['sqlquery']), "\">{$lang['strcreatereport']}</a></li>\n";
	
	// Create view and download
	if (isset($_SESSION['sqlquery']) && isset($rs) && is_object($rs) && $rs->recordCount() > 0) {
		// Report views don't set a schema, so we need to disable create view in that case
		if (isset($_REQUEST['schema'])) 
			echo "\t<li><a href=\"views.php?action=create&amp;formDefinition=",
				urlencode($_SESSION['sqlquery']), "&amp;{$misc->href}\">{$lang['strcreateview']}</a></li>\n";
		echo "\t<li><a href=\"dataexport.php?query=", urlencode($_SESSION['sqlquery']);
		if (isset($_REQUEST['search_path']))
			echo "&amp;search_path=", urlencode($_REQUEST['search_path']);
		echo "&amp;{$misc->href}\">{$lang['strdownload']}</a></li>\n";
	}

	echo "</ul>\n";
	
	$misc->printFooter();
?>
