<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 *
 * @filesource	execSetResults.php
 *
 * @internal revisions
 * @since 2.0
 * 20121027 - franciscom - TICKET 5310: Execution Config - convert options into rights
 *
**/
require_once('../../config.inc.php');
require_once('common.php');
require_once("specview.php");
require_once("web_editor.php");

$cfg = getCfg();
require_once(require_web_editor($cfg->editorCfg['type']));
if( $cfg->exec_cfg->enable_test_automation )
{
  require_once('remote_exec.php');
}

testlinkInitPage($db);
$templateCfg = templateConfiguration();
$smarty = new TLSmarty();

$tcversion_id = null;
$submitResult = null;
$args = init_args($cfg);
$mgr = createManagers($db,$args->tproject_id);
$gui = initializeGui($db,$args,$cfg,$mgr);

// get issue tracker config and object to manage TestLink - BTS integration 
list($issueTrackerEnabled,$its) = $mgr->tproject->getIssueTrackerMgr($args->tproject_id);
if($issueTrackerEnabled)
{
	if(!is_null($its) && $its->isConnected())
	{
		$gui->issueTrackerIntegrationOn = true;
	}
	else
	{
		$gui->user_feedback = lang_get('issue_tracker_integration_problems');
	}
}


$_SESSION['history_on'] = $gui->history_on;
// Testplan executions and result archiving. Checks whether execute cases button was clicked
if($args->doExec == 1 && !is_null($args->tc_versions) && count($args->tc_versions))
{
	$gui->remoteExecFeedback = launchRemoteExec($db,$args,$gui->tcasePrefix,$mgr);
}	


list($linked_tcversions,$itemSet) = getLinkedItems($args,$gui->history_on,$gui->cfg,$mgr);
$tcase_id = 0;
$userid_array = null;
if(!is_null($linked_tcversions))
{
	$items_to_exec = array();
  if ($args->doDelete)
  {
    $mgr->tcase->deleteExecution($args->exec_to_delete);
  }

  if($args->level == 'testcase')
  {
    // Warning!!! - $gui is passed by reference to be updated inside function
    $tcase = null;
    list($tcase_id,$tcversion_id) = processTestCase($tcase,$gui,$args,$linked_tcversions,$mgr);
  }
  else
  {
    list($tcase_id,$tcversion_id) = processTestSuite($db,$gui,$args,$linked_tcversions,$mgr);
  }

  $gui->tcversionSet = is_array($tcversion_id) ? implode(',',$tcversion_id) : $tcversion_id;



  // will create a record even if the testcase version has not been executed (GET_NO_EXEC)
  //
  // Can be DONE JUST ONCE AFTER write results to DB
  // Results to DB
  if ($args->save_results || $args->do_bulk_save || $args->save_and_next)
  {
    // this has to be done to do not break logic present on writeExecToDB()
    $args->save_results = $args->save_and_next ? $args->save_and_next : $args->save_results;
    $_REQUEST['save_results'] = $args->save_results;
    $mgr->tcase->writeExecToDB($args,$_REQUEST);
        
    // Need to re-read to update test case status
    if ($args->save_and_next) 
    {
      $identity = processSaveAndNext($mgr,$args,$gui,$tcversion_id);
			if( !is_null($identity) )
			{
				$tcase_id = $identity['tcase_id'];
				$tcversion_id = $identity['tcversion_id'];
			}
   }
  }
  // Important Notice: $tcase_id and $tcversions_id, can be ARRAYS when user enable bulk execution
  $gui->map_last_exec = getLastExecution($db,$tcase_id,$tcversion_id,$gui,$args,$mgr->tcase,
                                         $smarty->tlImages);
    
  $gui->map_last_exec_any_build = null;
  $gui->other_execs=null;
  $testerid = null;
    
    
  if($args->level == 'testcase')
  {
    // @TODO 20090815 - franciscom check what to do with platform
    if( $gui->cfg->exec_cfg->show_last_exec_any_build )
    {
			$options=array('getNoExecutions' => 1, 'groupByBuild' => 0);
    	$gui->map_last_exec_any_build = $mgr->tcase->get_last_execution($tcase_id,$tcversion_id,$args->tplan_id,
    	                                                               testcase::ANY_BUILD,
    	                                                               $args->platform_id,$options);
    	    
    	// Get UserID and Updater ID for current Version
    	$tc_current = $gui->map_last_exec_any_build;
    	foreach ($tc_current as $key => $value)
    	{
				$testerid = $value['tester_id'];
			  $userid_array[$testerid] = $testerid;
    	}	    
    }
    	
    $gui->req_details = $mgr->req->get_all_for_tcase($tcase_id);
    $gui->other_execs = getOtherExecutions($db,$tcase_id,$tcversion_id,$gui,$args,$mgr->tcase,
                                           $smarty->tlImages);
    // Get attachment,bugs, etc
    if(!is_null($gui->other_execs))
    {
    		//Get the Tester ID for all previous executions
			  foreach ($gui->other_execs as $key => $execution)
			  {    	
		      	foreach ($execution as $singleExecution)
		      	{    			  
			  	      $testerid = $singleExecution['tester_id'];
			  	      $userid_array[$testerid] = $testerid;
		      	}    	
			  }
    	
    	  // do not like to much this kind of layout but ...
			  list($gui->attachments,$gui->bugs,$gui->other_exec_cfields) = 
			      exec_additional_info($db,$mgr,$gui->other_execs,$args->tplan_id,$args->tproject_id,
    	                           $issueTrackerEnabled,$its);
 	 
    	  // this piece of code is useful to avoid error on smarty template due to undefined value   
    	  if( is_array($tcversion_id) && (count($gui->other_execs) != count($gui->map_last_exec)) )
    	  {
    	    foreach($tcversion_id as $version_id)
    	    {
    	        if( !isset($gui->other_execs[$version_id]) )
    	        {
    	            $gui->other_execs[$version_id]=null;  
    	        }  
    	    }
    	  }
    	} // if(!is_null($gui->other_execs))
    } 	
} // if(!is_null($linked_tcversions))


// Removing duplicate and NULL id's
$gui->users = builGuiUsers($db,$userid_array);
buildGuiTSuiteInfo($db,$_REQUEST,$mgr,$gui,$tcase_id,$args->tproject_id);

// Bulk is possible when test suite is selected (and is allowed in config)
$gui->can_use_bulk_op = ($args->level == 'testsuite');
if( $gui->can_use_bulk_op )
{
  setUpForBulkExec($gui);
}
else
{
  $gui->exec_notes_editors = createExecNotesWebEditor($gui->map_last_exec,$_SESSION['basehref'],$gui->cfg->editorCfg);
}


new dBug($gui);

$smarty->assign('gui',$gui);
$smarty->display($templateCfg->template_dir . $templateCfg->default_template);



