CREATE PROCEDURE AddUser( IN vEmail VARCHAR(64),
                          IN vFirstName VARCHAR(32),
                          IN vLastName VARCHAR(32),
                          IN vPasswordHash VARCHAR(60),
                          IN vLoginToken VARCHAR(50),
                          IN vTokenGenTime DATETIME,
                          IN vLastLogin DATETIME,
                          IN vJoinDate DATETIME)
  BEGIN
    INSERT INTO Accounts
    (Email, FirstName, LastName, PasswordHash, LoginToken, TokenGenTime, LastLogin, JoinDate)
    VALUES(vEmail, vFirstName, vLastName, vPasswordHash, vLoginToken, vTokenGenTime, vLastLogin, vJoinDate);
  END
  ;

CREATE PROCEDURE RoomLogs(IN vRoomID BIGINT UNSIGNED)
  BEGIN
    SELECT *
    FROM Logs
      JOIN Accounts a
      ON a.AccountID = Logs.AccountID
    WHERE RoomID = vRoomID
    ORDER BY Time ASC;
  END;
