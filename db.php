<?php

class carddavsso_db{
	static private $instance;
	private $username;
	private $dbh;
	private $prefix;
	
	static function get_instance(){
		if(!self::$instance){self::$instance = new carddavsso_db();}
		return self::$instance;
	}
	private function __construct(){
		$rc = rcube::get_instance();
		$this->username = $rc->get_user_name();
		$this->dbh = rcmail::get_instance()->db;
		$this->prefix = $rc->config->get("db_prefix", "");
	}
	
	public function get_abook_id($abook_id){
		$sql = "SELECT * FROM ".$this->prefix."carddavsso_contactsync WHERE username = ? AND abook_id = ?";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return $this->dbh->fetch_assoc($sql_result);
	}
	public function get_abook_davurl($dav_url){
		$sql = "SELECT * FROM ".$this->prefix."carddavsso_contactsync WHERE username = ? AND dav_url = ?";
		$sql_result = $this->dbh->query($sql, array($this->username, $dav_url));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return $this->dbh->fetch_assoc($sql_result);
	}
	public function set_abook_token($abook_id, $token){
		$sql = "INSERT INTO ".$this->prefix."carddavsso_contactsync (username, abook_id, token) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token)";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id, $token));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
	}
	public function set_abook_lastsync($abook_id, $lastsync){
		$sql = "INSERT INTO ".$this->prefix."carddavsso_contactsync (username, abook_id, lastsync) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE lastsync = VALUES(lastsync)";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id, $lastsync));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
	}
	public function set_abook_lastrecover($abook_id, $lastrecover){
		$sql = "INSERT INTO ".$this->prefix."carddavsso_contactsync (username, abook_id, lastrecover) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE lastrecover = VALUES(lastrecover)";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id, $lastrecover));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
	}

	public function get_contact_id($abook_id, $contact_id){
		$sql = "SELECT * FROM ".$this->prefix."carddavsso_contacts WHERE username = ? AND abook_id = ? AND contact_id = ?";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id, $contact_id));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return $this->dbh->fetch_assoc($sql_result);
	}
	public function get_contactdatas($abook_id){
		$sql = "SELECT * FROM ".$this->prefix."carddavsso_contacts WHERE username = ? AND abook_id = ?";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id));
		for($res = array(); $temp = $this->dbh->fetch_assoc($sql_result);){$res[] = $temp;}
		return $res;
	}

	public function get_contact_davurl($abook_id, $dav_url){
		$sql = "SELECT * FROM ".$this->prefix."carddavsso_contacts WHERE username = ? AND abook_id = ? AND dav_url = ?";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id, $dav_url));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return $this->dbh->fetch_assoc($sql_result);
	}

	public function set_contact($abook_id, $contact_id, $dav_id, $dav_url, $etag){
		$sql = 	"INSERT INTO ".$this->prefix."carddavsso_contacts"
					." (username, abook_id, contact_id, dav_id, dav_url, etag) VALUES(?, ?, ?, ?, ?, ?)"
					." ON DUPLICATE KEY UPDATE dav_id=VALUES(dav_id), dav_url=VALUES(dav_url), etag=VALUES(etag)";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id, $contact_id, $dav_id, $dav_url, $etag));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
	}

	public function del_contact($abook_id, $contact_id){
		$sql = "DELETE FROM ".$this->prefix."carddavsso_contacts WHERE username = ? AND abook_id = ? AND contact_id = ?";
		$sql_result = $this->dbh->query($sql, array($this->username, $abook_id, $contact_id));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return $sql_result;
	}
	
	private function handle_error($error){
		if(strpos($error, "Table") !== false
			&& strpos($error, "doesn't exist") !== false
		){
			if(strpos($error, "carddavsso_contactsync") !== false){
				rcube::raise_error(array('code' => 600, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Sync table does not exist, create it"), true, false);
				$this->create_table_contactsync();
			}else if(strpos($error, "carddavsso_contacts") !== false){
				rcube::raise_error(array('code' => 600, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Sync table does not exist, create it"), true, false);
				$this->create_table_contact();
			}else{
				rcube::raise_error(array('code' => 600, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Unkown table does not exists: $error"), true, false);
			}
		}else{
			rcube::raise_error(array('code' => 600, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error in executing db query: $error"), true, false);
		}
	}
	private function create_table_contactsync(){
		$create_db_sync = 
			"CREATE TABLE IF NOT EXISTS ".$this->prefix."carddavsso_contactsync(".
				"username VARCHAR(255),abook_id VARCHAR(255),token VARCHAR(255),lastsync INT,lastrecover INT".
				",UNIQUE KEY unique_index(username,abook_id));";
		$sql_result = $this->dbh->query($create_db_sync);
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
	}
	private function create_table_contact(){
		$create_db_contacts = 
			"CREATE TABLE IF NOT EXISTS ".$this->prefix."carddavsso_contacts(".
				"username VARCHAR(255),abook_id VARCHAR(255),contact_id INT,dav_id VARCHAR(255),dav_url VARCHAR(255),etag VARCHAR(255)".
				",UNIQUE KEY unique_index(username,abook_id,contact_id));";
		$sql_result = $this->dbh->query($create_db_contacts);
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
	}
}