function init_args($cfgObj)
{
	$_REQUEST = strings_stripSlashes($_REQUEST);
	$form_token = isset($_REQUEST['form_token']) ? $_REQUEST['form_token'] : 0;

	$args = new stdClass();
	$args->user = $_SESSION['currentUser'];
  $args->user_id = $args->user->dbID;

  getFromSessionPool($args,$form_token);
	
	// can be a list, will arrive via form POST
	$args->tc_versions = isset($_REQUEST['tc_version']) ? $_REQUEST['tc_version'] : null;  
	$args->cf_selected = isset($_REQUEST['cfields']) ? unserialize($_REQUEST['cfields']) : null;
  $args->doExec = isset($_REQUEST['execute_cases']) ? 1 : 0;
	$args->doDelete = isset($_REQUEST['do_delete']) ? $_REQUEST['do_delete'] : 0;
 
  $int2get = array('id','tproject_id','tplan_id');
  foreach($int2get as $prop)
  {
    $args->$prop = isset($_REQUEST[$prop]) ? intval($_REQUEST[$prop]): 0;
  }

	$key2loop = array('level' => '','status' => null, 'do_bulk_save' => 0, 'save_results' => 0, 'save_and_next' => 0);
	foreach($key2loop as $key => $value)
	{
		$args->$key = isset($_REQUEST[$key]) ? $_REQUEST[$key] : $value;
	}

  // See details on: "When nullify filter_status - 20080504" in this file
  if(is_null($args->filter_status) || trim($args->filter_status) || $args->level == 'testcase')
  {
    $args->filter_status = null;  
  }
  else
  {
    $args->filter_status = unserialize($args->filter_status);
  }


  switch($args->level)
  {
    case 'testcase':
      $args->tc_id = $args->id;
      $args->tsuite_id = null;

      // some problems with $_GET that has impact on logic 'Save and Go to next test case';
      if( !is_null($args->tc_versions) )
      {
      	$args->tc_id = current($args->tc_versions);
      	$args->id = $args->tc_id;
      	$args->version_id = key($args->tc_versions);
      } 
    break;
        
    case 'testsuite':
      $args->tsuite_id = $args->id;
      $args->tc_id = null;
    break;
  }

  buildCookies($args,$cfgObj);

  // Brute Force Exit  
  if (($args->level == "" || $args->level == 'testproject'))
  {
      show_instructions('executeTest');
      exit();
  }
  
  foreach(array('tproject_id','tplan_id','build_id') as $prop)
  {
    if( $args->$prop <= 0)
    {
 			// CRASH IMMEDIATELY
			throw new Exception("Execution Can NOT WORK with $prop <= 0");
    }
  }
  
  
	return $args;
}


/*
  function: 

  args :
  
  returns: 

*/
function manage_history_on($hash_REQUEST,$hash_SESSION,
                           $exec_cfg,$btn_on_name,$btn_off_name,$hidden_on_name)
{
    if( isset($hash_REQUEST[$btn_on_name]) )
    {
		    $history_on = true;
    }
    elseif(isset($_REQUEST[$btn_off_name]))
    {
		    $history_on = false;
    }
    elseif (isset($_REQUEST[$hidden_on_name]))
    {
        $history_on = $_REQUEST[$hidden_on_name];
    }
    elseif (isset($_SESSION[$hidden_on_name]))
    {
        $history_on = $_SESSION[$hidden_on_name];
    }
    else
    {
        $history_on = $exec_cfg->history_on;
    }
    return $history_on;
}
/*
  function: get_ts_name_details

  args :
  
  returns: map with key=TCID
           values= assoc_array([tsuite_id => 5341
                               [details] => my detailas ts1
                               [tcid] => 5343
                               [tsuite_name] => ts1)
*/
function get_ts_name_details(&$db,$tcase_id)
{
	$tables = array();
    $tables['testsuites'] = DB_TABLE_PREFIX . 'testsuites';
    $tables['nodes_hierarchy'] = DB_TABLE_PREFIX . 'nodes_hierarchy';

    
	$rs = '';
	$do_query = true;
	$sql = "SELECT TS.id AS tsuite_id, TS.details, 
	             NHA.id AS tc_id, NHB.name AS tsuite_name 
	      FROM {$tables['testsuites']} TS, {$tables['nodes_hierarchy']} NHA, 
	           {$tables['nodes_hierarchy']} NHB
	      WHERE TS.id=NHA.parent_id
	      AND   NHB.id=NHA.parent_id ";
	if( is_array($tcase_id) && count($tcase_id) > 0)
	{
		$in_list = implode(",",$tcase_id);
		$sql .= "AND NHA.id IN (" . $in_list . ")";
	}
	else if(!is_null($tcase_id))
	{
		$sql .= "AND NHA.id={$tcase_id}";
	}
	else
	{
		$do_query = false;
	}
	if($do_query)
	{
		$rs = $db->fetchRowsIntoMap($sql,'tc_id');
	}
	return $rs;
}

/*
  function: 

  args :
  
  returns: 

*/
function buildGuiTSuiteInfo(&$db,&$request_hash,$mgrPool,&$guiObj,$tcase_id,$tproject_id)
{
  $fpath = $mgrPool->tcase->get_full_path_verbose($tcase_id, array('output_format' => 'id_name'));
  $guiObj->tsuite_info = get_ts_name_details($db,$tcase_id);
  foreach($fpath as $key => $value)
  {
      unset($value['name'][0]);  // Remove test project info
      unset($value['node_id'][0]);  // Remove test project info
      $str='';
      foreach($value['name'] as $jdx => $elem)
      {
      	$str .= "<a href=\"javascript:openTestSuiteWindow(" . $value['node_id'][$jdx] . ")\"> ";
      	// Encoding did not work properly
      	$str .= htmlspecialchars($elem,ENT_QUOTES) . '</a>';
      }
      $guiObj->tsuite_info[$key]['tsuite_name']=$str;  
  }
  
	if(!is_null($guiObj->tsuite_info))
  {
    $cookieKey = 'TL_execSetResults_tsdetails_view_status';
		$exec_cfg = config_get('exec_cfg');

    $a_tsvw=array();
    $a_ts=array();
    $a_tsval=array();
    
    $tsuite_mgr = new testsuite($db);
    foreach($guiObj->tsuite_info as $key => $elem)
    {
      $main_k = 'tsdetails_view_status_' . $key;
    	$a_tsvw[] = $main_k;
    	$a_ts[] = 'tsdetails_' . $key;
      $expand_collapse = 0;
			if( !isset($request_hash[$main_k]) )
			{
				// First time we are entered here => we can need to understand how to proceed
        switch($exec_cfg->expand_collapse->testsuite_details)
			  {
			    case LAST_USER_CHOICE:
  					if (isset($_COOKIE[$cookieKey]) ) 
      			{
      						$expand_collapse = $_COOKIE[$cookieKey];
  					}
					break;	
        	
					default:
						$expand_collapse = $exec_cfg->expand_collapse->testsuite_details;
					break;
		    } 
			}
      $a_tsval[] = isset($request_hash[$main_k]) ? $request_hash[$main_k] : $expand_collapse;
    	$tsuite_id = $elem['tsuite_id'];
    	$tc_id = $elem['tc_id'];
    	if(!isset($cached_cf[$tsuite_id]))
    	{
        $cached_cf[$tsuite_id] = $tsuite_mgr->html_table_of_custom_field_values($tsuite_id,'design',null,$tproject_id);
    	}
    	$guiObj->ts_cf_smarty[$tc_id] = $cached_cf[$tsuite_id];
    }
    if( count($a_tsval) > 0 )
    {
			setcookie($cookieKey,$a_tsval[0],TL_COOKIE_KEEPTIME, '/');
    }
    	
    $guiObj->tsd_div_id_list = implode(",",$a_ts);
    $guiObj->tsd_hidden_id_list = implode(",",$a_tsvw);
    $guiObj->tsd_val_for_hidden_list = implode(",",$a_tsval);
	}

}  


