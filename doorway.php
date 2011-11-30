<?php

/**
 * Doorway - A Smaller, Simpler ACL library
 *
 * Simplifies ACL by integrating a couple key componets into the 
 * permission layer(actions and resource), and applying
 * a DENY ALL as the default.  
 *
 * @author Geoff Doty <n2geoff@gmail.com>
 * @copyright Geoff Doty
 * @license TBA
 *
 * ENTITIES: GROUP, MEMBER
 * ACTIONS:  CREATE, READ, UPDATE, DELETE
 */
class Doorway {
	
	private $db = NULL;  //PHP Data Object instance

	private $table = 'acl';

	public function __construct($pdo, $table = 'acl')
	{
		//set acl table name
		$this->table = $table;

		//connect to database
		if(get_class($pdo) != 'PDO') {return FALSE;} //no, throw error
		$this->db = $pdo;
		
		return $this;	
	}

	/*******************************************************************
	 * Member Methods
	 *******************************************************************/
	 public function create_member($name, $description = NULL) 
	 {
	 	$sql =
	 	"
	 		INSERT INTO {$this->table}.members 
	 		(
		 		name, description, created_on
	 		) 
	 		VALUES 
	 		(
		 		:name, :description, NOW()
	 		)
	 	";

		$prep = $this->db->prepare($sql);
		$prep->bindValue(':name', $name, PDO::PARAM_STR);
		$prep->bindValue(':description', $description, PDO::PARAM_STR);

		if($prep->execute())
		{
			return $this->db->lastInsertID();
		}
		return FALSE;
	 }

	 //if a member is removed all member resources need to be removed
	 public function remove_member($member_id) 
	 {
	 	$member_id = filter_var($member_id, FILTER_SANITIZE_NUMBER_INT);

		if($member_id > 0)
		{
			try
			{
				$this->db->beginTransaction();

			 	//remove all memberships
			 	$this->db->exec("DELETE FROM {$this->table}.memberships WHERE member_id = {$member_id}");

			 	//remove from permissions
			 	$this->db->exec("DELETE FROM {$this->table}.permissions WHERE member_id = {$member_id}");

			 	//remove member
			 	$this->db->exec("DELETE FROM {$this->table}.members WHERE id = {$member_id}");
				
				$this->db->commit();
			}
			catch (PDOException $e)
			{
				$this->db->rollBack();
				echo $e->getMessage();
				return FALSE;
			}
	 	}
	 	return FALSE;
	 }

	 public function is_member($member_id) 
	 {
	 	return $this->db->query("SELECT count(*) FROM {$this->table}.members WHERE id = {$member_id}")->fetchColumn();
	 }

 	public function is_authorized($member_id, $resource, $action = 'read')
	{
		$sql = 
		"
			SELECT *
			FROM acl.members AS m
			LEFT JOIN acl.memberships AS ms ON m.id = ms.member_id
			LEFT JOIN acl.permissions AS p ON p.group_id = ms.group_id OR p.member_id = ms.member_id
			LEFT JOIN acl.groups AS g ON g.id = ms.group_id 
			WHERE
			(	m.id = :member_id
				AND p.resource = :resource AND p.action = :action
			)
		";

		$prep = $this->db->prepare($sql);
		$prep->bindValue(':member_id', $member_id, PDO::PARAM_INT);
		$prep->bindValue(':resource', strtolower($resource), PDO::PARAM_STR);
		$prep->bindValue(':action', strtolower($action), PDO::PARAM_STR);

		if($prep->execute())
		{
			return $prep->fetchColumn(); //returns count(*)
		}
		return FALSE;
	}

	/******************************************************************
	 * Group Methods
	 ******************************************************************/
	 public function create_group($name, $description = NULL) 
	 {
	 	$sql =
	 	"
	 		INSERT INTO {$this->table}.groups
	 		(
	 			name, description, created_on
	 		)
	 		VALUES
	 		(
	 			:name, :description, NOW()
	 		)
	 	";

		$prep = $this->db->prepare($sql);
		$prep->bindValue(':name', $name, PDO::PARAM_STR);
		$prep->bindValue(':description', $description, PDO::PARAM_STR);

		if($prep->execute())
		{
			return $this->db->lastInsertID();
		}
		return FALSE;
	 }

	//if a group is removed all resources need to be removed
	public function remove_group($group_id) 
	{
	 	//sanitize params
	 	$group_id = filter_var($group_id, FILTER_SANITIZE_NUMBER_INT);

		if($group_id > 0)
		{
		 	//remove all memberships
		 	$this->db->query("DELETE FROM {$this->table}.memberships WHERE group_id = {$group_id}");

		 	//remove from permissions
		 	$this->db->query("DELETE FROM {$this->table}.permissions WHERE group_id = {$group_id}");

		 	//remove member
		 	$this->db->query("DELETE FROM {$this->table}.groups WHERE id = {$group_id}");

		 	return TRUE;
		}
		 return FALSE;
	}

	public function is_group($group_id) 
	{
		return $this->db->query("SELECT count(*) FROM {$this->table}.groups WHERE id = {$group_id})->fetchColumn();
	}

	/******************************************************************
	 * Membership Methods
	 ******************************************************************/
	 public function add_membership($member_id, $group_id) 
	 {
	 	if($this->is_member($member_id))
	 	{
	 		if($this->is_group($group_id))
	 		{
	 			//do sql
	 			$sql = "INSERT INTO {$this->table}.memberships (member_id, group_id) VALUES (:member_id, :group_id)";
	 			
	 			$prep = $this->db->prepare($sql);
	 			$prep->bindValue(':member_id', $member_id, PDO::PARAM_INT);
	 			$prep->bindValue(':group_id', $group_id, PDO::PARAM_INT);
	 			
	 			if($prep->execute())
	 			{
	 				return TRUE;
	 			}
	 		}
	 	}
	 	return FALSE;
	 }

	/******************************************************************
	 * Permission Methods
	 ******************************************************************/
	public function create_group_permission($group_id, $resource, $action = 'read') 
	{
	  	if($group_id > 0 && is_int($group_id))
	  	{
		  	$sql =
		  	"
		  		INSERT INTO {$this->table}.permissions
		  		(
		  			member_id, group_id, resource, action, created_on
			  	)
			  	VALUES
			  	(
			  		NULL, :group_id, :resource, :action, NOW()
			  	)
		  	";	

		  	$prep = $this->db->prepare($sql);
		  	$prep->bindValue(':group_id', strtolower($group_id), PDO::PARAM_STR);
		  	$prep->bindValue(':resource', strtolower($resource), PDO::PARAM_STR);
		  	$prep->bindValue(':action', strtolower($action), PDO::PARAM_STR);

		  	if($prep->execute())
		  	{
		  		return TRUE;
		  	}
	    }
	    return FALSE;
	}
}
