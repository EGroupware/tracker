<?php

	/**
	 * eGroupWare - Setup
	 * http://www.egroupware.org 
	 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
	 *
	 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
	 * @package tracker
	 * @subpackage setup
	 * @version $Id$
	 */

	$test[] = '0.1.005';
	function tracker_upgrade0_1_005()
	{
		$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker','tr_budget',array(
			'type' => 'decimal',
			'precision' => '20',
			'scale' => '2'
		));
		$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_resolution',array(
			'type' => 'char',
			'precision' => '1',
			'default' => ''
		));

		return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.006';
	}


	$test[] = '0.1.006';
	function tracker_upgrade0_1_006()
	{
		$GLOBALS['egw_setup']->oProc->CreateTable('egw_tracker_bounties',array(
			'fd' => array(
				'bounty_id' => array('type' => 'auto','nullable' => False),
				'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
				'bounty_creator' => array('type' => 'int','precision' => '4','nullable' => False),
				'bounty_created' => array('type' => 'int','precision' => '8','nullable' => False),
				'bounty_amount' => array('type' => 'decimal','precision' => '20','scale' => '2','nullable' => False),
				'bounty_name' => array('type' => 'varchar','precision' => '64'),
				'bounty_email' => array('type' => 'varchar','precision' => '128'),
				'bounty_confirmer' => array('type' => 'int','precision' => '4'),
				'bounty_confirmed' => array('type' => 'int','precision' => '8')
			),
			'pk' => array('bounty_id'),
			'fk' => array(),
			'ix' => array('tr_id'),
			'uc' => array()
		));

		return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.007';
	}


	$test[] = '0.1.007';
	function tracker_upgrade0_1_007()
	{
		$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_bounties','bounty_payedto',array(
			'type' => 'varchar',
			'precision' => '128'
		));
		$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_bounties','bounty_payed',array(
			'type' => 'int',
			'precision' => '8'
		));

		return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.008';
	}
    $test[] = '0.1.008';
    
    function tracker_upgrade0_1_008()
    {
        // Add configurable statis (stored as egw_tracker global cats)
        // Needs a int tr_status (migrate actual data to the new $stati array    
    	
		// Rename actual tr_status column
        $GLOBALS['egw_setup']->oProc->RenameColumn('egw_tracker','tr_status','char_tr_status');
        
        // Create the new (int) tr_status column
        $GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_status',array(
                'type' => 'int',
                'precision' => '4',
                'nullable' => False,
                'default'  => -100, // Open State
		));
	
        // Update the data
		//		'-100' => 'Open',
		//		'-101' => 'Closed',
		//		'-102' => 'Deleted',
		//		'-103' => 'Pending',
		$GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-100 where char_tr_status='o'",__LINE__,__FILE__);
        $GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-101 where char_tr_status='c'",__LINE__,__FILE__);
        $GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-102 where char_tr_status='d'",__LINE__,__FILE__);
        $GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-103 where char_tr_status='p'",__LINE__,__FILE__);
        			
		// Drop the old char tr_status column
		$GLOBALS['egw_setup']->oProc->DropColumn('egw_tracker',array(
			'fd' => array(
				'tr_id' => array('type' => 'auto','nullable' => False),
				'tr_summary' => array('type' => 'varchar','precision' => '80','nullable' => False),
				'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False),
				'cat_id' => array('type' => 'int','precision' => '4'),
				'tr_version' => array('type' => 'int','precision' => '4'),
				'tr_status' => array('type' => 'int','precision' => '4','default' => -100),
				'tr_description' => array('type' => 'text'),
				'tr_assigned' => array('type' => 'int','precision' => '4'),
				'tr_private' => array('type' => 'int','precision' => '2','default' => '0'),
				'tr_budget' => array('type' => 'decimal','precision' => '20','scale' => '2'),
				'tr_completion' => array('type' => 'int','precision' => '2','default' => '0'),
				'tr_creator' => array('type' => 'int','precision' => '4','nullable' => False),
				'tr_created' => array('type' => 'int','precision' => '8','nullable' => False),
				'tr_modifier' => array('type' => 'int','precision' => '4'),
				'tr_modified' => array('type' => 'int','precision' => '8'),
				'tr_closed' => array('type' => 'int','precision' => '8'),
				'tr_priority' => array('type' => 'int','precision' => '2','default' => '5'),
				'tr_resolution' => array('type' => 'char','precision' => '1','default' => '')
			),
			'pk' => array('tr_id'),
			'fk' => array(),
			'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_assigned',array('cat_id','tr_status','tr_assigned')),
			'uc' => array()
		),'char_tr_status');			

        return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.009';
    }
    
    $test[] = '0.1.009';
    function tracker_upgrade0_1_009()
    {
        // Add CC to tracker table    
        
        // Create the new (text) tr_cc column
        $GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_cc',array(
                'type' => 'text',
		));

        return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.010';
    }
    
    $test[] = '0.1.010';
    function tracker_upgrade0_1_010()
    {
         return $GLOBALS['setup_info']['tracker']['currentver'] = '1.4';
    }
?>
