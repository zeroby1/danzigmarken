<?php
namespace Dzg\Cls;

/****************************
 * Funktionscontainer für den Anmelde- und Verifizierungsprozess
 *
 ****************************/

#echo urldecode("id=%27nvOpzp;%20AND%201=1%20OR%20(%3C%27%22%3EiKO)),");

/*
 * A complete login script with registration and members area.
 *
 * @author: Nils Reimers / http://www.php-einfach.de/experte/php-codebeispiele/loginscript/
 * @license: GNU GPLv3
 */


date_default_timezone_set('Europe/Berlin');

require_once __DIR__.'/Database.php';
include_once __DIR__.'/../inc/auth.password.func.php';
include_once __DIR__.'/Tools.php';

use PDO, PDOException;
use Dzg\Cls\{Database, Tools};

/*
 * Stammverzeichnis festlegen, bei Aufruf aus Unterverzeichnis (wie auth/login.php)
 * sonst Probleme zB. mit css Aufruf
 * nötig für Cookies, Header, Footer
 */
$_SESSION['rootdir'] = Tools::rootdir();


// Startseite festlegen, $_SESSION['main']
if (!isset($_SESSION['main'])) {
    $_SESSION['main'] = $_SESSION['rootdir'].'/index.php';
}


/****************************
 * Summary of Auth
 * Funktionscontainer für den Anmelde- und Verifizierungsprozess
 */
class Auth
{
    /***********************
     * Klassenvariablen / Eigenschaften
     */
    public static array $error_arr;
    public static $pdo;


    public static function get_pdo(): PDO
    {
        if (!is_object(self::$pdo)) self::set_pdo();
        return self::$pdo;
    }
    protected static function set_pdo(): PDO
    {
        // Verbindung zur Datenbank
        $pdo = (is_object(Database::$pdo))
            ? Database::get_pdo()
            : Database::connect_mariadb();

        self::$pdo = $pdo;
        return $pdo;
    }


    /***********************
     * Logge den Benutzer ein, hole dessen Daten aus der DB
     */
    public static function login($userid): array
    {
        // userid prüfen
        if ($userid == (int)$userid) {
            $error_arr = [];

            // Benutzerdaten aus DB holen
            $usr_data = self::get_user_data($userid);

            // UserID in DB gefunden, globale Login-Session-Werte setzen
            if ($usr_data) {
                self::set_login_session($usr_data);

            } else {
                $error_arr []= "#login: User-ID nicht gefunden ";
                self::logout();
            }
        } else
            $error_arr []= "#login: keine korrekte Login-User-ID übergeben ";

        if (!empty($error_arr)) self::$error_arr []= $error_arr;
        return [$usr_data, Tools::arr2str($error_arr)];
    }


    /***********************
     * Summary of logout
     */
    public static function logout($target = "")
    {
        if ($target == "") {
            if (empty($_SESSION['lastsite'])) {

                // Herkunftsseite speichern
                $return2 = ['index', 'index2', 'details', 'login', 'email'];
                if (isset($_SERVER['HTTP_REFERER']) && (strpos($_SERVER['HTTP_REFERER'], $_SERVER['PHP_SELF']) === false)) {

                    // wenn VorgängerSeite bekannt und nicht die aufgerufene Seite selbst ist, speichern
                    $referer = str_replace("change", "details", $_SERVER['HTTP_REFERER']);
                    $fn_referer = pathinfo($referer)['filename'];

                    // wenn Herkunft von den target-Seiten, dann zu diesen, ansonsten Standardseite
                    $_SESSION['lastsite'] =  (in_array($fn_referer, $return2))
                        ? $referer
                        : $_SESSION['main'];

                } elseif (empty($_SERVER['HTTP_REFERER']) && empty($_SESSION['lastsite'])) {

                    // wenn nix gesetzt ist, auf Standard index.php verweisen
                    $_SESSION['lastsite'] = (!empty($_SESSION['main'])) ? $_SESSION['main'] : "/";
                }
            }
            $target = $_SESSION['lastsite'];
        }

        self::logout2();

        header("location: {$target}");
        exit;
    }


