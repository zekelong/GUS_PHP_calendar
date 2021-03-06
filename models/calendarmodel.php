<?php
/***** written by Zeke Long *****/
class Calendarmodel extends Model 
{
	function Calendarmodel()		//constructor
	{
		parent::Model();
	
		$this->load->helper('url');  	//need for base_url() function
		$this->load->model('User');
		$this->load->model('Group');
		$this->load->model('Page');
		$this->db = $this->load->database('admin', TRUE);	
	
		//preference variable for when the calendar library is loaded
		$this->pref = array(
			'day_type' => 'long',
			'show_next_prev' => true,
			'next_prev_url' => site_url() . '/calendar/index'	
		);
		//template from CI's calendar class
		$this->pref['template'] = '
		   {table_open}<table border="0" cellpadding="4" cellspacing="0" class="calendar">
		   {/table_open}
		   {heading_row_start}<tr class="month">{/heading_row_start}
		   {heading_previous_cell}<th><a href="{previous_url}">&lt;&lt;prev</a></th>
		   {/heading_previous_cell}
		   {heading_title_cell}<th colspan="{colspan}"><h2>{heading}</h2></th>{/heading_title_cell}
		   {heading_next_cell}<th><a href="{next_url}">next&gt;&gt;</a></th>{/heading_next_cell}
		   {heading_row_end}</tr>{/heading_row_end}
		   {week_row_start}<tr class="weeks">{/week_row_start}
		   {week_day_cell}<td><center><font color="white">{week_day}</font></center></td>{/week_day_cell}
		   {week_row_end}</tr>{/week_row_end}
		   {cal_row_start}<tr class="days">{/cal_row_start}
		   {cal_cell_start}<td class="day">{/cal_cell_start}
				{cal_cell_content}
					<div class="day_num">{day}</div>
					<div class="content">{content}</div>
				{/cal_cell_content}
				{cal_cell_content_today}
					<div class="day_num highlight">{day}</div>
					<div class="content">{content}</div>
				{/cal_cell_content_today}
				{cal_cell_no_content}<div class="day_num">{day}</div>{/cal_cell_no_content}
				{cal_cell_no_content_today}<div class="day_num highlight">{day}</div>
				{/cal_cell_no_content_today}
				{cal_cell_blank}&nbsp;{/cal_cell_blank}
		   {cal_cell_end}</td>{/cal_cell_end}
		   {cal_row_end}</tr>{/cal_row_end}
		   {table_close}</table>{/table_close}
		';
	}
	