/*
  function: 

  args :
  
  returns: 

@internal revisions:
  20100625 - asimon - added parameters $bugInterfaceOn, $bugInterface 
                      to get rid of warning in event log

*/
function exec_additional_info(&$db, $mgrPool,$other_execs, 
                              $tplan_id, $tproject_id, $bugInterfaceOn, $bugInterface)
{
  $attachmentInfos = null;
  $bugs = null;
  $cfexec_values = null;

  foreach($other_execs as $tcversion_id => $execInfo)
  {
    $num_elem = sizeof($execInfo);   
  	for($idx = 0;$idx < $num_elem;$idx++)
  	{
  		$exec_id = $execInfo[$idx]['execution_id'];

      // REFACTORING NEEDED !!! 20120930  		
  		// $aInfo = getAttachmentInfos($mgrPool->attachmentRepository,$exec_id,'executions',
  		//            tlAttachmentRepository::STOREINSESSION,1);
  		$infoSet = $mgrPool->repository->getAllAttachmentsMetadata($exec_id,'executions',tlAttachmentRepository::STOREINSESSION,1);
  		if ($infoSet)
  		{
  			$attachmentInfos[$exec_id] = $infoSet;
  		}
  		
  		if($bugInterfaceOn)
  		{
  			$the_bugs = get_bugs_for_exec($db,$bugInterface,$exec_id);
	  		if(count($the_bugs) > 0)
		  	{
			  	$bugs[$exec_id] = $the_bugs;
			  }	
  		}

      // Custom fields
      $cfexec_values[$exec_id] = $mgrPool->tcase  ->html_table_of_custom_field_values($tcversion_id,'execution',null,
                                                                                      $exec_id,$tplan_id,$tproject_id);
  	}
  }
  return array( $attachmentInfos,$bugs,$cfexec_values);      
} //function end


/*
  function: 

  args : context hash with following keys
  		 target => array('tc_versions' => array, 'version_id' =>, 'feature_id' => array) 
  		 context => array with keys 
  		 							tproject_id
  		 							tplan_id
  		 							platform_id
  		 							build_id
  		 							user_id
  
  
  returns: 

*/
function do_remote_execution(&$dbHandler,$context)
{
	$debugMsg = "File:" . __FILE__ . " Function: " . __FUNCTION__;
	
	$tables = array();
    $tables['executions'] = DB_TABLE_PREFIX . 'executions';

    $resultsCfg = config_get('results');
    $tc_status = $resultsCfg['status_code'];
    $tree_mgr = new tree($dbHandler);
    $cfield_mgr = new cfield_mgr($dbHandler);
  
	$ret = null;
	$executionResults = array();

	$myResult = array();
	$sql = 	" /* $debugMsg */ INSERT INTO {$tables['executions']} " . 
			" (testplan_id,platform_id,build_id,tester_id,execution_type," .
			"  tcversion_id,execution_ts,status,notes) ".
			" VALUES ({$context['tplan_id']}, {$context['platform_id']}, {$context['build_id']}," .
			" {$context['user_id']}," . testcase::EXECUTION_TYPE_AUTO . ",";

	// have we got multiple test cases to execute ?
	$target = &$context['target'];
	foreach($target['tc_versions'] as $version_id => $tcase_id)
	{
		$ret[$version_id] = array("verboseID" => null,
								  "status" => null,"notes" => null,"system" => null,
				 				  "scheduled" => null, "timestamp" => null);

		$tcaseInfo = $tree_mgr->get_node_hierarchy_info($tcase_id);
		$tcaseInfo['version_id'] = $version_id;
		
		// For each test case version we can have a different server config
		$serverCfg = $cfield_mgr->getXMLRPCServerParams($version_id,$target['feature_id'][$version_id]);
		$execResult[$version_id] = executeTestCase($tcaseInfo,$serverCfg,$context['context']); // RPC call

		
		$tryWrite = false;
		switch($execResult[$version_id]['system']['status'])
		{
			case 'configProblems':
				$tryWrite = false;
			break;
			
			case 'connectionFailure':
				$tryWrite = false;
			break;
				
			case 'ok';
				$tryWrite = true;
			break;	
		}
		
		if( $tryWrite )
		{
			$trun = &$execResult[$version_id]['execution'];
			if( $trun['scheduled'] == 'now' )
			{
				$ret[$version_id]["status"] = strtolower($trun['result']);
				$ret[$version_id]["notes"] = trim($trun['notes']);
				
				$notes = $dbHandler->prepare_string($ret[$version_id]["notes"]);

				if( $ret[$version_id]["status"] != $tc_status['passed'] && 
					$ret[$version_id]["status"] != $tc_status['failed'] && 
				    $ret[$version_id]["status"] != $tc_status['blocked'])
				{
					  $ret[$version_id]["status"] = $tc_status['blocked'];
				}
				
				//
				$sql2exec = $sql . $version_id . "," . $dbHandler->db_now() . 
							", '{$ret[$version_id]["status"]}', '{$notes}' )"; 
				$dbHandler->exec_query($sql2exec);
			}
			else
			{
				$ret[$version_id]["notes"] = trim($execResult[$version_id]['notes']);
				$ret[$version_id]["scheduled"] = $execResult[$version_id]['scheduled'];
				$ret[$version_id]["timestamp"]= $execResult[$version_id]['timestampISO'];
			}
		}
		else
		{
			$ret[$version_id]["system"] = $execResult[$version_id]['system'];
		}
	}
	
	return $ret;
}


/*
  function: initializeExecMode 

  args:
  
  returns: 

*/
function initializeExecMode(&$db,$exec_cfg,$userObj,$tproject_id,$tplan_id)
{

    $simple_tester_roles=array_flip($exec_cfg->simple_tester_roles);
    $effective_role = $userObj->getEffectiveRole($db,$tproject_id,$tplan_id);
    
	// SCHLUNDUS: hmm, for user defined roles, this wont work correctly
	// 20080104 - franciscom - Please explain why do you think will not work ok ?
	//                         do you prefer to check for exec right ?
	//
	// SCHLUNDUS: jep, exactly. If a user defines it own roles than a check for the tester
	// role will not do the desired effect of putting the logged in user in tester-view-mode
	// instead we must check for presence (and or absence) the right(s) which mades a user a tester 
	//
	// 20080310 - franciscom - 
	// Role is considered tester if:
	// role == TL_ROLES_TESTER OR Role has Test Plan execute but not Test Plan planning
	//
	//
	$can_execute = $effective_role->hasRight('testplan_execute');
	$can_manage = $effective_role->hasRight('testplan_planning');
    
	// 20081217 - franciscom
	// $use_exec_cfg = $effective_role->dbID == TL_ROLES_TESTER || ($can_execute && !$can_manage);
    $use_exec_cfg = isset($simple_tester_roles[$effective_role->dbID]) || ($can_execute && !$can_manage);
    
    return  $use_exec_cfg ? $exec_cfg->exec_mode->tester : 'all';
} // function end