    private static function logout2()
    {
        $pdo = self::get_pdo();
        $status = false;

        $error_arr = [];
        if (isset($_SESSION['userid'])) {
            $userid = $_SESSION['userid'];

            if (!preg_match('/^[0-9]{1,19}$/', $userid)
                || $userid != (int)$userid)
            {
                $error_arr []= "#logout: unzulässige User-ID ";

            } else {
                // Cookies löschen --> DB ausloggen
                if (self::plausi_check_autocookie()) {
                    $identifier = $_COOKIE['auto_identifier'];
                    $token_hash = $_COOKIE['auto_token'];

                    $stmt0 = "UPDATE site_login SET login = NULL, autologin = NULL
                        WHERE identifier = :identifier";  # muss per Admin gelöscht werden (tote Logins)
                    $stmt = "DELETE FROM site_login WHERE identifier = :identifier";

                    $data = [":identifier" => $identifier];
                    try {
                        $qry = $pdo->prepare($stmt0);
                        $qry->execute($data);
                    } catch(PDOException $e) {die($e->getMessage().': auth.func.logout()_del-user#1');}

                    self::delete_autocookies();
                    $status = true;

                } else {
                    $stmt0 = "UPDATE site_login SET login = NULL
                        WHERE userid = :userid AND identifier IS NULL";  # dadurch Info 'last.seen'
                    $stmt = "DELETE FROM site_login WHERE userid = :userid AND identifier IS NULL";

                    try {
                        $qry = $pdo->prepare($stmt0);
                        $qry->bindParam(":userid", $userid, PDO::PARAM_INT);
                        $qry->execute();
                    } catch(PDOException $e) {die($e->getMessage().': auth.func.logout()_del-user#2');}
                    $status = true;
                }
            }
        }
        if (!empty($error_arr)) self::$error_arr []= $error_arr;

        #$session = ['userid', 'status', 'su', 'sort', 'dir', 'col', 'start', 'proseite', 'thema', 'search', 'loggedin', 'autologin'];
        $session = ['userid', 'status', 'su', 'loggedin', 'autologin'];
        foreach($session AS $i) {
            unset($_SESSION[$i]);
        }
        #unset($_REQUEST, $_POST, $_GET);
        $pdo = NULL;

        return $status;
    }


    /***********************
     * Summary of delete_autocookies
     */
    private static function delete_autocookies()
    {
        // Remove Cookies
        if (isset($_COOKIE['auto_identifier']) || isset($_COOKIE['auto_token'])) {
            setcookie("auto_identifier", "", time() - 3600, "/", "", 1);
            setcookie("auto_token", "", time() - 3600, "/", "", 1);
            unset($_COOKIE['auto_identifier'], $_COOKIE['auto_token']);

            // löscht hier aber nix :-(
            /*echo "<script>
                localStorage.removeItem('auto_identifier');
                localStorage.removeItem('auto_token');
                localStorage['auto_token']= '';
                localStorage.clear();
            </script>";*/

            #session_regenerate_id();
        }

        $session = ['login_id', 'ident', 'autologin'];
        foreach ($session as $k) {unset($_SESSION[$k]);}

        // Remove OLD Cookies
        if (isset($_COOKIE['login_ID']) || isset($_COOKIE['login_token'])) {
            setcookie("login_ID", "", time() - 3600, "/", "", 1);
            setcookie("login_token", "", time() - 3600, "/", "", 1);
            unset($_COOKIE['login_ID'], $_COOKIE['login_token']);

            // löscht hier aber nix :-(
            /*echo "<script>
                localStorage.removeItem('login_ID');
                localStorage.removeItem('login_token');
                localStorage['login_token']= '';
                localStorage.clear();
            </script>";*/

            #session_regenerate_id();
        }
    }


