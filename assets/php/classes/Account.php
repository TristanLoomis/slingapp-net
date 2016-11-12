<?php
/**
 * Accounts Class
 * Created by PhpStorm.
 * User: Isaac, Tristan
 * Date: 11/6/2016
 * Time: 9:21 AM
 */
set_include_path(realpath($_SERVER["DOCUMENT_ROOT"]) . "/assets/php/");
require_once "classes/Database.php";
require_once "interfaces/DatabaseObject.php";

/**
 * This Class handles all Accounts and linked participants in the database.
 * This class will create a new account for  user that does not already have
 * one based on the status of the login token. The account will be used in order
 * to maintain a single participant to any room a single account is participating
 * in. The account class can have at most one participant per session.
 *
 * This class uses SQL statements in order to locate data pertaining to any current
 * accounts in the database and any participants linked to that account and any room
 * that the single participant and account are participating in.
 * */
class Account extends DatabaseObject
{
    /**
     * @return string[]
     */
    public function getName()
    {
        return ["First" => $this->_fName, "Last" => $this->_lName];
    }

    /**
     * @return null
     */
    private $_fName;
    private $_lName;
    private $_email;
    private $_passHash;
    private $_token;
    private $_tokenGen;
    private $_lastLogin;
    private $_joinDate;
    private $_accountID;
    private $_roomID;
    private $_screenName;

    /**
     * This Constructor is used to create a new account based on data that is retrieved from the user at
     * the point of creation. This will include
     * First Name
     * Last Name
     * Email
     * Password
     * The Class will then retrieve/generate
     * AccountID
     * Password Hash
     * Token
     * Token Gen
     * Last Login
     * Join Date
     * These elements make up the new account in the database and will persist until removed on command
     * by the Delete Account function.
     */
    public function __construct($accountID, $token, $_tokenGen, $email = null, $fName = null, $lName = null, $passHash = null
        , $lastLogin = null, $joinDate = null)
    {
        $this->_accountID = $accountID;
        $this->_email = $email;
        $this->_fName = $fName;
        $this->_lName = $lName;
        $this->_passHash = $passHash;
        $this->_token = $token;
        $this->_tokenGen = $_tokenGen;
        $this->_lastLogin = $lastLogin;
        $this->_joinDate = $joinDate;
    }

    /**
     * Function CreateAccount
     * @param $email
     * @param $fName
     * @param $lName
     * @param $password
     * @return Account
     * @throws Exception
     * This function is used to create a new account in the database. It will be called when a user
     * is attempting to join a room without already having an account, or when a user opts to register
     * a new account with the Sling Application.
     * This Function executes the SQL DML Statement Insert to add a new account to the database.
     */
    public static function CreateAccount($email, $fName, $lName, $password)
    {

        $passHash = password_hash($password, PASSWORD_BCRYPT);
        $token = md5(uniqid(mt_rand(), true));
        $currentDate = gmdate("Y-m-d H:i:s");

        $sql = "INSERT INTO Accounts 
                (Email, FirstName, LastName, PasswordHash, LoginToken, TokenGenTime, LastLogin, JoinDate)  
                VALUES(:email, :fName, :lName, :passHash, :logTok, :tokGen, :lastLog, :joinDate)";

//        $sql = "CALL AddUser(:email, :fName, :lName, :passHash, :logTok, :tokGen, :lastLog, :joinDate)";
        $statement = Database::connect()->prepare($sql);

        if (!$statement->execute([
            ':email' => $email,
            ':fName' => $fName,
            ':lName' => $lName,
            ':passHash' => $passHash,
            ':logTok' => $token,
            ':tokGen' => $currentDate,
            ':lastLog' => $currentDate,
            ':joinDate' => $currentDate
        ])
        ) {
            var_dump(Database::connect()->errorInfo());
            throw new Exception("Could not create account");
        }

        $accountID = (int)Database::connect()->lastInsertId();

        return new Account($accountID, $token, $currentDate, $email, $fName, $lName, $passHash
            , $currentDate, $currentDate);

    }