/*
  function: setTesterAssignment 

  args:
  
  returns: 

*/
function setTesterAssignment(&$db,$exec_info,&$tcase_mgr,$tplan_id,$platform_id, $build_id)
{     

  $userCache = null;
	foreach($exec_info as $version_id => $value)
	{
		$exec_info[$version_id]['assigned_user'] = '';
		$exec_info[$version_id]['assigned_user_id'] = 0;
		
		// map of map: main key version_id, secondary key: platform_id
		$p3 = $tcase_mgr->get_version_exec_assignment($version_id,$tplan_id, $build_id);
		$assignedTesterId = intval($p3[$version_id][$platform_id]['user_id']);
		if($assignedTesterId)
		{
	    if( !isset($userCache[$assignedTesterId]) )
	    {
        $userCache[$assignedTesterId] = tlUser::getByID($db,$assignedTesterId);
	    }
	    
			if( isset($userCache[$assignedTesterId]) )
			{
				$exec_info[$version_id]['assigned_user'] = $userCache[$assignedTesterId]->getDisplayName();  
			}
			$exec_info[$version_id]['assigned_user_id'] = $assignedTesterId;
		}  
	}
	return $exec_info;
} //function end

/*
  function: 
           Reorder executions to mantaing correct visualization order.

  args:
  
  returns: 

*/
function reorderExecutions(&$tcversion_id,&$exec_info)
{
    $dummy = array();
    foreach($tcversion_id as $key => $value)
    {
       $dummy[$key] = $exec_info[$value];    
    }
    return $dummy;    
}

/*
  function: setCanExecute 

  args:
  
  returns: 

*/
function setCanExecute($exec_info,$execution_mode,$can_execute,$tester_id)
{     
	foreach($exec_info as $key => $tc_exec) 
	{
		$execution_enabled = 0;  
		if($can_execute == 1 && $tc_exec['active'] == 1)
		{
			$assigned_to_me = $tc_exec['assigned_user_id'] == $tester_id ? 1 : 0;
			$is_free = $tc_exec['assigned_user_id'] == '' ? 1 : 0;

			switch($execution_mode)
			{
				case 'assigned_to_me':
					$execution_enabled = $assigned_to_me;
					break;

				case 'assigned_to_me_or_free':
					$execution_enabled = $assigned_to_me || $is_free;
					break;

				case 'all':
					$execution_enabled = 1;
					break;

				default:
					$execution_enabled = 0;  
					break;
			} // switch
		}
		$exec_info[$key]['can_be_executed']=$execution_enabled;
	}
	return $exec_info;
} //function end


/*
  function: createExecNotesWebEditor
            creates map of html needed to display web editors
            for execution notes.
            
  args: tcversions: array where each element has information
                    about testcase version that can be executed.
                    
        basehref: URL            
        editorCfg:
  
  returns: map
           key: testcase id
           value: html to display web editor.

  rev : 20080104 - creation  
*/
function createExecNotesWebEditor(&$tcversions,$basehref,$editorCfg)
{
  
    if(is_null($tcversions) || count($tcversions) == 0 )
    {
        return null;  // nothing todo >>>------> bye!  
    }
     
    // Important Notice:
    //
    // When using tinymce or none as web editor, we need to set rows and cols
    // to appropriate values, to avoid an ugly ui.
    // null => use default values defined on editor class file
    //
    // Rows and Cols values are useless for FCKeditor.
    //
    $itemTemplateValue = getItemTemplateContents('execution_template', 'notes', null);
    foreach($tcversions as $key => $tcv)
    {
        $tcversion_id=$tcv['id'];
        $tcase_id=$tcv['testcase_id'];

        $of=web_editor("notes[{$tcversion_id}]",$basehref,$editorCfg) ;
        $of->Value = $itemTemplateValue;
       
        // Magic numbers that can be determined by trial and error
        $editors[$tcase_id]=$of->CreateHTML(10,60);         
        unset($of);
    }
    return $editors;
}



/*
  function: getCfg 

  args:
  
  returns: 

*/
function getCfg()
{
    $cfg = new stdClass();
    $cfg->exec_cfg = config_get('exec_cfg');
    $cfg->gui_cfg = config_get('gui');
    $cfg->bts_type = config_get('interface_bugs');
    
    $results = config_get('results');
    $cfg->tc_status = $results['status_code'];
    $cfg->testcase_cfg = config_get('testcase_cfg'); 
    $cfg->editorCfg = getWebEditorCfg('execution');
    
    return $cfg;
}


/*
  function: initializeRights 
            create object with rights useful for this feature 
  
  args:
       dbHandler: reference to db object
       $userObj: reference to current user object
       tproject_id:
       tplan_id
  
                
  returns: 

*/
function initializeRights(&$dbHandler,&$userObj,$tproject_id,$tplan_id)
{
    $exec_cfg = config_get('exec_cfg');
    $grants = new stdClass();
    
    $grants->execute = $userObj->hasRight($dbHandler,"testplan_execute",$tproject_id,$tplan_id);
    $grants->execute = $grants->execute=="yes" ? 1 : 0;
    
    // TICKET 5310: Execution Config - convert options into rights
    $grants->delete_execution = $userObj->hasRight($dbHandler,"exec_delete",$tproject_id,$tplan_id);
    
    
    // may be in the future this can be converted to a role right
    // Important:
    // Execution right must be present to consider this configuration option.
    $grants->edit_exec_notes = $grants->execute && 
                               $userObj->hasRight($dbHandler,"exec_edit_notes",$tproject_id,$tplan_id);
    
    $grants->edit_testcase = $userObj->hasRight($dbHandler,"mgt_modify_tc",$tproject_id,$tplan_id);
    $grants->edit_testcase = $grants->edit_testcase=="yes" ? 1 : 0;
    return $grants;
}