    /***********************
     * Autologin-Cookies/Session löschen und aus Datenbank austragen
     */
    private static function delete_autologin()
    {
        $pdo = self::get_pdo();

        // wenn Autologin-Cookies --> DB auslogen
        if (self::plausi_check_autocookie()) {
            $identifier = $_COOKIE['auto_identifier'];
            $token_hash = $_COOKIE['auto_token'];

            #$stmt = "DELETE FROM site_login WHERE identifier=:ident AND token_hash=:token_hash";
            $stmt = "UPDATE site_login SET login = NULL, autologin = NULL
                WHERE identifier = :ident AND token_hash = :token_hash";

            $data = [':ident' => $identifier, ':token_hash' => $token_hash];
            try {
                $qry = $pdo->prepare($stmt);
                $qry->execute($data);
            } catch(PDOException $e) {die($e->getMessage().': auth.del_autologin()');}

            self::delete_autocookies();
        }
    }


    /***********************
     * Summary of plausi_check_autocookie
     */
    private static function plausi_check_autocookie(): bool
    {
        if (isset($_COOKIE['auto_identifier'], $_COOKIE['auto_token'])) {

            $identifier = (!empty($_COOKIE['auto_identifier']))
                ? htmlspecialchars($_COOKIE['auto_identifier'], ENT_QUOTES)
                : "";
            $token_hash = (!empty($_COOKIE['auto_token']))
                ? htmlspecialchars($_COOKIE['auto_token'], ENT_QUOTES)
                : "";
            $error_arr = [];

            // Plausi-Check...
            // random_16bytes: 32 Zeichen
            if (!preg_match("/^[a-zA-Z0-9]{32}$/", $identifier)) {
                $error_arr []= "*** unzulässige Zeichen im Identifier-Cookies ***";
            }
            // sha1_hash: 40 Zeichen
            if (!preg_match("/^[a-zA-Z0-9]{40}$/", $token_hash)) {
                $error_arr []= "*** unzulässige Zeichen im Token-Cookie ***";
            }

            if (!empty($error_arr)) {
                self::delete_autocookies();  # Remove Autologin-Cookies
                #self::logout();
            }
        } else $error_arr []= "keine Autologin-Cookies gefunden";


        if (isset($_COOKIE['login_ID'], $_COOKIE['login_token'])) {
            // Set New Cookies
            setcookie("auto_identifier", $_COOKIE['login_ID'], time() + 3600*24*180, "/", "", 1);
            setcookie("auto_token", $_COOKIE['login_token'], time() + 3600*24*180, "/", "", 1);
            $_COOKIE['auto_identifier'] = $_COOKIE['login_ID'];
            $_COOKIE['auto_token'] = $_COOKIE['login_token'];

            // Remove Old Cookies
            setcookie("login_ID", "", time() - 3600, "/", "", 1);
            setcookie("login_token", "", time() - 3600, "/", "", 1);
            unset($_COOKIE['login_ID'], $_COOKIE['login_token']);

            #session_regenerate_id();
        }

        if (!empty($error_arr)) self::$error_arr []= $error_arr;
        return (empty($error_arr)) ? true : false;
    }


