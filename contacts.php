<?php
include_once 'db.php';

use Sabre\VObject;

class carddavsso_contacts{
	static private $instance;
	private $rc;
	private $db;
	private $username;
	private $password;
	private $abook_url;
	
	static function get_instance(){
		if(!self::$instance){self::$instance = new carddavsso_contacts();}
		return self::$instance;
	}
	private function __construct(){
		$this->rc = rcube::get_instance();
		$this->db = carddavsso_db::get_instance();
		$this->username = $this->rc->get_user_name();
		$this->password = $this->rc->get_user_password();
	}

	public function sync($abook_id){
		$abook_dbresult = $this->db->get_abook_id($abook_id);
		if(!isset($abook_dbresult['token']) || $abook_dbresult['token'] == ''){
			return; // Wait for recover to run first
		}
		if(isset($abook_dbresult['lastsync'])){
			$syncinterval = $this->rc->config->get("carddavsso_syncinterval", 5);
			if(time() < ($abook_dbresult['lastsync'] + $syncinterval)){
				return;// Not time yet to run another sync
			}
		}

		$this->db->set_abook_lastsync($abook_id, time());
		
		if(!$abook_url = $this->getUrlForBook($abook_id)){return;}
		$headers = array('Depth'=>'0', 'Content-type'=>'text/xml; charset="utf-8"');
		$body = '<?xml version="1.0" encoding="utf-8" ?><D:sync-collection xmlns:D="DAV:"><D:sync-token>%TOKEN%</D:sync-token><D:sync-level>infinite</D:sync-level></D:sync-collection>';
		$body = str_replace("%TOKEN%", $abook_dbresult['token'], $body);

		$response = $this->makeRequest($abook_url, 'REPORT', $headers, $body);
		if($response->code != "207"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during sync, wrong response code: ".$response->code), true, true);
		}
		$xmlDoc = new DOMDocument();
		if(!$xmlDoc->loadXML($response->raw_body)){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during sync, failed to process response as xml"), true, true);
		}

		$responses = $xmlDoc->getElementsByTagName('response');
		foreach($responses as $response){
			$hrefs = $response->getElementsByTagName('href');
			if(count($hrefs)!= 1){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during sync, response with ".count($hrefs)." href fields"), true, true);
			}
			$href = $hrefs[0]->nodeValue;

			$statuss = $response->getElementsByTagName('status');
			if(count($statuss)!= 1){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during sync, response with ".count($statuss)." status fields"), true, true);
			}
			$status = $statuss[0]->nodeValue;
			
			if(strpos($status, "200")){
				$this->fromdav_update($abook_id, $href);
			}elseif(strpos($status, "404")){
				$this->fromdav_delete($abook_id, $href);
			}else{
				rcube::raise_error(array('code' => $status, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during sync, unkown status: $status for contact $href"), true, false);
			}
		}
		
		$tokens = $xmlDoc->getElementsByTagName('sync-token');
		if(count($tokens) != 1){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during sync, response with ".count($tokens)." sync-token fields"), true, true);
		}
		$this->db->set_abook_token($abook_id, $tokens[0]->nodeValue);
	}
	private function fromdav_delete($abook_id, $href){
		if(!$abook_url = $this->getUrlForBook($abook_id)){return;}
		$abook_url_lastpart = substr($abook_url,strrpos($abook_url,'/',-2));
		$dav_url = substr($href,strrpos($href,$abook_url_lastpart, -1)+strlen($abook_url_lastpart));
		
		$db_data = $this->db->get_contact_davurl($abook_id, $dav_url);
		if(!$db_data){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Failed to delete contact($dav_url), missing sync data"), true, true);
		}
		$contact_id = $db_data['contact_id'];

		$abook = $this->rc->get_address_book($abook_id);
		$result = $abook->delete(array($contact_id));
		if($result != "1"){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Failed to delete contact; local($contact_id); dav($dav_url)"), true, true);
		}
		$this->db->del_contact($abook_id, $contact_id);
	}
	private function fromdav_update($abook_id, $href){
		if(!$abook_url = $this->getUrlForBook($abook_id)){return;}
		$abook_url_lastpart = substr($abook_url,strrpos($abook_url,'/',-2));
		$dav_url = substr($href,strrpos($href,$abook_url_lastpart, -1)+strlen($abook_url_lastpart));
		$this->fromdav_update_davurl($abook_id, $dav_url);
	}
	private function fromdav_update_davurl($abook_id, $dav_url){
		if(!$abook_url = $this->getUrlForBook($abook_id)){return;}
		$save_data = $this->dav2rcube($abook_id, $dav_url);
		
		$abook = $this->rc->get_address_book($abook_id);
		$db_data = $this->db->get_contact_davurl($abook_id, $dav_url);
		if($db_data){
			$contact_id = $db_data['contact_id'];
			if($save_data['etag'] == $db_data['etag']){
				return $contact_id;
			}

			$success = $abook->update($contact_id, $save_data);
			if(!$success){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Failed to update contact; local($contact_id); dav($dav_url)"), true, false);
				return -1;
			}
			$this->db->set_contact($abook_id, $contact_id, $save_data['dav_id'], $dav_url, $save_data['etag']);
		}else{
			$contact_id = $abook->insert($save_data);
			if($contact_id < 0){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Failed to create contact; dav($dav_url)"), true, false);
				return -1;
			}
			$response = $this->makeRequest($abook_url.$dav_url, 'GET', array('Content-type'=>'text/vcard; charset="utf-8"'), "");
			$etag = isset($response->headers['etag']) ? $response->headers['etag'] : '';
			$this->db->set_contact($abook_id, $contact_id, $save_data['dav_id'], $dav_url, $etag);
		}

		$this->fromdav_putcontactingroups($abook, $contact_id, $save_data['groups']);
		return $contact_id;
	}
	private function fromdav_putcontactingroups($abook, $contact_id, $dav_groups){
		$local_groups = $abook->list_groups();
		if(is_array($dav_groups)){foreach($dav_groups as $dav_group){
			$local_group_id = -1;
			foreach($local_groups as $local_group){
				if($dav_group == $local_group['name']){
					$local_group_id = $local_group['ID'];
					break;
				}
			}
			if($local_group_id == -1){
				$local_group = $abook->create_group($dav_group);
				$local_group_id = $local_group['id'];
			}
			$abook->add_to_group($local_group_id, $contact_id);
		}}

		$db_groups = $abook->get_record_groups($contact_id);
		foreach($db_groups as $db_group_id => $db_group_name){
			if(!is_array($dav_groups) || !in_array($db_group_name, $dav_groups)){
				$abook->remove_from_group($db_group_id, $contact_id);
			}
		}
	}

	public function recover($abook_id){
		$abook_dbresult = $this->db->get_abook_id($abook_id);
		if(isset($abook_dbresult['lastrecover'])){
			$recoverinterval = $this->rc->config->get("carddavsso_recoverinterval", 300);
			if(time() < ($abook_dbresult['lastrecover'] + $recoverinterval)){
				return; // Not time yet to run recover again
			}
		}

		$this->db->set_abook_lastrecover($abook_id, time());

		// Step 1: Grab list of dav_urls from server
		if(!$abook_url = $this->getUrlForBook($abook_id)){return;}
		$abook_url_lastpart = substr($abook_url,strrpos($abook_url,'/',-2));

		$headers = array('Content-type'=>'text/xml; charset="utf-8"');
		$body = '<?xml version="1.0" encoding="utf-8" ?><C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"><D:prop><D:getetag /><C:address-data><C:prop name="FN"/></C:address-data></D:prop></C:addressbook-query>';

		$response = $this->makeRequest($abook_url, 'REPORT', $headers, $body);
		if($response->code != "207"){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover (".$this->username."), wrong response code: ".$response->code), true, true);
		}
		$xmlDoc = new DOMDocument();
		if(!$xmlDoc->loadXML($response->raw_body)){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover, failed to process response as xml"), true, true);
		}

		$contacts_dav = array();
		$responses = $xmlDoc->getElementsByTagName('response');
		foreach($responses as $response){
			$hrefs = $response->getElementsByTagName('href');
			if(count($hrefs)!= 1){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover, response with ".count($hrefs)." href fields"), true, true);
			}
			$href = $hrefs[0]->nodeValue;
			$dav_url = substr($href,strrpos($href,$abook_url_lastpart, -1)+strlen($abook_url_lastpart));
			
			$etags = $response->getElementsByTagName('getetag');
			if(count($etags)!= 1){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover, response with ".count($etags)." etag fields"), true, true);
			}
			$etag = $etags[0]->nodeValue;
			
			$addressdatas = $response->getElementsByTagName('address-data');
			if(count($addressdatas)!= 1){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover, response with ".count($addressdatas)." address-data fields"), true, true);
			}
			$name = preg_replace(array('/.*BEGIN:.*/', '/.*FN:/', '/.*END:.*/', '/\r/', '/\n/'), array('', '', '', '', ''), $addressdatas[0]->nodeValue);

			$contacts_dav[$dav_url] = array("etag" => $etag, "name" => $name);
		}

		// Step 2: Create a list of the local contacts
		$contacts_local = array();
		$abook = $this->rc->get_address_book($abook_id);
		foreach($abook->list_records(null, 2147483647)->records as $record){
			$contacts_local[$record['contact_id']] = $record['name'];
		}

		// Step 3: Loop db entries
		$contacts_db = $this->db->get_contactdatas($abook_id);
		foreach($contacts_db as $contact_db){
			$dav_url = $contact_db['dav_url'];
			$contact_id = $contact_db['contact_id'];
			if(isset($contacts_dav[$dav_url])){
				// Exists on dav
				if(isset($contacts_local[$contact_id])){
					// Exists on dav, and exists local
					if($contacts_dav[$dav_url]['etag'] != $contact_db['etag']){
						// Updated on dav
						$this->fromdav_update_davurl($abook_id, $dav_url);
					}
					unset($contacts_dav[$dav_url]);
					unset($contacts_local[$contact_id]);
				}else{
					// Exists on dav, does not exists local
					$this->db->del_contact($abook_id, $contact_id);
					$this->fromdav_update_davurl($abook_id, $dav_url);
					unset($contacts_dav[$dav_url]);
				}
			}else{
				// Removed from dav
				if(isset($contacts_local[$contact_id])){
					// Removed from dav, and exists local
					$result = $abook->delete(array($contact_id));
					if($result != "1"){
						rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Failed to remove local contact $contact_id"), true, false);
					}else{
						$this->db->del_contact($abook_id, $contact_id);
					}
				}else{
					// Removed from dav, and removed from local
					$this->db->del_contact($abook_id, $contact_id);
				}
			}
		}

		// Step 4: Loop remaining dav contacts, these were not found in the db
		foreach($contacts_dav as $dav_url => $contact_dav){
			$contact_id = -1;
			foreach($contacts_local as $contact_local_id => $contact_local_name){
				if($contact_dav['name'] == $contact_local_name){
					$contact_id = $contact_local_id;
					break;
				}
			}
			if($contact_id > -1){
				$this->db->set_contact($abook_id, $contact_id, "", $dav_url, "");
			}
			$contact_id = $this->fromdav_update_davurl($abook_id, $dav_url);
			if($contact_id == -1){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Failed to create or update local contact '".$contact_dav['name']."'"), true, true);
			}
			unset($contacts_local[$contact_id]);
		}

		// Step 5: Loop remaining local contacts, these were not in the db and not on dav
		foreach($contacts_local as $contact_id => $contact_name){
			$save_data = $this->fromlocal_getdata($abook_id, $contact_id);
			$this->fromlocal_upload($abook_id, $save_data, $contact_id);
		}

		$headers = array('Content-type'=>'text/xml; charset="utf-8"', 'Depth'=>'0');
		$body = '<?xml version="1.0" encoding="utf-8" ?><D:propfind xmlns:D="DAV:"><D:prop><D:sync-token /></D:prop></D:propfind>';

		$response = $this->makeRequest($abook_url, 'PROPFIND', $headers, $body);
		if($response->code != "207"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover, wrong response code: ".$response->code), true, true);
		}
		$xmlDoc = new DOMDocument();
		if(!$xmlDoc->loadXML($response->raw_body)){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover, failed to process response as xml"), true, true);
		}
		$synctokens = $xmlDoc->getElementsByTagName('sync-token');
		if(count($synctokens)!= 1){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error during recover, response with ".count($synctokens)." sync-token fields"), true, true);
		}
		$synctoken = $synctokens[0]->nodeValue;
		
		$this->db->set_abook_token($abook_id, $synctoken);
	}
	
	public function fromlocal_create($parameters){
		$parameters['abort'] = 1;
		$abook_id = $parameters['source'];

		$result = $this->fromlocal_upload($abook_id, $parameters['record'], null);
		if($result['error']){
			$parameters['message'] = $result['error'];
			return $parameters;
		}

		$abook = $this->rc->get_address_book($abook_id);
		$contact_id = $abook->insert($parameters['record']);

		$this->db->set_contact($abook_id, $contact_id, $result['dav_id'], $result['dav_url'], $result['etag']);

		$parameters['result'] = $contact_id;
		return $parameters;
	}
	private function generateUID($abook_url){
		$method = 'GET';
		$headers = array('Content-type'=>'text/vcard; charset="utf-8"');
		$uid = uniqid();
		$response = $this->makeRequest($abook_url.$uid.".vcf", $method, $headers, "");
		if($response->code == "404"){
			return $uid;
		}
		return $this->generateUID($abook_url);
	}
	private function fromlocal_getdata($abook_id, $contact_id){
		$abook = $this->rc->get_address_book($abook_id);
		$records = $abook->get_record($contact_id);
		$save_data = $records->records[0];
		$db_data = $this->db->get_contact_id($abook_id, $contact_id);
		if(isset($db_data['dav_id'])){$save_data['dav_id'] = $db_data['dav_id'];}
		if(isset($db_data['dav_url'])){$save_data['dav_url'] = $db_data['dav_url'];}
		if(isset($db_data['etag'])){$save_data['etag'] = $db_data['etag'];}
		
		$record_groups = $abook->get_record_groups($contact_id);
		$save_data['groups'] = trim(implode(",", $record_groups), ",");
		
		return $save_data;
	}
	private function fromlocal_upload($abook_id, $save_data, $contact_id){
		if(!$abook_url = $this->getUrlForBook($abook_id)){return "No url for abook on dav";}
		$dav_id = isset($save_data['dav_id']) ? $save_data['dav_id'] : $this->generateUID($abook_url);
		$dav_url = isset($save_data['dav_url']) ? $save_data['dav_url'] : $dav_id.".vcf";
		$headers = array('Content-type'=>'text/vcard; charset="utf-8"');
		$vcard = $this->rcube2dav($save_data);
		$vcard->UID = $dav_id;
		$body = $vcard->serialize();

		if(isset($save_data['etag'])){
			$headers['ETag'] = $save_data['etag'];
		}

		// Upload to dav
		$response = $this->makeRequest($abook_url.$dav_url, 'PUT', $headers, $body);
		if($response->code != "201" && $response->code != "204"){
			return array("error" => "Failed to upload contact to dav: ".$response->code.": $response");
		}
		// Get the etag
		$response = $this->makeRequest($abook_url.$dav_url, 'GET', $headers, "");
		$etag = isset($response->headers['etag']) ? $response->headers['etag'] : '';

		// Add the contact to the db
		if($contact_id){
			$this->db->set_contact($abook_id, $contact_id, $dav_id, $dav_url, $etag);
		}
		return array("dav_id" => $dav_id, "dav_url" => $dav_url, "etag" => $etag);
	}
	public function fromlocal_update($parameters){
		$abook_id = $parameters['source'];
		$contact_id = $parameters['id'];
		
		$db_data = $this->db->get_contact_id($abook_id, $contact_id);
		if(!isset($db_data['dav_id']) || !isset($db_data['dav_url'])){
			$parameters['message'] = "Failed to update contact: Did not find dav id in sync db";
			$parameters['abort'] = 1;
			return $parameters;
		}
		
		$save_data = $parameters['record'];

		$db_data = $this->db->get_contact_id($abook_id, $contact_id);
		$save_data['dav_id'] = $db_data['dav_id'];
		$save_data['dav_url'] = $db_data['dav_url'];

		$abook = $this->rc->get_address_book($abook_id);
		$record_groups = $abook->get_record_groups($contact_id);
		$save_data['groups'] = trim(implode(",", $record_groups), ",");

		// Grab the photo from the record if it is not provided in the parameters
		if(!isset($save_data['photo'])){
			$record_data = $abook->get_record($contact_id)->records[0];
			if(isset($record_data['photo'])){
				$save_data['photo'] = $record_data['photo'];
			}
		}

		$result = $this->fromlocal_upload($abook_id, $save_data, $contact_id);
		if($result['error']){
			$parameters['message'] = $result['error'];
			$parameters['abort'] = 1;
			return $parameters;
		}

		return $parameters;
	}
	public function fromlocal_delete($parameters){
		$abook_id = $parameters['source'];
		foreach($parameters['id'] as $contact_id){
			$result = $this->db->get_contact_id($abook_id, $contact_id);
			if(isset($result['dav_url']) && strlen($result['dav_url']) > 5){
				$dav_url = $result['dav_url'];
			}else{
				$parameters['message'] = "Failed to delete contact: Did not find dav identifier in the db";
				$parameters['abort'] = 1;
				return $parameters;
			}

			if(!$abook_url = $this->getUrlForBook($abook_id)){
				$parameters['message'] = "Failed to delete contact: Did not find DAV server url for addressbook";
				$parameters['abort'] = 1;
				return $parameters;
			}
			// Delete the contact from the server
			$response = $this->makeRequest($abook_url.$dav_url, 'DELETE', "", "");
			if($response->code != "204"){
				$parameters['message'] = "Failed to delete contact: ".$response->code.": $response";
				$parameters['abort'] = 1;
				return $parameters;
			}
		}
		return $parameters;
	}
	
	public function fromlocal_groupaddmembers($parameters){
		$group_id = $parameters['group_id'];
		$abook_id = $parameters['source'];
		$contact_ids = $parameters['ids'];
		$abook = $this->rc->get_address_book($abook_id);
		$group_name = $abook->get_group($group_id)['name'];
		
		foreach($contact_ids as $contact_id){
			$save_data = $this->fromlocal_getdata($abook_id, $contact_id);
			$save_data['groups'] = trim(implode(",",array($group_name, $save_data['groups'])),",");
			$result = $this->fromlocal_upload($abook_id, $save_data);
			if($result['error']){
				$parameters['message'] = "Failed to add $contact_id to $group_name on dav";
				$parameters['abort'] = 1;
				return $parameters;
			}
		}
		
		return $parameters;
	}
	public function fromlocal_groupdelmembers($parameters){
		// TODO: if this is the last, it causes an error in the logging
		$group_id = $parameters['group_id'];
		$abook_id = $parameters['source'];
		$contact_ids = $parameters['ids'];
		$abook = $this->rc->get_address_book($abook_id);
		$group_name = $abook->get_group($group_id)['name'];

		foreach($contact_ids as $contact_id){
			$save_data = $this->fromlocal_getdata($abook_id, $contact_id);
			$record_groups = $abook->get_record_groups($contact_id);
			$groups = "";
			foreach($record_groups as $record_group){
				if($record_group != $group_name){
					$groups .= "$record_group,";
				}
			}
			$save_data['groups'] = trim($groups, ",");
			
			$result = $this->fromlocal_upload($abook_id, $save_data, $contact_id);
			if($result['error']){
				$parameters['message'] = "Failed to remove $contact_id from $group_name on dav";
				$parameters['abort'] = 1;
				return $parameters;
			}
		}

		return $parameters;
	}
	public function fromlocal_groupcreate($parameters){
		if(strpos($parameters['name'], ',') !== false){
			$parameters['message'] = "Group name cannot have a comma(,)";
			$parameters['abort'] = 1;
		}
		return $parameters;
	}
	public function fromlocal_grouprename($parameters){
		if(strpos($parameters['name'], ',') !== false){
			$parameters['message'] = "Group name cannot have a comma(,)";
			$parameters['abort'] = 1;
			return $parameters;
		}

		$group_id = $parameters['group_id'];
		$newname = $parameters['name'];

		$abook_id = $parameters['source'];
		$abook = $this->rc->get_address_book($abook_id);
		
		$abook->set_group($group_id);
		$list = $abook->list_records();
		foreach($list->records as $record){
			$save_data = $this->fromlocal_getdata($abook_id, $record['ID']);

			$groups = $abook->get_record_groups($record['ID']);
			$groups[$group_id] = $newname;
			$save_data['groups'] = implode(",", $groups);

			$this->fromlocal_upload($abook_id, $save_data, $contact_id);
		}
		$abook->set_group(null);
		
		return $parameters;
	}
	public function fromlocal_groupdelete($parameters){
		$abook_id = $parameters['source'];
		$group_id = $parameters['group_id'];

		$abook = $this->rc->get_address_book($abook_id);
		$abook->set_group($group_id);
		$list = $abook->list_records();
		foreach($list->records as $record){
			$save_data = $this->fromlocal_getdata($abook_id, $record['ID']);

			$groups = $abook->get_record_groups($record['ID']);
			if(sizeof($groups) < 2){
				unset($save_data['groups']);
			}else{
				unset($groups[$group_id]);
				$save_data['groups'] = trim(implode(",", $groups), ",");
			}

			$this->fromlocal_upload($abook_id, $save_data, $contact_id);
		}
		$abook->set_group(null);

		return $parameters;
	}
	
	private function dav2rcube($abook_id, $dav_url){
		// Step 1: Get DAV data
		if(!$abook_url = $this->getUrlForBook($abook_id)){return;}
		$headers = array('Content-type'=>'text/vcard; charset="utf-8"');
		
		$response = $this->makeRequest($abook_url.$dav_url, 'GET', $headers, '');
		if($response->code != "200"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Getting DAV data for $dav_url failed, wrong response code: ".$response->code), true, true);
		}

		try{
			$dav_vcard = VObject\Reader::read($response->raw_body, VObject\Reader::OPTION_FORGIVING);
		}catch(Exception $e){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Getting DAV data for $dav_url failed, couldn't parse vcard"), true, true);
		}

		// Step 2: Fill roundcube style array
		$save_data = array();
		if(!isset($dav_vcard->UID)){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Getting DAV data for $dav_url failed, no UID"), true, false);
			return $save_data;
		}
		$save_data['dav_id'] = (string)$dav_vcard->UID;
		$save_data['etag'] = $response->headers['etag'];
		
		if(isset($dav_vcard->FN)){$save_data['name'] = (string)$dav_vcard->FN;}
		if(isset($dav_vcard->N)){
			foreach($dav_vcard->N as $value){
				$nparts = $value->getParts();
				if($nparts[0]){$save_data['surname'] = $nparts[0];}
				if($nparts[1]){$save_data['firstname'] = $nparts[1];}
				if($nparts[2]){$save_data['middlename'] = $nparts[2];}
				if($nparts[3]){$save_data['prefix'] = $nparts[3];}
				if($nparts[4]){$save_data['suffix'] = $nparts[4];}
			}
		}
		if(isset($dav_vcard->NICKNAME)){$save_data['nickname'] = (string)$dav_vcard->NICKNAME;}
		if(isset($dav_vcard->ORG)){
			foreach($dav_vcard->ORG as $value){
				$orgparts = $value->getParts();
				if($orgparts[0]){$save_data['organization'] = $orgparts[0];}
				if($orgparts[1]){
					unset($orgparts[0]);
					$save_data['department'] = implode("/", $orgparts);
				}
			}
		}
		/* @FIXME ROLE does not have a separate field */
		if(isset($dav_vcard->TITLE) && isset($dav_vcard->ROLE)){
			$save_data['jobtitle'] = (string)$dav_vcard->TITLE. " / ".(string)$dav_vcard->ROLE;
		}elseif(isset($dav_vcard->TITLE)){
			$save_data['jobtitle'] = (string)$dav_vcard->TITLE;
		}elseif(isset($dav_vcard->ROLE)){
			$save_data['jobtitle'] = (string)$dav_vcard->ROLE;
		}

		if(isset($dav_vcard->CATEGORIES)){$save_data['groups'] = $dav_vcard->CATEGORIES->getParts();}

		if(isset($dav_vcard->EMAIL)){
			foreach($dav_vcard->EMAIL as $value){
				$type = strtolower((string)$value['TYPE']);
				$type = $type == "home" || $type == "work" ? $type : "other";
				$value_clean = preg_replace(array('/.*</', '/>.*/'), array('', ''), (string)$value);
				$save_data["email:$type"][] = $value_clean;
			}
		}
		if(isset($dav_vcard->TEL)){
			foreach($dav_vcard->TEL as $value){
				switch((string)$value['TYPE']){
					case "CELL":
						$type = "mobile";
						break;
					case "HOME":
					case "HOME,VOICE":
						$type = "home";
						break;
					case "HOME,FAX":
						$type = "home fax";
						break;
					case "WORK":
					case "WORK,VOICE":
						$type = "work";
						break;
					case "WORK,FAX":
						$type = "work fax";
						break;
					case "WORK,MAIN":
						$type = "main";
						break;
					case "ASSISTANT,VOICE":
						$type = "assistant";
						break;
					case "PAGER":
						$type = "pager";
						break;
					case "CAR":
						$type = "car";
						break;
					case "VIDEO":
						$type = "video";
						break;
					default:
						$type = "other";
				}
				$save_data["phone:$type"] = (string)$value;
			}
		}
		if(isset($dav_vcard->ADR)){
			foreach($dav_vcard->ADR as $value){
				$parts = $value->getParts();
				$type = strtolower((string)$value['TYPE']);
				$type = $type == "home" || $type == "work" ? $type : "other";
				$adr = array(
					'pobox'    => $parts[0],
					'extended' => $parts[1],
					'street'   => $parts[2],
					'locality' => $parts[3],
					'region'   => $parts[4],
					'zipcode'  => $parts[5],
					'country'  => $parts[6]
				);
				$save_data["address:$type"][] = $adr;
			}
		}
		if(isset($dav_vcard->URL)){
			foreach($dav_vcard->URL as $value){
				$type = (string)$value['TYPE'];
				$save_data["website:$type"] = (string)$value;
			}
		}
		if(isset($dav_vcard->BDAY)){$save_data['birthday'] = (string)$dav_vcard->BDAY;}
		if(isset($dav_vcard->NOTE)){$save_data['notes'] = (string)$dav_vcard->NOTE;}
		
		if(isset($dav_vcard->PHOTO)){$save_data['photo'] = (string)$dav_vcard->PHOTO;}

		if(isset($dav_vcard->KIND)){
			$save_data['kind'] = (string)$dav_vcard->KIND;
		}else{
			$save_data['kind'] = "individual";
		}
		
		if(isset($dav_vcard->GENDER)){
			if((string)$dav_vcard->GENDER == "M"){
				$save_data['gender'] = "male";
			}elseif((string)$dav_vcard->GENDER == "F"){
				$save_data['gender'] = "female";
			}
		}
		/* @FIXME no entry in DAV for maidenname
		if(isset($dav_vcard->)){$save_data['maidenname'] = (string)$dav_vcard->;}
		*/
		
		if(isset($dav_vcard->{'X-ANNIVERSARY'})){$save_data['anniversary'] = (string)$dav_vcard->{'X-ANNIVERSARY'};}
		if(isset($dav_vcard->{'X-ASSISTANT'})){$save_data['assistant'] = (string)$dav_vcard->{'X-ASSISTANT'};}
		if(isset($dav_vcard->{'X-MANAGER'})){$save_data['manager'] = (string)$dav_vcard->{'X-MANAGER'};}
		if(isset($dav_vcard->{'X-SPOUSE'})){$save_data['spouse'] = (string)$dav_vcard->{'X-SPOUSE'};}

		if(isset($dav_vcard->IMPP)){$save_data["im:other"][] = (string)$dav_vcard->IMPP;}
		if(isset($dav_vcard->{'X-AIM'})){
			foreach($dav_vcard->{'X-AIM'} as $value){
				$save_data["im:aim"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-JABBER'})){
			foreach($dav_vcard->{'X-JABBER'} as $value){
				$save_data["im:jabber"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-MSN'})){
			foreach($dav_vcard->{'X-MSN'} as $value){
				$save_data["im:msn"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-YAHOO'})){
			foreach($dav_vcard->{'X-YAHOO'} as $value){
				$save_data["im:yahoo"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-ICQ'})){
			foreach($dav_vcard->{'X-ICQ'} as $value){
				$save_data["im:icq"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-GOOGLE-TALK'})){
			foreach($dav_vcard->{'X-GOOGLE-TALK'} as $value){
				$save_data["im:other"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-GTALK'})){
			foreach($dav_vcard->{'X-GTALK'} as $value){
				$save_data["im:other"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-TWITTER'})){
			foreach($dav_vcard->{'X-TWITTER'} as $value){
				$save_data["im:other"][] = (string)$value;
			}
		}
		if(isset($dav_vcard->{'X-GADUGADU'})){
			foreach($dav_vcard->{'X-GADUGADU'} as $value){
				$save_data["im:other"][] = (string)$value;
			}
		}

		/* @FIXME These fields don't have a contact field:
			X-PHONETIC-FIRST-NAME
			X-PHONETIC-LAST-NAME
		*/

		return $save_data;
	}
	private function rcube2dav($record){
		$vcard = new VObject\Component\VCard;
		$vcard->KIND = "individual";
		$vcard->PRODID = "roundcube_carddavsso";
		$vcard->REV = gmdate("Ymd\THis\Z");

		foreach($record as $key => $value){
			if($value == ""){continue;}
			if(strpos($key, ':') !== false){
				$parts = explode(":",$key);
				$key = $parts[0];
				$type = $parts[1];
			}
			switch($key){
				case "name":
					$vcard->FN = $value;
					break;
				case "surname":
				case "firstname":
				case "middlename":
				case "prefix":
				case "suffix":
					$vcard->N = [$record['surname'], $record['firstname'], $record['middlename'], $record['prefix'], $record['suffix']];
					break;
				case "nickname":
					$vcard->NICKNAME = $value;
					break;
				case "organization":
				case "department":
					$vcard->ORG = [$record['organization'], $record['department']];
					break;
				case "jobtitle":
					$vcard->TITLE = $value;
					break;
				case "email":
					foreach($value as $email_data){
						if($email_data == ""){continue;}
						$vcard->add('EMAIL', $email_data, ['type' => strtoupper($type)]);
					}
					break;
				case "phone":
					foreach($value as $phone_data){
						if($phone_data == ""){continue;}
						switch($type){
							case "mobile":
								$vcard->add('TEL', $phone_data, ['type' => 'CELL']);
								break;
							case "home":
								$vcard->add('TEL', $phone_data, ['type' => 'HOME,VOICE']);
								break;
							case "work":
								$vcard->add('TEL', $phone_data, ['type' => 'WORK,VOICE']);
								break;
							case "homefax":
								$vcard->add('TEL', $phone_data, ['type' => 'HOME,FAX']);
								break;
							case "workfax":
								$vcard->add('TEL', $phone_data, ['type' => 'WORK,FAX']);
								break;
							case "pager":
								$vcard->add('TEL', $phone_data, ['type' => 'PAGER']);
								break;
							case "car":
								$vcard->add('TEL', $phone_data, ['type' => 'CAR']);
								break;
							case "main":
								$vcard->add('TEL', $phone_data, ['type' => 'WORK,MAIN']);
								break;
							case "assistant":
								$vcard->add('TEL', $phone_data, ['type' => 'ASSISTANT,VOICE']);
								break;
							default:
								$vcard->add('TEL', $phone_data);
								break;
						}
					}
					break;
				case "address":
					foreach($value as $address_data){
						if($address_data['pobox'] == ""
								&& $address_data['extended'] == ""
								&& $address_data['street'] == ""
								&& $address_data['locality'] == ""
								&& $address_data['region'] == ""
								&& $address_data['zipcode'] == ""
								&& $address_data['country'] == ""){
							continue;
						}
						$vcard->add('ADR', [$address_data['pobox']
													, $address_data['extended']
													, $address_data['street']
													, $address_data['locality']
													, $address_data['region']
													, $address_data['zipcode']
													, $address_data['country']], ['type' => strtoupper($type)]);
					}
					break;
				case "website":
					$vcard->add('URL', $value, ['type' => strtoupper($type)]);
					break;
				case "birthday":
					$vcard->BDAY = $value;
					break;
				case "notes":
					$vcard->NOTE = $value;
					break;
				case "groups":
					$vcard->CATEGORIES = $value;
					break;
				case "photo":
					// @FIXME: Support other encodings
					$vcard->add('PHOTO', $value, ['ENCODING' => 'BASE64', 'type' => 'JPEG']);
					break;
				case "gender":
					if($value == "male"){
						$vcard->GENDER = "M";
					}elseif($value == "female"){
						$vcard->GENDER = "F";
					}else{
						$vcard->GENDER = "U";
					}
					break;
				/* @FIXME Not part of any vcard definition
				case "maidenname":
					$vcard->add('???', $value);
					break;
				*/
				case "anniversary":
					$vcard->add('X-ANNIVERSARY', $value);
					break;
				case "assistant":
					$vcard->add('X-ASSISTANT', $value);
					break;
				case "manager":
					$vcard->add('X-MANAGER', $value);
					break;
				case "spouse":
					$vcard->add('X-SPOUSE', $value);
					break;
				case "im":
					foreach($value as $im_data){
						if($im_data == ""){continue;}
						switch($type){
							case "aim":
								$vcard->add('X-AIM', $im_data);
								break;
							case "icq":
								$vcard->add('X-ICQ', $im_data);
								break;
							case "jabber":
								$vcard->add('X-JABBER', $im_data);
								break;
							case "msn":
								$vcard->add('X-MSN', $im_data);
								break;
							case "yahoo":
								$vcard->add('X-YAHOO', $im_data);
								break;
							case "skype":
								$vcard->add('X-SKYPE', $im_data);
								break;
							default:
								$vcard->add('IMPP', $im_data);
						}
					}
					break;
				case "ID":
				case "contact_id":
				case "contactgroup_id":
				case "changed":
				case "created":
				case "del":
				case "vcard":
				case "words":
				case "user_id":
				case "dav_id":
				case "dav_url":
				case "etag":
					// ignore, these are roundcube/dav fields
					break;
				default:
					rcube::raise_error(array('code' => 404, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error converting local to DAV, unkown key:  $key"), true, false);
			}
			/* @FIXME These fields don't have a contact field:
				X-PHONETIC-FIRST-NAME
				X-PHONETIC-LAST-NAME
			*/
		}

		return $vcard;
	}
	private function getUrlForBook($abook_id){
		if(strlen($this->abook_url) < 5){
			$davserver = str_replace("%USER%", $this->username, $this->rc->config->get('carddavsso_davserver'));
			$dav_url = $this->rc->config->get("carddavsso_contacts_default");
			$this->abook_url = $davserver.$dav_url;
		}
		return $this->abook_url;
	}
	
	private function makeRequest($request_url, $request_method, $request_headers, $request_body){
		$httpful = \Httpful\Request::init();
		$httpful->basicAuth($this->username, $this->password);
		$httpful->addHeader("User-Agent", "roundcube_carddavsso");
		$httpful->uri($request_url);
		$httpful->method($request_method);
		if(is_array($request_headers)){
			foreach($request_headers as $name => $value){
				$httpful->addHeader($name, $value);
			}
		}
		$httpful->body($request_body);
		return $httpful->send();
	}
	
}