/*
  function: initializeGui

  args :
  
  returns: 

*/
function initializeGui(&$dbHandler,&$argsObj,$cfgObj,&$mgrPool)
{
    $buildMgr = new build_mgr($dbHandler);
    $platformMgr = new tlPlatform($dbHandler,$argsObj->tproject_id);
    
    $gui = new stdClass();
    $gui->cfg = $cfgObj;
    $gui->issueTrackerIntegrationOn = false;
    $gui->tplan_id=$argsObj->tplan_id;
    $gui->tproject_id=$argsObj->tproject_id;
    $gui->build_id = $argsObj->build_id;
    $gui->platform_id = $argsObj->platform_id;
    
    $gui->execStatusValues=null;
    $gui->can_use_bulk_op=0;
    $gui->exec_notes_editors=null;
    $gui->bulk_exec_notes_editor=null;
    $gui->req_details=null;
    $gui->attachmentInfos=null;
    $gui->bugs=null;
    $gui->other_exec_cfields=null;
    $gui->ownerDisplayName = null;
    
    $gui->editorType = $gui->cfg->editorCfg['type'];
    $gui->filter_assigned_to=$argsObj->filter_assigned_to;
    $gui->tester_id=$argsObj->user_id;
    $gui->include_unassigned=$argsObj->include_unassigned;
    $gui->tpn_view_status=$argsObj->tpn_view_status;
    $gui->bn_view_status=$argsObj->bn_view_status;
    $gui->bc_view_status=$argsObj->bc_view_status;
    $gui->platform_notes_view_status=$argsObj->platform_notes_view_status;

    $gui->refreshTree = $argsObj->refreshTree;
    if (!$argsObj->status || $argsObj->status == $gui->cfg->tc_status['not_run']) {
    	$gui->refreshTree = 0;
    }
    
    $gui->map_last_exec_any_build=null;
    $gui->map_last_exec=null;

    	
    // Just for the record:	
    // doing this here, we avoid to do on processTestSuite() and processTestCase(),
    // but absolutely this will not improve in ANY WAY perfomance, because we do not loop
    // over these two functions. 	
    $gui->tcasePrefix = $mgrPool->tproject->getTestCasePrefix($argsObj->tproject_id);
    
    $build_info = $buildMgr->get_by_id($argsObj->build_id);
    $gui->build_notes=$build_info['notes'];
    $gui->build_is_open=($build_info['is_open'] == 1 ? 1 : 0);
    $gui->execution_types = testcase::get_execution_types();

    if($argsObj->filter_assigned_to)
    {
    	$userSet = tlUser::getByIds($dbHandler,array_values($argsObj->filter_assigned_to));
    	if ($userSet)
    	{
    	    foreach($userSet as $key => $userObj) 
    	    {
    	        $gui->ownerDisplayName[$key] = $userObj->getDisplayName();
    	    }    
    	}    
    }
    // ------------------------------------------------------------------

    $the_builds = $mgrPool->tplan->get_builds_for_html_options($argsObj->tplan_id);
    $gui->build_name = isset($the_builds[$argsObj->build_id]) ? $the_builds[$argsObj->build_id] : '';

    $gui->grants = initializeRights($dbHandler,$argsObj->user,$argsObj->tproject_id,$argsObj->tplan_id);
    $gui->exec_mode = initializeExecMode($dbHandler,$gui->cfg->exec_cfg,
                                         $argsObj->user,$argsObj->tproject_id,$argsObj->tplan_id);



    $rs = $mgrPool->tplan->get_by_id($argsObj->tplan_id);
    $gui->testplan_notes = $rs['notes'];


    // Important note: 
    // custom fields for test plan can be edited ONLY on design, that's reason why we are using 
    // scope = 'design' instead of 'execution'
    $gui->testplan_cfields = $mgrPool->tplan->html_table_of_custom_field_values($argsObj->tplan_id,'design',
                                                                          array('show_on_execution' => 1));
    
    $gui->history_on = manage_history_on($_REQUEST,$_SESSION,$gui->cfg->exec_cfg,
                                         'btn_history_on','btn_history_off','history_on');
    $gui->history_status_btn_name = $gui->history_on ? 'btn_history_off' : 'btn_history_on';

    $dummy = $platformMgr->getLinkedToTestplan($argsObj->tplan_id);
    $gui->has_platforms = !is_null($dummy) ? 1 : 0;
    
    $gui->platform_info['id']=0;
    $gui->platform_info['name']='';
    if(!is_null($argsObj->platform_id) && $argsObj->platform_id > 0 )
    { 
    	$gui->platform_info = $platformMgr->getByID($argsObj->platform_id);
    }
    $gui->node_id = $argsObj->id;

    
    $gui->showBuildColumn = ($gui->history_on == 0 || $gui->cfg->exec_cfg->show_history_all_builds);
    $gui->showPlatformColumn = ($gui->has_platforms && 
				                        ($gui->history_on == 0 || $gui->cfg->exec_cfg->show_history_all_platforms));
    return $gui;
}


/*
  function: processTestCase

  args :
  
  returns: 

  rev: 
  
*/
function processTestCase($tcase,&$guiObj,&$argsObj,$linked_tcversions,&$mgrPool)
{     

  // IMPORTANT due to platform feature
  // every element on linked_tcversions will be an array.
  $cf_filters = array('show_on_execution' => 1);
  $guiObj->design_time_cfields='';
  $guiObj->testplan_design_time_cfields='';
  
  $tcase_id = isset($tcase['tcase_id']) ? $tcase['tcase_id'] : $argsObj->id;
  $items_to_exec[$tcase_id] = $linked_tcversions[$tcase_id][0]['tcversion_id'];    
  $tcversion_id = isset($tcase['tcversion_id']) ? $tcase['tcversion_id'] : $items_to_exec[$tcase_id];
 
  $link_id = $linked_tcversions[$tcase_id][0]['feature_id'];
  $guiObj->tcAttachments[$tcase_id] = $mgrPool->tcase->getAttachmentInfos($tcase_id);

  $tsuiteID = $mgrPool->tcase->getTestSuiteID($tcase_id);  
	// $guiObj->tSuiteAttachments[$tsuiteID] = $mgrPool->tsuite->getAttachmentInfos($tsuiteID);
	$dummy = $mgrPool->tsuite->getAttachmentInfos($tsuiteID);
  new dBug($dummy);

  // --------------------------------------------------
  $attach = new stdClass();
  $attach->itemID = $tsuiteID;
  $attach->dbTable = $mgrPool->tsuite->getAttachmentTableName();
  $attach->infoSet = null;
  $attach->gui = null;
  list($attach->infoSet,$attach->gui) = $mgrPool->tsuite->buildAttachSetup($attach->itemID);
  $attach->gui->display = $attach->gui->downloadOnly = true;
  
  $attach->enabled = $attach->gui->enabled;
  $guiObj->tSuiteAttachments[$tsuiteID] = $attach;
  new dBug($attach);
  
  
  // -----------------------------------------------------------------  


  $locationFilters = testcase::buildCFLocationMap();
	foreach($locationFilters as $locationKey => $filterValue)
	{
		$finalFilters = $cf_filters+$filterValue;
    $guiObj->design_time_cfields[$tcase_id][$locationKey] = 
  		         $mgrPool->tcase->html_table_of_custom_field_values($tcase_id,'design',$finalFilters,null,null,
  		         											                              $argsObj->tproject_id,null,$tcversion_id);
    	
   	$guiObj->testplan_design_time_cfields[$tcase_id] = 
  		         $mgrPool->tcase->html_table_of_custom_field_values($tcversion_id,'testplan_design',$cf_filters,
  		                                                            null,null,$argsObj->tproject_id,null,$link_id);

  }

  if($guiObj->grants->execute)
  {
  	   $guiObj->execution_time_cfields[$tcase_id] = 
  	            $mgrPool->tcase->html_table_of_custom_field_inputs($tcase_id,null,'execution',"_{$tcase_id}",null,
  	                                                               null,$argsObj->tproject_id);
  }
  return array($tcase_id,$tcversion_id);
}