    /***********************
     * Summary of get_user_data
     * Benutzerdaten aus DB holen
     */
    private static function get_user_data($userid)
    {
        $pdo = self::get_pdo();
        $error_arr = [];
        $stmt = "SELECT * FROM site_users WHERE userid = :userid";
        try {
            $qry = $pdo->prepare($stmt);
            $qry->bindParam(":userid", $userid, PDO::PARAM_INT);
            $qry->execute();
            $usr_data = $qry->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {die($e->getMessage().': auth.func.login()_usrdata');}
        return $usr_data;
    }


    /***********************
     * Summary of get_login_data
     * Login-Daten aus DB holen
     */
    private static function get_login_data(int $userid=0)
    {
        $pdo = self::get_pdo();

        // mit Autologin
        if (!$userid) {

            $identifier = $_COOKIE['auto_identifier'];
            $token_hash = $_COOKIE['auto_token'];

            // Kombi Identifier/Token in DB finden und Login-Infos holen
            $stmt = "SELECT * FROM site_login WHERE token_hash = :token_hash AND identifier = :ident";
            $data = [':token_hash' => $token_hash, ':ident' => $identifier];
            try {
                $qry = $pdo->prepare($stmt);
                $qry->execute($data);
                $login_data = $qry->fetch(PDO::FETCH_ASSOC);
            } catch(PDOException $e) {die($e->getMessage().': auth.func.get_login_data().site_login');}

        // ohne Autologin
        } else {
            $stmt = "SELECT * FROM site_login WHERE userid = :userid AND identifier IS NULL";
            try {
                $qry = $pdo->prepare($stmt);
                $qry->bindParam(":userid", $userid, PDO::PARAM_INT);
                $qry->execute();
                $login_data = $qry->fetch(PDO::FETCH_ASSOC);
            } catch(PDOException $e) {die($e->getMessage().': auth.func.login()_secrow');}
        }

        return $login_data;
    }


    /***********************
     * Summary of set_login_session
     */
    public static function set_login_session($usr_data)
    {
        $_SESSION['userid'] = $usr_data['userid'];
        $_SESSION['loggedin'] = true;
        $_SESSION['su'] = ($usr_data['su'] == 1) ? true : null;
        $_SESSION['status'] = ($usr_data['status'] === "activated") ? "activ" : null;
        foreach ($_SESSION as $k=>$v) {
            if ($v === null) unset($_SESSION[$k]);
        }
    }

    /***********************
     * Summary of set_login_session
     */
    public static function set_autologin_session($login_id, $identifier)
    {
        $_SESSION['login_id'] = $login_id;
        $_SESSION['ident'] = $identifier;
        $_SESSION['autologin'] = true;
    }


    /***********************
     * Summary of refresh_autologin
     * neuen Token & Gültigkeitsdauer setzen
     * DB, Cookies, Autologin-Session aktualisieren
     *
     * DB-Login-Daten zuückgeben
     */
    private static function refresh_autologin(&$login_data)
    {
        $pdo = self::get_pdo();
        #if (self::plausi_check_autocookie()):

        $identifier = $_COOKIE['auto_identifier'];
        $token_hash = $_COOKIE['auto_token'];
        $token_timer = time() + 3600*24*365;  // gültig für 1 Jahr

        $token_endtime = date('Y-m-d H:i:s', $token_timer);
        $ip = self::remote_addr();

        // neuen Token setzen
        $newtoken_hash = sha1(self::random_string());
        $stmt = "UPDATE site_login
            SET token_hash = :newtoken, token_endtime = :token_endtime, login = 1, autologin = 1, ip = :ip
            WHERE token_hash = :oldtoken AND identifier = :ident";

        $stmt1 = "SELECT id FROM site_login WHERE identifier = :ident";

        try {
            $qry = $pdo->prepare($stmt);
            $qry->bindParam(":newtoken", $newtoken_hash, PDO::PARAM_STR);
            $qry->bindParam(":oldtoken", $token_hash, PDO::PARAM_STR);
            $qry->bindParam(":ident", $identifier, PDO::PARAM_STR);
            $qry->bindParam(":token_endtime", $token_endtime, PDO::PARAM_STR);
            $qry->bindParam(":ip", $ip, PDO::PARAM_STR);
            $qry->execute();

            $qry = $pdo->prepare($stmt1);
            $qry->execute([":ident" => $identifier]);
            $login_id = $qry->fetch()[0];

        } catch(PDOException $e) {die($e->getMessage().': auth.func.refresh_autologin()');}

        // Cookies neu setzen
        #session_regenerate_id();
        setcookie("auto_identifier", $identifier, $token_timer, "/", "", 1);
        setcookie("auto_token", $newtoken_hash, $token_timer, "/", "", 1);
        $_COOKIE['auto_identifier'] = $identifier;
        $_COOKIE['auto_token'] = $newtoken_hash;

        // Session-Autologin-Werte setzen
        self::set_autologin_session($login_id, $identifier);

        // Cookie Variante, LocalStorage
        /*echo "<script>
            localStorage.setItem('auto_identifier', '$identifier');
            localStorage.setItem('auto_token', '$newtoken_hash');
        </script>";*/


        // Array-Einträge mit neuen Werten überschreiben
        // durch den & Parameter im Funktionsaufruf wird das Original-Array verändert
        $new = [
            'token_hash' => $newtoken_hash,
            'token_endtime' => $token_endtime,
            'ip' => $ip];
        $login_data = array_merge($login_data, $new);
    }


    /***********************
     * Checks that the user is logged in.
     * Returns the row of the logged in user
     */
    public static function check_user(): array
    {
        $pdo = self::get_pdo();

        $ip = self::remote_addr();
        $now = time();

        $usr_data = [];
        $login_data = [];
        $error_arr = [];
        $error_msg = "";
        $success_msg = "";
        $exit = false;

        // -0- Heimnetz -> ohne Anmeldung

        // -1- nicht angemeldet, aber Cookies (dauerhaft) --> anmelden
        if (!isset($_SESSION['userid']) && self::plausi_check_autocookie()):

            // mit den Autologin-Cookies die DB-Login-Daten holen
            $login_data = self::get_login_data();

            // die Kombi Identifier/Token nicht in der DB gefunden  --> no login
            if (empty($login_data)) {
                self::delete_autocookies();
                $error_arr []= "#1.chkusr: cookie trouble, token not found .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                #$exit = true;

            // Token ist veraltet --> no login
            } elseif (strtotime($login_data['token_endtime']) < $now) {
                self::delete_autologin();
                $error_arr []= "#1.chkusr: Sitzung ist abgelaufen .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                #$exit = true;

            // Cookie-ID/Token in DB gefunden, aber abgemeldet auto=0
            } elseif (empty($login_data['autologin'])) {
                self::delete_autocookies();
                $error_arr []= "#1.chkusr: Sitzung wurde abgemeldet .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                #$exit = true;

            // Cookie-Ident/Token korrekt --> einloggen
            } else {
                self::refresh_autologin($login_data);
                [$usr_data, $error_X] = self::login($login_data['userid']);
                $success_msg = "#1.chkusr: auto.login ";
            }


        // -2- angemeldet, mit Autologin-Cookies
        elseif (!empty($_SESSION['userid']) && self::plausi_check_autocookie()):

            $userid = $_SESSION['userid'];

            // Plausi-Check...
            //
            // mariadb: (abs)BIGINT: 2^64-1, 18.446.744.073.709.551.615 > 18.44*10^18,
            // mariadb: BIGINT: 2^64/2-1, +-9.223.372.036.854.775.807 > 9.22*10^18,
            // mariadb: (abs)INT: 2^32-1, 4.294.967.295 > 4.29*10^9,
            // mariadb: INT: 2^32/2-1, +-2.147.483.647 > 2.14*10^9,
            // php, int max: 2^64, 9.223.372.036.854.775.807, 9.22*10^18, 9.22E+18,
            // dann Umwandlung in float/double
            // [0-9]:Zahl mit 1 bis 19 Ziffern, 10*10^18-1, zu große Zahl > php.int.max
            // [0-9]:Zahl mit 1 bis 18 Ziffern,  1*10^18-1, zu kleine Zahl < php.int.max
            //
            // Umwandlung in int ergibt bei string mit Buchst od. zu grosser Zahl php.int.max
            // -> Vgl mit Original dann negativ
            // int grösser 9*10^18 (php.int.max) wird autom. in double/float gewandelt,
            // die Wandlung in int ergibt neg. Integerzahl -> Wert-Vgl mit Original dann negativ
            #var_dump(PHP_INT_MAX);

            // Zahl mit 1 bis 19 Ziffern, 10*10^18-1, in php aber nur 9*10^18 möglich
            if (!preg_match('/^[0-9]{1,19}$/', $userid)
                || $userid != (int)$userid)
            {
                $error_arr []= "#2.chkusr: unzulässige User-ID .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                $exit = true;

            // Plausi-Check okay --> Anmeldeprozedur
            } else {

                // mit den Autologin-Cookies die DB-Login-Daten holen
                $login_data = self::get_login_data();

                // die Kombi Identifier/Token nicht in der DB gefunden  --> ausloggen
                if (empty($login_data)) {
                    self::delete_autocookies();
                    $error_arr []= "#2.chkusr: cookie trouble, token not found .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                    $exit = true;

                // Token ist veraltet --> ausloggen
                } elseif (strtotime($login_data['token_endtime']) < $now) {
                    self::delete_autologin();
                    $error_arr []= "#2.chkusr: Sitzung ist abgelaufen .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                    $exit = true;

                // Cookie-ID/Token in DB gefunden, aber abgemeldet auto=0
                } elseif (empty($login_data['autologin'])) {
                    self::delete_autocookies();
                    $error_arr []= "#2.chkusr: Sitzung wurde abgemeldet .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                    $exit = true;

                // DB_login.UserID <> Session_UserID verschieden --> ausloggen
                } elseif ($login_data['userid'] != $userid) {
                    self::delete_autocookies();
                    $error_arr []= "#2.chkusr: Online.UserID passt nicht zur registr. UserID .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                    $exit = true;

                // UserID & Cookie-Ident/Token korrekt --> einloggen
                } else {
                    self::refresh_autologin( $login_data);
                    [$usr_data, $error_X] = self::login($login_data['userid']);
                    $success_msg = "#2.chkusr: auto.login ";
                }

            }  // Plausi-Check okay


        // -3- angemeldet, ohne Cookie
        elseif (!empty($_SESSION['userid']) && !self::plausi_check_autocookie()):

            $userid = $_SESSION['userid'];

            // Plausi-Check userid ...
            if ($userid != (int)$userid) {
                $error_arr []= "#3.chkusr: unzulässige User-ID .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                $exit = true;

            // Plausi-Check okay --> Login erneuern
            } else {

                // userid in DB suchen
                $stmt = "SELECT userid FROM site_login
                        WHERE userid=:userid AND identifier IS NULL";

                try {
                    $qry = $pdo->prepare($stmt);
                    $qry->bindParam(":userid", $userid, PDO::PARAM_INT);
                    $qry->execute();
                    $usr_id = $qry->fetch()[0];

                } catch(PDOException $e) {die($e->getMessage().': auth.func.check_user()_#3-userid');}

                // wenn Benutzer vorhanden, Login setzen
                if (!empty($usr_id)) {

                    $stmt = "UPDATE site_login SET login=1, ip=:ip
                        WHERE userid=:userid AND identifier IS NULL";
                    try {
                        $qry = $pdo->prepare($stmt);
                        $qry->bindParam(":userid", $userid, PDO::PARAM_INT);
                        $qry->bindParam(":ip", $ip, PDO::PARAM_STR);
                        $qry->execute();

                    } catch(PDOException $e) {
                        die($e->getMessage().": auth.func.check_user()_#3-storelogin");
                    }

                    [$usr_data, $error_X] = self::login($userid);
                    $login_data = self::get_login_data($userid);
                    $success_msg = "#3.chkusr: login ";

                } else {
                    $error_arr []= "#3.1_chkusr: nichtvorhandene User-ID .. neue <a href='login.php'>Anmeldung</a> notwendig ";
                    $exit = true;
                }
            }

        // -4- nicht angemeldet, keine Cookies
        else:
            #$exit = true;

        endif;


        // Erfolgsmeldung
        if ($success_msg !== "") {
            #echo $success_msg;
        }

        // Fehlermeldung
        if (!empty($error_arr)) {
            self::$error_arr []= $error_arr;
            $error_msg = Tools::arr2str($error_arr);
            #echo $error_msg;
        }

        if ($exit) {
            #self::logout2();
            #exit;
            self::logout();
            #self::logout($_SERVER['PHP_SELF']);
        }

        return [$usr_data, $login_data, $error_msg];
    }


    /***********************
     * Returns true when the user is checked in, else false
     */
    public static function is_checked_in(): bool
    {
        if (!isset($_SESSION['userid'])) {
            self::check_user();
        };
        return isset($_SESSION['userid']);
        #return isset($_SESSION['loggedin']);
    }


    /***********************
     * abfragende Adresse
     */
    public static function remote_addr(): string
    {
        return $_SERVER['REMOTE_ADDR'];
    }


    /***********************
     * Returns a random string
     */
    public static function random_string(): string
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);
            $str = bin2hex($bytes);
        } else {
            //Replace 'your_secret_string' with a string of your choice (>12 characters) ... Die_Sonne_Der_Mond_Hurz
            $str = md5(uniqid('M;0bAP&2hsS(8nxJS5~S6=kuC', true));
            echo '--> auth.functions.inc.php -> func -> random_string() -> (Zeile 353)';
        }
        return $str;
    }

    function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }
}

