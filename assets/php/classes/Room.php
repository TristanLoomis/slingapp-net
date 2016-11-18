<?php

/**
 * Room Class
 * Created by PhpStorm.
 * User: ian
 * Date: 10/16/16
 * Time: 5:53 PM
 */
set_include_path(realpath($_SERVER["DOCUMENT_ROOT"]) . "/assets/php/");
require_once "interfaces/DatabaseObject.php";
require_once "classes/RoomCode.php";
require_once "classes/Account.php";

//Needs setter for number of room code uses
/**
 * This Class handles all Rooms in the application, and manages their creation
 * and deletion as well as adding and removing participants through its function
 * calls. This class has the ability to make rooms for temporary accounts as well
 * as permanent accounts. The participants generated in each room will exist for the
 * duration of the room, and can be rejoined by an account via the cookie unique
 * identity that each account will have based on its computer  and browser.
 */
class Room extends DatabaseObject
{
    private $_room_codes = [];
    private $_accounts = [];
    private $_roomID;
    private $_roomName;
    private $_usesLeft;
    private $_expirationDate;
    //pass account object to constructor or just the needed parameters to factor out the select statement
    /**
     * Function Constructor
     * Room constructor.
     * @param $roomID
     * @param $token
     * @param $screenName
     * @throws Exception
     * This constructor will allow a room to be generated based on the creating participants
     * token, and given screen name, the roomID will act as a unique identifier for the room.
     * No room can exist without a participant.
     */
    public function __construct($roomID, $token, $screenName, $uses, $expirationDate)
    {
        $this->_roomID = $roomID;
        $this->_usesLeft = $uses;

        $this->_expirationDate = $expirationDate;
        if($participantID = $this->addParticipant($token, $screenName)) {
            $roomCodeObject = $this->addRoomCode($participantID, $uses, $expirationDate);
            $roomCode = $roomCodeObject->getCode();

            $sql = "SELECT DISTINCT * FROM Rooms
                    LEFT JOIN Participants
                    ON Rooms.RoomID = Participants.RoomID
                    LEFT JOIN RoomCodes rc
                    ON Rooms.RoomID = rc.RoomID
                    WHERE Rooms.RoomID = (SELECT RoomID
                                          FROM RoomCodes
                                          WHERE RoomCode = :roomCode
                                          )";
            $statement = Database::connect()->prepare($sql);
            $statement->execute([":roomCode" => $roomCode]);
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);

            if ($result != false) {
                $this->_roomID = $result[0]["RoomID"];
                $this->_roomName = $result[0]["RoomName"];
                if ($roomCode != null) {

                    foreach ($result as $row) {
                        if ($row["RoomCode"] != null)
                            $this->_room_codes[] = new RoomCode($row["RoomCode"], $row["RoomID"], $row["CreatedBy"]);
                    }
                }
            } else {
                throw new Exception("A Room with that code could not be found");
            }
        } else {
            throw new Exception("Participant could not be created with that token");
        }
    }
    /**
     * Function createRoom
     * @param $roomName
     * @param $token
     * @param $screenName
     * @throws Exception
     * @return Room
     * This Function will allow a room to be generated based on a token from
     * the account creating the room. This will allow both Account Users and
     * Temp Users to join the room.
     */
    public static function createRoom($roomName, $token, $screenName, $uses = null, $expirationDate = null)
    {
        $sql = "INSERT INTO Rooms (RoomName) VALUES (:name)";
        $statement = Database::connect()->prepare($sql);
        if (!$statement->execute([":name" => $roomName])) {
            throw new Exception("Could not create room");
        }
        $roomID = Database::connect()->lastInsertId();

        return new Room($roomID, $token, $screenName, $uses, $expirationDate);
    }
    /**
     * Function createRoomWithoutAccount
     * @param $roomName
     * @param $screenName
     * @return Room
     * @throws Exception
     * This Function will allow the generation of a room without an account
     * token, and will allow the joining of Account Users as well as Temp Users.
     */
    public static function createRoomWithoutAccount($roomName, $screenName, $uses = null, $expirationDate = null)
    {
        $sql = "INSERT INTO Rooms (RoomName) VALUES (:name)";
        $statement = Database::connect()->prepare($sql);
        if (!$statement->execute([":name" => $roomName])) {
            throw new Exception("Could not create room");
        }
        $roomID = Database::connect()->lastInsertId();
        $account = Account::CreateAccount();
        $token = $account->getToken();

        return new Room($roomID, $token, $screenName, $uses, $expirationDate);
    }
    /**
     * @return mixed
     */
    public function getRoomID()
    {
        return $this->_roomID;
    }
    /**
     * @return mixed
     */
    public function getRoomName()
    {
        return $this->_roomName;
    }
    /**
     * @param $newRoomName
     */
    public function setRoomName($newRoomName)
    {
        $this->_roomName = $newRoomName;
        $this->_has_changed = true;
    }
    /**
     * Function AddParticipant
     * @param $token
     * @param $screenName
     * @return integer
     * This Function allows a participant to be generated in a room based
     * on its account token (Temp or Perm) and a screenName that the
     * user provides. Checks how many uses are left and returns false if
     * no uses left.
     */
    public function addParticipant($token, $screenName)
    {
        $retval = false;
        if($this->_usesLeft-- >= 0 && $account = Account::Login($token)) {
            $account->addParticipant($this->getRoomID(), $screenName);
            $this->_accounts[] = $account;
            $retval = $account->getParticipantID();
        }

        return $retval;
    }
    /**
     * Function Delete
     * This function will remove a RoomCode, then all Participants, the the Room
     * that is targeted by it. This will allow referential integrity to remain valid
     * and the removal of the room from the active database.
     */
    public function delete()
    {
        $sql = "DELETE FROM RoomCodes WHERE RoomID=:roomid";
        if (!Database::connect()->prepare($sql)->execute([":roomid" => $this->_roomID])) {
            echo Database::connect()->errorInfo()[2] . "<br>";
        }
        $this->_room_codes = [];

        $sql = "DELETE FROM Participants WHERE RoomID=:roomid";
        if (!Database::connect()->prepare($sql)->execute([":roomid" => $this->_roomID])) {
            echo Database::connect()->errorInfo()[2] . "<br>";
        }
        $this->_accounts = [];

        $sql = "DELETE FROM Rooms WHERE RoomID=:roomid";
        if (!Database::connect()->prepare($sql)->execute([":roomid" => $this->_roomID])) {
            echo Database::connect()->errorInfo()[2] . "<br>";
        }
        $this->_roomID = null;
    }
    /**
     * Function deleteParticipant
     * @param $accountID
     * @return boolean
     * This function will remove the account's participant from the database.
     * This function uses an SQL statement in order to find an
     * existing participant based on an account's ID. If it succeeds in finding
     * and deleting the participant, it will return 'true'.
     * This function should be called when a room expires.
     */
    public function deleteParticipant($accountID)
    {
        $retval = false;

        $sql = "SELECT p.RoomID
                FROM Participants AS p
                  JOIN RoomCodes AS rc
                    ON p.RoomID = rc.RoomID
                WHERE AccountID = :accountID";
        $statement = Database::connect()->prepare($sql);
        $statement->execute(array(':accountID' => $accountID));
        if ($result = $statement->fetch(PDO::FETCH_ASSOC)) {
            $sql = "DELETE 
                    FROM RoomCodes
                    WHERE RoomID = :roomID";
            $statement = Database::connect()->prepare($sql);
            if ($statement->execute(array(':roomID' => $result['RoomID']))) {

                $sql = "DELETE 
                    FROM Participants
                    WHERE AccountID = :accountID";

                if ($retval = Database::connect()->prepare($sql)->execute(array(':accountID' => $accountID))) {
                    foreach ($this->_accounts as $a) {
                        if ($a->getAccountID() == $accountID) {
                            $a->_roomID = null;
                            $a->_screenName = null;
                        }
                    }
                }
            }
        }
        return $retval;
    }

    /**
     * Function Update
     * This Function allows the update of an account and roomCode based
     * on the changes made in either the account or the roomCode.
     */
    public function update()
    {
        foreach ($this->_accounts as $account) {
            $account->update();
        }
        foreach ($this->_room_codes as $rc) {
            $rc->update();
        }
        if ($this->hasChanged()) $this->updateRoom();
    }

    /**
     * Function UpdateRoom
     * This Function Changes the currenr room name in the database.
     */
    private function updateRoom()
    {
        $sql = "UPDATE Rooms SET RoomName = :roomname WHERE RoomID = $this->_roomID";
        $statement = Database::connect()->prepare($sql);
        $statement->execute([":roomname" => $this->_roomName]);
    }

    /**
     * Function AddRoomCode
     * @param $participantID
     * @param null $uses
     * @param null $expires
     * @return RoomCode
     * This Function adds a roomCode to the given participant, and will allow for
     * specific settings such as the uses remaining for the key as well as the
     * datetime that the key will expire.
     */
    public function addRoomCode($participantID, $uses = null, $expires = null)
    {
        $this->_room_codes[] = $retval =  RoomCode::createRoomCode($this->_roomID, $participantID, $uses, $expires);
        return $retval;
    }

    /**
     * @return array
     */
    public function getAccounts()
    {
        return $this->_accounts;
    }

    /**
     * @return array|null
     */
    public function getParticipants()
    {
        $participants = null;
        foreach ($this->_accounts as $p) {
            $participants[] = $p->getScreenName();
        }
        return $participants;
    }
    /**
     * @return RoomCode[]
     */
    public function getRoomCodes()
    {
        return $this->_room_codes;
    }

    /**
     * Function getJSON
     * @param bool $as_array
     * @return array|string
     * This Function allows the return of the encoded JSON object
     * to be used in different areas of the program.
     */
    public function getJSON($as_array = false)
    {
        $json = [];
        $json["Type"] = "Room";
        $json['Accounts'] = [];
        foreach($this->_accounts as $a){
            $json['Accounts'][] = json_decode($a->getJSON(), true);
        }

        $json['RoomCodes'] = [];
        foreach ($this->_room_codes as $p) {
            $json['RoomCodes'][] = json_decode($p->getJSON(), true);
        }

        $json["RoomID"] = $this->_roomID;
        $json["RoomName"] = $this->_roomName;

        if ($as_array)
            return $json;
        return json_encode($json);
    }
}