<?php
/**
 * Created by PhpStorm.
 * User: Ian Murphy
 * Date: 11/27/2016
 * Time: 8:04 PM
 * Test Name: NOINCLUDE
 */


require_once "classes/Account.php";
mark();

$a = Account::CreateAccount();
setcookie("Token", $a->getToken(), time()+31104000);
$a = Account::Login($_COOKIE["Token"]);
mark("Create Account");

$token = $a->getToken();

mark();
$account = Account::Login($token);
mark("Login with Token");

echo "Account ID: " . $a->getAccountID() . "<br>";
echo "Account Screenname: ". $a->getScreenName();