/*
  function: getLastExecution

  args :
  
  returns: 

*/
function getLastExecution(&$dbHandler,$tcase_id,$tcversion_id,$guiObj,$argsObj,&$tcaseMgr,$stdImages)
{      
	  $options =array('getNoExecutions' => 1, 'groupByBuild' => 0);
    $last_exec = $tcaseMgr->get_last_execution($tcase_id,$tcversion_id,$argsObj->tplan_id,
                                               $argsObj->build_id,$argsObj->platform_id,$options);
    
    if( !is_null($last_exec) )
    {
        $last_exec = setTesterAssignment($dbHandler,$last_exec,$tcaseMgr,
                                         $argsObj->tplan_id,$argsObj->platform_id, $argsObj->build_id);
        
        // Warning: setCanExecute() must be called AFTER setTesterAssignment()  
        $can_execute = $guiObj->grants->execute && ($guiObj->build_is_open);
        $last_exec = setCanExecute($last_exec,$guiObj->exec_mode,$can_execute,$argsObj->user_id);

        // do we need this ?
        $last_exec = testcase::addExecIcons($last_exec,$stdImages,'bizzare');
    }
   
    // Reorder executions to mantaing correct visualization order.
    if( is_array($tcversion_id) )
    {
      $last_exec = reorderExecutions($tcversion_id,$last_exec);
    }
    return $last_exec;
}



/*
  function: getOtherExecutions

  args :
  
  returns: 

  rev: 
*/
function getOtherExecutions(&$dbHandler,$tcase_id,$tcversion_id,$guiObj,$argsObj,&$tcaseMgr,$stdImages)
{      
    $other_execs = null;
    if($guiObj->history_on)
    {
		  $filters['build_id'] = $argsObj->build_id;
      $filters['platform_id'] = $argsObj->platform_id;
      
      if($guiObj->cfg->exec_cfg->show_history_all_builds )
      {
        $filters['build_id'] = ANY_BUILD;
      }  
      if($guiObj->cfg->exec_cfg->show_history_all_platforms )
      {
        $filters['platform_id'] = null;
      }  
      $options = array('exec_id_order' => $guiObj->cfg->exec_cfg->history_order);
      $other_execs = $tcaseMgr->get_executions($tcase_id,$tcversion_id,$argsObj->tplan_id,
                                               $filters['build_id'],$filters['platform_id'],$options);
    }    
    else
    {
      // Warning!!!:
      // we can't use the data we have got with previous call to get_last_execution()
      // because if user have asked to save results last execution data may be has changed
      $aux_map = $tcaseMgr->get_last_execution($tcase_id,$tcversion_id,$argsObj->tplan_id,
                                               $argsObj->build_id,$argsObj->platform_id);

      if(!is_null($aux_map))
      {
          $other_execs = array();
          foreach($aux_map as $key => $value )
          {
             $other_execs[$key] = array($value);
          }
      }
    }
    
    return !is_null($other_execs) ? testcase::addExecIcons($other_execs,$stdImages) : $other_execs;
}


/*
  function: processTestSuite

  args :
  
  returns: 


*/
function processTestSuite(&$dbHandler,&$guiObj,&$argsObj,$linked_tcversions,
                          &$treeMgr,&$tcaseMgr,&$docRepository)
{
  $locationFilters=$tcaseMgr->buildCFLocationMap();
  $testSet = new stdClass();
  $cf_filters = array('show_on_execution' => 1); 
  
  $tsuite_mgr=new testsuite($dbHandler); 
  $tsuite_data = $tsuite_mgr->get_by_id($argsObj->id);
  $opt = array('write_button_only_if_linked' => 1, 'prune_unlinked_tcversions' => 1);

  // why here we do not have filtered by tester ?
  // same for platform_id
  $filters = array('keywords' => $argsObj->keyword_id);
  $out = gen_spec_view($dbHandler,'testplan',$argsObj->tplan_id,$argsObj->id,$tsuite_data['name'],
                       $linked_tcversions,null,$filters,$opt);
       
  $testSet->tcase_id = array();
  $testSet->tcversion_id = array();
  foreach($out['spec_view'] as $key => $value)
  {
   if( count($value['testcases']) > 0 )
   {
     foreach($value['testcases'] as $xkey => $xvalue)
     {
       $testSet->tcase_id[]=$xkey;
       $testSet->tcversion_id[]=$xvalue['linked_version_id'];
     }  
   }
  }
   
  // Get the path for every test case, grouping test cases that have same parent.
  if( ($testCaseQty = count($testSet->tcase_id)) > 0 )
  {
		$dummy = $tcaseMgr->cfield_mgr->getLocations();
		$verboseLocationCode = array_flip($dummy['testcase']);
		$filters=null;
    foreach($verboseLocationCode as $key => $value)
    {
      $filters[$key]['location']=$value;
    }	     

		$dummy_id = current($testSet->tcase_id);
		$index = $testCaseQty == 1 ? $dummy_id : 0;  // 0 => BULK
		$suffix = '_' . $index;
		$execution_time_cfields = 
    $tcaseMgr->html_table_of_custom_field_inputs($dummy_id,$argsObj->tproject_id,'execution',$suffix,
	        	                               			 null,null,$argsObj->tproject_id);
		
		$guiObj->execution_time_cfields[$index] = $execution_time_cfields;
    $gdx=0;
    foreach($testSet->tcase_id as $testcase_id)
    {
      $path_f = $treeMgr->get_path($testcase_id,null,'full');
      foreach($path_f as $key => $path_elem)
      {
        if( $path_elem['parent_id'] == $argsObj->id )
        {
          // Can be added because is present in the branch the user wants to view
          // ID of branch starting node is in $argsObj->id
          $guiObj->tcAttachments[$testcase_id] = getAttachmentInfos($docRepository,$testcase_id,
                                                                    'nodes_hierarchy',true,1);
                	
	        foreach($locationFilters as $locationKey => $filterValue)
	        {
            $finalFilters=$cf_filters+$filterValue;
       			$guiObj->design_time_cfields[$testcase_id][$locationKey] = 
     				$tcaseMgr->html_table_of_custom_field_values($testcase_id,'design',$finalFilters,null,null,
  		         											                     $argsObj->tproject_id,null,$testSet->tcversion_id[$gdx]);

            $guiObj->testplan_design_time_cfields[$testcase_id] = 
  	            		        $tcaseMgr->html_table_of_custom_field_values($testcase_id,'testplan_design',$cf_filters,
  	            		                                                     null,null,$argsObj->tproject_id);
            			                                                                        
          }	                     

        	if($guiObj->grants->execute)
          {
            $guiObj->execution_time_cfields[$testcase_id] = 
            $tcaseMgr->html_table_of_custom_field_inputs($testcase_id, null,'execution', "_" . $testcase_id,null,null,
            				                                     $argsObj->tproject_id);
          }
        } // if( $path_elem['parent_id'] == $argsObj->id )
            	
        // We do this because do not know if some test case not yet analised will be direct
        // child of this test suite, then we get this info in advance.
        // In situations where only last test suite on branch have test cases, we are colleting
        // info we will never use.
        if($path_elem['node_table'] == 'testsuites' && !isset($guiObj->tSuiteAttachments[$path_elem['id']]))
        {
          $guiObj->tSuiteAttachments[$path_elem['id']] = 
            	   		getAttachmentInfos($docRepository,$path_elem['id'],'nodes_hierarchy',true,1);
        }
            	   
      } //foreach($path_f as $key => $path_elem) 
      $gdx++;
    }  
  }
  return array($testSet->tcase_id,$testSet->tcversion_id);  
}