    /**
     * Function Login
     * @param $token_email
     * @param null $password
     * @return Account|false
     * This function facilitates the data lookup of a user who is attempting to log into the Sling Application.
     * If the user has provided a password the function will return the account data through an SQL query based
     * on the stored password.
     * If the user has not provided a password, then the system will return a new account provided a login token.
     * If the username or password do not match, the system will return false
     */
    public static function Login($token_email, $password = null)        //add validity checks
    {
        $retval = null;
        $currentDate = gmdate("Y-m-d H:i:s");
        if ($password) {
            $sql = "SELECT *
                FROM Accounts
                WHERE Email = :email";
            $statement = Database::connect()->prepare($sql);
            $statement->execute(array(':email' => $token_email));
            $result = $statement->fetch(PDO::FETCH_ASSOC);

            if (!password_verify($password, $result['PasswordHash']))    //adds quiet a bit of overhead
                $retval = false;
            else {
                $retval = new Account($result['AccountID'], $result['LoginToken'], $result['TokenGenTime'],
                    $result['Email'], $result['FirstName'], $result['LastName'], $currentDate, $result['JoinDate']);

            }
        } else {      //no password provided, lookup based on token
            $sql = "SELECT *
                FROM Accounts
                WHERE LoginToken = :logtok";

            $statement = Database::connect()->prepare($sql);
            $statement->execute(array(':logtok' => $token_email));
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $retval = new Account($result['AccountID'], $result['LoginToken'], $result['TokenGenTime'],
                    $result['Email'], $result['FirstName'], $result['LastName'], $result['LastLogin'], $result['JoinDate']);
            } else {
                $retval = false;
            }
        }
        return $retval;
    }

    /**
     * Function Delete
     * @return boolean
     * This function will remove an account from the database.
     * This function uses an SQL statement in order to find an
     * existing account based on an account's token. The returned
     * accountID will then be used to remove any associated
     * participants and then will remove the account itself.
     * This function can be used to either have an account
     * explicity deleted by the user or automatically for
     * temporary accounts
     */

    public function delete()
    {
        $retval = false;

        $sql = "SELECT AccountID                                                                    
                    FROM Accounts
                    WHERE LoginToken = :logtok";
        $statement = Database::connect()->prepare($sql);

        if ($statement->execute(array(':logtok' => $this->_token))) {
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);

            $this->_accountID = $result[0]["AccountID"];

            //if a participant exists, delete it (check for roomID)
            if ($this->_roomID) {
                $sql = "DELETE FROM Particpants
                    WHERE AccountID = $this->_accountID";
                $statement = Database::connect()->prepare($sql);
                $retval = $statement->execute();
                $this->_roomID = null;
                $this->_screenName = null;

            }//if participant was deleted successfully or if it didn't exist, delete account
            if (!$this->_roomID) {
                $sql = "DELETE FROM Accounts
                        WHERE AccountID = $this->_accountID";
                $statement = Database::connect()->prepare($sql);
                $retval = ($statement->execute()) ? true : false;
            }
        }
        return $retval;
    }

    /**
     * Function deleteParticipant
     * @return boolean
     * This function will remove the account's participant from the database.
     * This function uses an SQL statement in order to find an
     * existing participant based on an account's ID. If it succeeds in finding
     * and deleting the participant, it will return 'true'.
     * This function should be called when a room expires.
     */
    public function deleteParticipant()
    {
        $sql = "DELETE FROM Participants AS p
                            JOIN Accounts AS a
                              ON p.AccountID = a.AccountID
                WHERE p.AccountID = :accountID";

        if($retval = Database::connect()->prepare($sql)->execute(array(':accountID' => $this->_accountID))){
            $this->_roomID = null;
            $this->_screenName = null;
        }

        return $retval;
    }
    /**
     * Function Update
     * This function will trigger whenever a setter is used or a user attempts to join a room,
     * it will attempt to insert the account
     * data and the participant data to correlate with the new room and participant status.
     */
    //NEEDED:   Update Account status based on room to join and allow linked participant to join room
    //NEEDED:   Test that allows room to be created-> then account-> then update to move account and part. to room
    public function update()
    {
            if ($this->_roomID != null) {
                //Do select statement and pull account id after account created.
                $sql = "INSERT INTO Participants
                (AccountID, RoomID, ScreenName)
                VALUES (:accountID, :roomID, :screenName)";
                $statement = Database::connect()->prepare($sql);
                if ($statement->execute(array(':accountID' => $this->_accountID, ':roomID' => $this->_roomID
                , ':screenName' => $this->_screenName))
                ) {
                    $result = $statement->fetchAll(PDO::FETCH_ASSOC);
                    #foreach($result as $row)
                    #    var_dump($row);
                }
            }
            //create error if result is nothing
            //FOUND OUT WHAT WAS WRONG, tests failed because no room existed, ref. integrity.
            //Need REAL account info
            //NEED ACTUAL room id

//        if(!$statement->execute(array(':accountID' => 101, ':roomID' => 123, ':screenName' => "TEST_SCREEN_NAME"))) {
//            DatabaseObject::Log(__FILE__, "ParticipantsUpdate", "Could Not Insert");
//        }
    }

    /**
     * Function updateAfterSetter
     * This function will trigger whenever a setter is used and will attempt to insert the
     * updated account/participant data. Data must pass validity checks before this function
     * is called. Validity checks are in the __set() function
     */
    private function updateAfterSetter()
    {
        if($this->_roomID) {            //account has participant
            $sql = "UPDATE Accounts AS a 
                      JOIN Participants AS p
                        ON a.AccountID = p.AccountID
                    SET Email = :email,
                    FirstName = :fName,
                    LastName = :lName,
                    PasswordHash = :passHash,
                    LoginToken = :logTok,
                    TokenGenTime = :tokGen,
                    LastLogin = :lastLog,
                    JoinDate = :joinDate,
                    ScreenName = :screenName,
                    
                WHERE a.AccountID = :accountID";

            $statement = Database::connect()->prepare($sql);
            $statement->execute(array(':email' => $this->_email,
                ':fName' => $this->_fName,
                ':lName' => $this->_lName,
                ':passHash' => $this->_passHash,
                ':logTok' => $this->_token,
                ':tokGen' => $this->_tokenGen,
                ':lastLog' => $this->_lastLogin,
                ':joinDate' => $this->_joinDate,
                ':accountID' => $this->_accountID,
                ':screenName' => $this->_screenName));
        } else {    //account doesn't have a participant
            $sql = "UPDATE Accounts
                SET Email = :email,
                    FirstName = :fName,
                    LastName = :lName,
                    PasswordHash = :passHash,
                    LoginToken = :logTok,
                    TokenGenTime = :tokGen,
                    LastLogin = :lastLog,
                    JoinDate = :joinDate
                WHERE AccountID = :accountID";

            $statement = Database::connect()->prepare($sql);
            $statement->execute(array(':email' => $this->_email,
                ':fName' => $this->_fName,
                ':lName' => $this->_lName,
                ':passHash' => $this->_passHash,
                ':logTok' => $this->_token,
                ':tokGen' => $this->_tokenGen,
                ':lastLog' => $this->_lastLogin,
                ':joinDate' => $this->_joinDate,
                ':accountID' => $this->_accountID));
        }
    }

    private function isNameValid($name)
    {
        $nameExp = "/^[^<,\"(){}@*$%?=>:|;#]*$/i";
        return preg_match($nameExp, $name) ? 1 : 0;
    }

    /**
     * @return mixed
     */
    public function getAccountID()
    {
        return $this->_accountID;
    }

    /**
     * @return mixed
     */
    public function getToken()
    {
        return $this->_token;
    }

    /**
     * Function getJSON
     * @return string | array
     * This function allows the Accounts type to be encoded.
     */
    public function getJSON($as_array = false)
    {
        $json = [];
        $json['Type'] = "Account";
        $json['Email'] = $this->_email;
        $json["FirstName"] = $this->_fName;
        $json["LastName"] = $this->_lName;
        $json["LoginToken"] = $this->_token;
        $json['ID'] = $this->_accountID;
        $json['ScreenName'] = $this->_screenName;
        if ($as_array)
            return $json;
        return json_encode($json);
    }

    /**
     * @return mixed
     */
    public function getScreenName()
    {
        return $this->_screenName;
    }

    /**
     * Function getEmail
     * @return mixed
     * This function allows the Current Account Email to be returned.
     */
    public function getEmail()
    {
        return $this->_email;
    }

    function __set($name, $value)
    {
        switch (strtolower($name)) {
            case "_email":
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $temp = $this->_email;
                    $this->_email = $value;
                    DatabaseObject::Log(__FILE__, "Updated Account",
                        "Account: $this->_accountID \n Updated Email From: $temp to: $value");
                } else
                    throw new Exception("Email is not valid, please try again.");


                break;
            case "_fname":
                if ($this->isNameValid($value)) {
                    $temp = $this->_fName;
                    $this->_fName = $value;
                    DatabaseObject::Log(__FILE__, "Updated Account",
                        "Account: $this->_accountID \n Updated First Name From: $temp to: $value");
                }
                else
                    throw new Exception("First name is not valid, please try again.");


                break;
            case "_lname":
                if ($this->isNameValid($value)) {
                    $temp = $this->_lName;
                    $this->_lName = $value;
                    DatabaseObject::Log(__FILE__, "Updated Account",
                        "Account: $this->_accountID \n Updated Last Name From: $temp to: $value");
                } else
                    throw new Exception("Last name is not valid, please try again.");

                break;
            case "_passhash":
                if (strlen($value) >= 6 && strlen($value) <= 30) {     //password should be between 6 to 30 charactesr
                    $hashedpass = password_hash($value, PASSWORD_BCRYPT);

                    $this->_passHash = $hashedpass;
                    DatabaseObject::Log(__FILE__, "Updated Account",
                        "Account: $this->_accountID \n updated password");  //should we log this?
                } else
                    throw new Exception("Password must be between 6 - 30 characters");


                break;
            case "_token":
                $this->_token = md5(uniqid(mt_rand(), true));
                $this->_tokenGen = gmdate('Y-m-d H:i:s');
                DatabaseObject::Log(__FILE__, "Updated Account",
                    "Account: $this->_accountID \n Updated token");
                break;
            case "_screenname":             //add validation check
                $temp = $this->_screenName;
                $this->_screenName = $value;
                DatabaseObject::Log(__FILE__, "Updated Account",
                    "Account: $this->_accountID \n Updated screenname from: $temp to: $value");
                break;
            default:
                DatabaseObject::Log(__FILE__, "Updated Account",
                    "Account: $this->_accountID \n set method using: $name wasn't valid");
        }

            $this->updateAfterSetter();

        return $value;
    }
}