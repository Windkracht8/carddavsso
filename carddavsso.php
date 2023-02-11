<?php
require_once (dirname(__FILE__).'/carddavsso_dav.php');

class carddavsso extends rcube_plugin{

	function init(){
		$rc = rcube::get_instance();
		if($rc->get_user_name() == ""){return;} // Wait until user is logged in

		// load configuration
		$this->load_config();
		
		$this->add_hook('startup', array($this, 'startup'));
		
		$this->add_hook('contact_create', array($this, 'contact_create'));
		$this->add_hook('contact_update', array($this, 'contact_update'));
		$this->add_hook('contact_delete', array($this, 'contact_delete'));

		$this->add_hook('group_addmembers', array($this, 'group_addmembers'));
		$this->add_hook('group_delmembers', array($this, 'group_delmembers'));
		$this->add_hook('group_create', array($this, 'group_create'));
		$this->add_hook('group_rename', array($this, 'group_rename'));
		$this->add_hook('group_delete', array($this, 'group_delete'));
		
		$this->add_hook('user_delete', array($this, 'user_delete'));
	}
	function startup($params){
		carddavsso_dav::recover('0');
		carddavsso_dav::sync('0');
		return $params;
	}
	function contact_create($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_create($parameters);
	}
	function contact_update($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_update($parameters);
	}
	function contact_delete($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_delete($parameters);
	}
	function group_addmembers($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_groupaddmembers($parameters);
	}
	function group_delmembers($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_groupdelmembers($parameters);
	}
	function group_create($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_groupcreate($parameters);
	}
	function group_rename($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_grouprename($parameters);
	}
	function group_delete($parameters){
		if($parameters['source'] != "0"){return;}
		return carddavsso_dav::fromlocal_groupdelete($parameters);
	}
	function user_delete($args){
		carddavsso_db::get_instance()->del_user($args['user']->ID);
	}

}