function launchRemoteExec(&$dbHandler,&$argsObj,$tcasePrefix,&$mgrPool,&$tcaseMgr)
{
		// IMPORTANT NOTICE
		// Remote execution will NOT use ANY of data typed by user,
		// - notes
		// - custom fields
		//
		// IMPORTANT NOTICE
		// need to understand what to do with feedback provided
		// by do_remote_execution().
		// Right now no matter how things go, no feedback is given to user.
		// May be this need to be improved in future.
		//
		// Only drawback i see is when remote exec is done on a test suite
		// and amount of feedback can be high, then do not see what can be effect
		// on GUI


		$execContext = buildExecContext($argsObj,$tcasePrefix,$tplanMgr,$tcaseMgr);
		$feedback = do_remote_execution($dbHandler,$execContext);
		$feedback = current($feedback);
		return $feedback;
}


function  processSaveAndNext(&$mgrPool,$argsObj,$guiObj,$tcversionID)
{
  $identity = null;
  $nextItem = $mgrPool->tplan->getTestCaseNextSibling($argsObj->tplan_id,$tcversionID,$argsObj->platform_id);
  while (!is_null($nextItem) && !in_array($nextItem['tcase_id'], $argsObj->testcases_to_show)) 
  {
  	$nextItem = $mgrPool->tplan->getTestCaseNextSibling($argsObj->tplan_id,$nextItem['tcversion_id'],
  	                                                    $argsObj->platform_id);
  }
  
  if( !is_null($nextItem) )
  {
  	// Save and Next - Issues with display CF for test plan design - always EMPTY	
  	// need info about this test case => need to update linked_tcversions info
  	$identity = array('id' => $nextItem['tcase_id'], 'version_id' => $nextItem['tcversion_id']);
  	list($lt,$xdm) = getLinkedItems($argsObj,$guiObj->history_on,$cfg,$tcase_mgr,$tplan_mgr,$identity);
  	processTestCase($nextItem,$guiObj,$argsObj,$cfg,$lt,$mgr);
  }
  return $identity;
}

function createManagers($dbHandler,$tprojectID)
{
  $is = new stdClass();
  $is->tree = new tree($dbHandler);
  $is->tproject = new testproject($dbHandler);
  $is->tsuite = new testsuite($dbHandler);
  $is->tplan = new testplan($dbHandler);
  $is->tcase = new testcase($dbHandler);
  $is->req = new requirement_mgr($dbHandler);
  
  $is->repository = tlAttachmentRepository::create($dbHandler);
  $is->exec_cfield = new exec_cfield_mgr($dbHandler,$tprojectID);

  return $is;
}



function getLinkedItems($argsObj,$historyOn,$cfgObj,$mgrPool,$identity=null)
{          
	$ltcv = null;
	$idCard = null;
	$itemSet = null;
	if( !is_null($identity) )
	{
		$idCard = $identity;	
	}
	else if(!is_null($argsObj->tc_id) && !is_array($argsObj->tc_id) )
	{
		$idCard = array('id' => $argsObj->tc_id, 'version_id' => $argsObj->version_id);
	}

	if( !is_null($idCard) )
	{
		$execContext = array('tplan_id' => $argsObj->tplan_id,
							           'platform_id' => $argsObj->platform_id,
							           'build_id' => $argsObj->build_id);		

		$ltcv = null;
		if($historyOn)
		{
			$execContext['testplan_id'] = $argsObj->tplan_id;
			$ltcv = $mgrPool->tcase->getExecutionSet($idCard['id'],null,$execContext);
		}

		// lazy implementation:
		// getExecutionSet() returns data ONLY for Statuses that are written ON DB,
		// then if full history for test case is NOT RUN, we are doomed!!
		if(!$historyOn || is_null($ltcv))
		{
			$opt = null;
			$ltcv = $mgrPool->tcase->getLatestExecSingleContext($idCard,$execContext,$opt);
		}
	}
	else
	{
		// -----------------------------------------------------------
		// When nullify filter_status - DO NOT REMOVE THIS INFO      -
		// 
		// May be in the following situation we do not HAVE to apply filter status:
		// 1. User have filter for Not Run on Tree
		// 2. Clicks on TC XXX
		// 3. Executes TC
		// 4. DO NOT UPDATE TREE.
		//    we do not update automatically to avoid:
		//    a) performance problems
		//    b) delays on operations due to tree redraw
		//    c) loose tree status due to lack of feature of tree engine
		//
		// 5. Clicks again on TC XXX
		// If we use filter, we will get No Data Available.
		//
		// When working on show_testsuite_contents mode (OLD MODE) when we show
		// all testcases inside a testsuite that verifies a filter criteria WE NEED TO APPLY FILTER
		//
		// We do not have this problem when this page is called after user have executed,
		// probably because filter_status is not send back.
		//
		// I will add logic to nullify filter_status on init_args()
		// 
		$options = array('only_executed' => true, 'output' => $historyOn ? 'mapOfArray' : 'mapOfMap',
						         'include_unassigned' => $argsObj->include_unassigned,
						         'group_by_build' => 'add_build','last_execution' => !$historyOn);
		
		if(is_null($argsObj->filter_status) || in_array($cfgObj->tc_status['not_run'],$argsObj->filter_status))
		{
		    $options['only_executed'] = false;
		}


		// args->tsuites_id: is only used when user click on a test suite.
		//                   probably is used only when bulk execution is enabled.
		//
		// if args->tc_id is not null, theorically all other filters are useless.
		// why ?
		// Because will normally call this script, from the execution tree and if we can click
		// on a tree node, this means it has passed all filters.
		//
		//
		// $args->platform_id: needed to get execution status info
		// $args->build_id: needed to get execution status info
		//
		$basic_filters = array('tcase_id' => $argsObj->tc_id, 'platform_id' => $argsObj->platform_id,
							             'build_id' => $argsObj->build_id);
		
		// This filters are useful when bulk execution is enabled, 
		// and user do click on a test suite on execution tree.
		$bulk_filters = array('keyword_id' => $argsObj->keyword_id,'assigned_to' => $argsObj->filter_assigned_to, 
						              'exec_status' => $argsObj->filter_status,'cf_hash' => $argsObj->cf_selected,
		                      'tsuites_id' => $argsObj->tsuite_id,
		                      'assigned_on_build' => $argsObj->build_id);
		
		// CRITIC / IMPORTANT 
		// With BULK Operation enabled, we prefer to display Test cases tha are ONLY DIRECT CHILDREN
		// of test suite id => we do not do deep walk.
		// Think is a good choice, to avoid retrieving lot of info.
		// May be we need to add a config parameter (or better an option at GUI level)
		// in order to allow use how he / she wants to work.
		//
		$filters = array_merge($basic_filters,$bulk_filters);
		if( !is_null($sql2do = $mgrPool->tplan->getLinkedForExecTree($argsObj->tplan_id,$filters,$options)) )
		{
			if( is_array($sql2do) )
			{				
				if( isset($filters['keyword_filter_type']) && ($filters['keyword_filter_type'] == 'And') )
				{ 
					$kmethod = "fetchRowsIntoMapAddRC";
					$unionClause = " UNION ALL ";
				}
				else
				{
					$kmethod = "fetchRowsIntoMap";
					$unionClause = ' UNION ';
				}
				$sql2run = $sql2do['exec'] . $unionClause . $sql2do['not_run'];
			}
			else
			{
				$sql2run = $sql2do;
			}
			
			// Development Notice: 
			// CUMULATIVE is used only to create same type of datastructe that existed
			// before this refactoring
			//
			$ltcv = $tex = $mgrPool->tcase->db->$kmethod($sql2run,'tcase_id');
			if(!is_null($tex))
			{
				foreach($tex as $xkey => $xvalue)
        {
		      $itemSet->tcase_id[]=$xkey;
		      $itemSet->tcversion_id[]=$xvalue['tcversion_id'];
        }  
			}
		}
	}
             
  return array($ltcv,$itemSet);         
}