	function view_day($date){               //function to get data for the day
		$groupName = $this->get_enrolled_groups();
		$userName = $this->session->userdata('un');
		$day_data = array();
		
		foreach($groupName as $group){
			//a fix for groups with names longer than 32 characters
			if(strlen($group) > 32)
				$group = substr($group, 0, 32);
			//get all the group events for the day along with their corresponding eventID
			$groupEvents = $this->db->query("SELECT data, eventID, user FROM calendar
				WHERE date='$date' AND user='$group'")->result();	
			if($groupEvents){		
				//push each event onto $day_data array
				foreach($groupEvents as $row){
					//make it blue
					array_push($day_data, "<small><font color='blue' size='1'>&#9830</small></font> "
						. "<font color='blue'>" . $row->data . "</font>\t(Group event for " . $group . ")");
					//if user is an admin of the group
					if($this->is_an_owner($group))
						array_push($day_data, $row->eventID);
					else
						array_push($day_data , "notAdm");
				}		
			}
		}		
		//get all the user's events for the day along with their corresponding eventID
		$primaryEvents = $this->db->query("SELECT data, eventID, user FROM calendar
			WHERE date='$date' AND user='$userName'")->result();	
		if($primaryEvents){		
			//save each event into $day_data array
			foreach($primaryEvents as $row){
				array_push($day_data, "<big>&#8226</big>" . $row->data);
				array_push($day_data, $row->eventID);
			}
		}	
		return $day_data;
	}
	
	function view_day_invites($date){   //function to get events user is invited to
		$userName = $this->session->userdata('un');
		$invite_data = array();
		
		//get all the events that the user is invited to (by querying calendar_rsvp table)
		$invitedEvents = $this->db->query("SELECT eventID FROM calendar_rsvp WHERE name='$userName'")->result();
		if($invitedEvents){
			foreach($invitedEvents as $row){
				$eventID = $row->eventID;
				//get the date, event data and owner of the event
				$eventOwner = null;
				$eventDate = null;
				$eventData = null;
				$result = $this->db->query("SELECT user, date, data FROM calendar WHERE eventID='$eventID'")->result();
				//if the event still exists
				if($result){
					foreach($result as $xyz){
						$eventOwner = $xyz->user;
						$eventDate = $xyz->date;
						$eventData = $xyz->data;
					}
					if($eventDate == $date){
						//add the event to the array of events
						array_push($invite_data, "<big>&#8226</big>" . $eventData
							. "\t(invited by " . $eventOwner . ")");
						array_push($invite_data, ($eventID));
					}
				}
				//if the event does not exist
				else{
					//clean up the calendar_rsvp table
					$this->db->query("DELETE FROM calendar_rsvp WHERE eventID='$eventID'");
				}
			}
		}		
		return $invite_data;
	}
	
	function add_event($date, $event, $eventID = null){
		//prevent scripts and SQL-injection
		$event = mysql_real_escape_string(htmlspecialchars($event));
		$userName = $this->session->userdata('un');
		
		if($eventID){
			//if it's an event for testing
			if($eventID == 1){
				$this->remove_event(1);	         //clean up previous test events if they are there
				return $this->db->query("INSERT INTO calendar (user, date, data, eventID) 
					VALUES ('$userName', '$date', '$event', 1)");
			}
			//if it's a user's event being updated
			elseif($this->db->query("SELECT data FROM calendar WHERE eventID='$eventID'")->result()){
				//update the event
				return $this->db->query("UPDATE calendar SET data='$event', date='$date' 
					WHERE eventID='$eventID'");
			}
		}
		else{
			$userName = $this->session->userdata('un');
			//add the event for the user in the calendar table 
			return $this->db->query("INSERT INTO calendar (user, date, data) 
				VALUES ('$userName', '$date', '$event')");
		}
	}
	
	function add_group_event($date, $event, $groupName, $eventID = null){
		//prevent scripts and SQL-injection
		$event = mysql_real_escape_string(htmlspecialchars($event));
		if($this->is_an_owner($groupName)){
			if($eventID){
				//update the group event if it exists already, otherwise add it
				if($this->db->query("SELECT data FROM calendar WHERE eventID='$eventID'")->result()){
					return $this->db->query("UPDATE calendar SET data='$event', date='$date' 
						WHERE eventID='$eventID'");									
				}
			}
			else{
				return $this->db->query("INSERT INTO calendar (user, date, data) 
					VALUES ('$groupName', '$date', '$event')");
			}
		}
		elseif($eventID == 1){       //if it's for testing
			return $this->db->query("INSERT INTO calendar (user, date, data, eventID) 
				VALUES ('$groupName', '$date', '$event', 1)");
		}
	}
	
	function edit_event($event, $eventID){
		//prevent scripts and SQL-injection
		$event = mysql_real_escape_string(htmlspecialchars($event));
		return $this->db->query("UPDATE calendar SET data='$event' WHERE eventID='$eventID'");
	}
	
	
	function remove_event($eventID){
		return $this->db->query("DELETE FROM calendar WHERE eventID='$eventID'");
	}
	
	
	function generate_invite_array($groupName = null){
		$options = array();
		if($groupName){
			foreach($groupName as $group)
				$options[$group] = $group;
		}
		else{
			//get array of groups that the user is part of
			$enrolledGroupsArr = $this->get_enrolled_groups();
			//get array of members in each of these groups
			if($enrolledGroupsArr){
				foreach($enrolledGroupsArr as $groupName){
					$members = $this->get_group_members($groupName);
					foreach($members as $member)
						$options[$member] = $member;
				}
			}
		}
		return $options;
	}
	
	
	function invite_to_event($eventID, $groupID, $userArray, $eventDate){
		if($userArray){
			//get the event data
			$eventDataArr = $this->db->query("SELECT data FROM calendar WHERE eventID='$eventID'")->result();
			foreach($eventDataArr as $row){
				$eventData = "<br>You are invited to: " . $row->data . " (on " 
					. $eventDate . ")<br>To accept the invite, go to the day in your calendar.";
			}
			$from_id = $this->Page->get_uid();
			$created = date("Y-m-d h:i:s");
			
			foreach($userArray as $name){	
				//update calendar_rsvp table if the user is not already invited
				if(! $this->db->query("SELECT name FROM calendar_rsvp 
					WHERE eventID='$eventID' AND name='$name'")->result()){
					$this->db->query("INSERT INTO calendar_rsvp (eventID, groupID, name, unanswered)
						VALUES ('$eventID', '$groupID', '$name', 1)");
				}		
				
				//set up fields to send a notification message to the invited user	
				$to_id = $this->User->get_id($name);
				//from_id and to_id have to be switched around to make it work correctly
				$invite = array('from_id' => $to_id,
					'to_id' => $from_id,
					'subject' => "Gus Event Invite",
					'message' => $eventData,
					'created' => $created,
					'location' => "inbox"
				);										
								
				//add the invite notification to Abhay's messages table 
				$check = $this->db->query("SELECT id FROM messages 
					WHERE message='$eventData' AND to_id='$to_id' 
					AND from_id='$from_id'")->result();
				if(! $check)
					$this->db->insert("messages" , $invite);	
			}		
		}
		return 1;
	}
	
	
	function join_event($eventID, $userName){
		return $this->db->query("UPDATE calendar_rsvp SET yes=1, no=0, maybe=0, unanswered=0 
			WHERE eventID='$eventID' 
			AND name='$userName'");
	}
	
	
	function drop_event($eventID, $userName){
		return $this->db->query("UPDATE calendar_rsvp SET yes=0, no=1, maybe=0, unanswered=0 
			WHERE eventID='$eventID' 
			AND name='$userName'");
	}
	
	
	function check_if_group_event($item){
		$userName = $this->session->userdata('un');
		$result = $this->db->query("SELECT user FROM calendar WHERE eventID='$item'")->result();
		if($result){
			foreach($result as $row){
				if($userName != $row->user)
					return 1;
				else
					return 0;
			}
		}
	}
	
	
	function get_owned_groups(){               //returns an array of groups that the user is an admin of
		$groupArray = array();
		//get the userID
		$userID = $this->User->get_id($this->session->userdata('un'));
		//get the groupID
		$ownedGroups = $this->db->query("SELECT gid, perm FROM usergroup WHERE uid='$userID'")->result();
		foreach($ownedGroups as $owned){
			if($owned->perm == 7){			
				$groupID = $owned->gid;
				//get the groupName
				$gName = $this->db->query("SELECT name FROM ggroup WHERE id='$groupID'")->result();
				foreach($gName as $xyz)
					array_push($groupArray, $xyz->name);
			}
		}
		return $groupArray;
	}
	
	
	function is_an_owner($groupName){     //returns TRUE if the user is an admin of the specified group
		$userID = $this->User->get_id($this->session->userdata('un'));
		$groupID = $this->Group->get_id($groupName);
		$permissions = $this->db->query("SELECT perm FROM usergroup 
			WHERE gid='$groupID' AND uid='$userID'")->result();
		foreach($permissions as $permission){
			if($permission->perm == 7)
				return 1;
			else 
				return 0;
		}
	}
	
	
	function get_enrolled_groups(){               //returns an array of groups the user is in
		$groupArray = array();
		//get the userID
		$userID = $this->User->get_id($this->session->userdata('un'));
		//get the groupID
		$gid = $this->db->query("SELECT gid FROM usergroup WHERE uid='$userID'")->result();
		foreach($gid as $row){
			$groupID = $row->gid;
			//get the groupName
			$gName = $this->db->query("SELECT name FROM ggroup WHERE id='$groupID'")->result();
			foreach($gName as $xyz)
				array_push($groupArray, $xyz->name);
		}
		return $groupArray;
	}
	
	function get_group_members($groupName){       //returns an array of members in the specified group
		$groupMembers = array();
		$groupID = $this->Group->get_id($groupName);
		$userIdArr = $this->db->query("SELECT uid FROM usergroup WHERE gid='$groupID'")->result();
		foreach($userIdArr as $userID){
			$id = $userID->uid;
			$userNameArr = $this->db->query("SELECT un FROM user WHERE id='$id'")->result();
			foreach($userNameArr as $userName)
				array_push($groupMembers, $userName->un);
		}
		return $groupMembers;
	}
	
	
	function myGenerate($year, $month){	
		//load calendar library with preferences that were specified in the constructor
		$this->load->library('calendar', $this->pref);
		
		//get data for the month
		$cal_data = $this->get_cal_data($year, $month);
		//return generated calendar to controller
		//(codeigniter's generate() function, different than myGenerate())
		if($result = $this->calendar->generate($year, $month, $cal_data))
			return $result;
		else
			return 0;
	}
	
	
	function get_cal_data($year, $month){
		$userName = $this->session->userdata('un');		
		$groupName = $this->get_enrolled_groups();
		$cal_data = array(array());             //2D array since each day can have multiple events
	
		foreach($groupName as $group){
			//a fix for groups with names longer than 32 characters
			if(strlen($group) > 32)
				$group = substr($group, 0, 32);
			//get the user's group events for the month 
			if($result = $this->db->query("SELECT date, data, user FROM calendar 
				WHERE date LIKE '$year-$month%' 
				AND user='$group'")->result()){
				//for each event that was a match
				foreach($result as $row)   {
					//allows for 20 group events in a day
					for($i=0; $i<20; $i++){
						//substr($row->date, 8, 2) is the day part of the date
						if(!isset($cal_data[substr($row->date, 8, 2)][$i])){
							//adjust the length and font to show that it's a group event
							$tmpData = "<font color='blue' size='1'>&#9830</font> "
								. "<font color='blue'>" . substr($row->data, 0, 8) . "</font>";
							if(strlen($row->data) > 8){
								$cal_data[substr($row->date, 8, 2)][$i] = 
									$tmpData . "<font color='blue'> ... </font>";
								break;
							}
							else{
								$cal_data[substr($row->date, 8, 2)][$i] = $tmpData;
								break;
							}
						}	
					}
				}
			}
		}	
		//get the user's personal events for the month 
		if($result = $this->db->query("SELECT date, data, user FROM calendar 
			WHERE date LIKE '$year-$month%' 
			AND user='$userName'")->result()){
			foreach($result as $row)   {       //for each event that was a match
				//allows for 100 events in a day, maybe excessive
				for($i=0; $i<100; $i++){
					//substr($row->date, 8, 2) is the day part of the date
					if(!isset($cal_data[substr($row->date, 8, 2)][$i])){
						//adjust the length
						$tmpData = "<big>&#8226</big>" . substr($row->data, 0, 8);	
						if(strlen($row->data) > 8){
							$cal_data[substr($row->date, 8, 2)][$i] = $tmpData . " ...";
							break;
						}
						else{
							$cal_data[substr($row->date, 8, 2)][$i] = $tmpData;
							break;
						}							
					}	
				}
			}
		}		
		return $cal_data; 
	}
}
?>