function getFromSessionPool(&$argsObj,$token)
{
  $mode = 'execution_mode';
  $pool = isset($_SESSION[$mode]) && isset($_SESSION[$mode][$token]) ? $_SESSION[$mode][$token] : null;

	$key2null = array('filter_status' => 'filter_result_result','filter_assigned_to' => 'filter_assigned_user', 
					          'build_id' => 'setting_build', 'platform_id' => 'setting_platform');
	foreach($key2null as $key => $sessionKey)
	{
		$argsObj->$key = isset($pool[$sessionKey]) ? $pool[$sessionKey] : null;
		// let this page be functional withouth a form token too (when called from testcases assigned to me)
		if (is_null($argsObj->$key)) 
		{
			$argsObj->$key = isset($_REQUEST[$sessionKey]) ? $_REQUEST[$sessionKey] : null;
		}
	}

  $int2get = array('build_id','platform_id');
  foreach($int2get as $prop)
  {
    if(is_null($argsObj->$prop)) 
    {
		  $argsObj->$prop = (isset($_REQUEST[$prop]) && is_numeric($_REQUEST[$prop])) ? intval($_REQUEST[$prop]) : 0;
    }
  }           
  
	$argsObj->keyword_id = 0;
	if (isset($pool['filter_keywords'])) 
  {
		$argsObj->keyword_id = $pool['filter_keywords'];
		if (is_array($argsObj->keyword_id) && count($argsObj->keyword_id) == 1) 
		{
			$argsObj->keyword_id = $argsObj->keyword_id[0];
		}
	}
	
	$argsObj->keywordsFilterType = null;
	if (isset($pool['filter_keywords_filter_type'])) 
	{
		$argsObj->keywordsFilterType = $pool['filter_keywords_filter_type'];
	}

  // Checkbox
  $argsObj->include_unassigned = isset($pool['filter_assigned_user_include_unassigned']) && 
                                 $pool['filter_assigned_user_include_unassigned'] != 0 ? 1 : 0;
	
	
  // changed refresh tree logic to adapt behavior of other forms (like tc edit)
  // additionally modified to only refresh on saving of test results, not on every click
  $argsObj->refreshTree = isset($pool['setting_refresh_tree_on_action']) ? 
                          $pool['setting_refresh_tree_on_action'] : 0;
	

 	$argsObj->testcases_to_show = null;
	if (isset($pool['testcases_to_show'])) 
	{
		$argsObj->testcases_to_show = $pool['testcases_to_show'];
	}
 
}


function buildCookies(&$argsObj,$cfgObj)
{
  $cookiePrefix = 'TL_execSetResults_';
     
  // IMPORTANT: logic for test suite notes CAN NOT BE IMPLEMENTED HERE
  //            see smarty_assign_tsuite_info() in this file.  
  $key4cookies = array('tpn_view_status' => 'testplan_notes','bn_view_status' => 'build_description',
                       'platform_notes_view_status' => 'platform_description');
    
	$key2loop = array('id' => 0, 'exec_to_delete' => 0, 'version_id' => 0, 'tpn_view_status' => 0, 
					          'bn_view_status' => 0, 'bc_view_status' => 1,'platform_notes_view_status' => 0);
	foreach($key4cookies as $key => $cfgKey)
	{
		$cookieKey = $cookiePrefix . $key;
		if( !isset($_REQUEST[$key]) )
		{
			// First time we are entered here => we can need to understand how to proceed
		    switch($cfgObj->exec_cfg->expand_collapse->$cfgKey )
		    {
		    	case LAST_USER_CHOICE:
					if (isset($_COOKIE[$cookieKey]) ) 
    				{
    					$key2loop[$key] = $_COOKIE[$cookieKey];
					}
				break;	

				default:
					$key2loop[$key] = $cfgObj->exec_cfg->expand_collapse->$cfgKey;
				break;
		    } 
		}
  }
    				            
	foreach($key2loop as $key => $value)
	{
 		$argsObj->$key = isset($_REQUEST[$key]) ? intval($_REQUEST[$key]) : $value;
    if( isset($key4cookies[$key]) )
		{
			setcookie($cookiePrefix . $key,$argsObj->$key,TL_COOKIE_KEEPTIME, '/');
		}
	}

}


function setUpForBulkExec($guiObj)
{  
  $guiObj->execStatusValues = testcase::createExecutionResultsMenu();
  if( isset($guiObj->execStatusValues[$guiObj->cfg->tc_status['all']]) )
  {
      unset($guiObj->execStatusValues[$guiObj->cfg->tc_status['all']]);
  }
  
  $of = web_editor("bulk_exec_notes",$_SESSION['basehref'],$guiObj->cfg->editorCfg);
  $of->Value = getItemTemplateContents('execution_template', $of->InstanceName, null);
  
  // Magic numbers that can be determined by trial and error
  $guiObj->bulk_exec_notes_editor = $of->CreateHTML(10,60);         
  unset($of);    
}

function builGuiUsers(&$dbHandler,$itemSet)
{
  unset($itemSet['']);
  $userSet = null;
  if ($itemSet)
  {
  	foreach($itemSet as $value)
  	{		
  		$userSet[] = $value;
  	}
  }
  return (tlUser::getByIDs($dbHandler,$userSet,'id'));
}
?>