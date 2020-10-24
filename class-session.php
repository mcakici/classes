<?php /* MEMORY storage engine table max size = "max_heap_table_size" default 16mb approx 7-10k records */
/* Last modified at 23-08-2020 - MÇ - Secure php session handler */
class Session {
    # SETTINGS
    private $sessionCookieName = "uSess";
    private $startAutomatically = false; // true = start session for each visitor, false = only triggers when you use set function (after a success login)

    # VARS
    private $db;
    private $ip;
    private $ua;
    private $requestURI;
    private $userid = null;

    function __construct() {
        ini_set('session.cookie_httponly', 1); //force to use SESSION cookie httponly (disable javascript access to cookie)
        ini_set('session.use_strict_mode', 1); //will reject users custom session ids if they change it
        session_name($this->sessionCookieName);
        session_set_save_handler(
            array($this, "_open"),
            array($this, "_close"),
            array($this, "_read"),
            array($this, "_write"),
            array($this, "_destroy"),
            array($this, "_gc"),
            array($this, "_createSID"), //PHP 5.5.1+
            array($this, "_isSessionTokenValid") //PHP 7+
        );
        if (isset($_COOKIE[$this->sessionCookieName]) || $this->startAutomatically) {
            $this->startSession();
        }
        $this->requestURI = ($_SERVER["REQUEST_URI"] ? $_SERVER["REQUEST_URI"] : '/');
    }

    public function _open($sess_path, $sess_name) {
        //$this->lifeTime = get_cfg_var("session.gc_maxlifetime");
        // DB connection settings (use different db user with delete privilege for more precaution)
        $this->db = new mysqli("localhost", "root", "", "test");
        /* check connection */
        if ($this->db->connect_errno) {
            printf("Connect failed: %s\n", $this->db->connect_error);
            exit();
        }

        $this->db->set_charset("utf8");
        $this->ip = $_SERVER['REMOTE_ADDR'] ? $_SERVER['REMOTE_ADDR'] : getenv('REMOTE_ADDR');
        $this->ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

        return true;
    }

    public function _close() {
        $this->_gc(ini_get('session.gc_maxlifetime'));
        return $this->db->close();
    }

    public function _read($client_hash) {
        $sess_id = $this->_getSessionId($client_hash);

        $query = $this->db->prepare("SELECT sessdata FROM sessions WHERE sessid=? LIMIT 1");
        $query->bind_param("s", $sess_id);
        $query->execute();
        $result = $query->get_result();
        $query->close();

        if ($result->num_rows) {
            $sessData = $result->fetch_assoc()["sessdata"];
            $unserializedSessData = ($sessData ? $this->unserialize_session($sessData) : null);
            $this->userid = (isset($unserializedSessData["uid"]) ? $unserializedSessData["uid"] : null);
        }
        return (isset($sessData) ? $sessData : '');
    }

    public function _write($client_hash, $sess_data) {
        $time = time();
        $sess_id = $this->_getSessionId($client_hash);
        $isbot = $this->isBot();

        $query = $this->db->prepare("REPLACE INTO sessions SET sessid = ?, lastactivity = ?, sessdata = ?, useragent = ?, host = ?, location = ?, isbot = ?, userid = ?");
        $query->bind_param("sdssssdd", $sess_id, $time, $sess_data, $this->ua, $this->ip, $this->requestURI, $isbot, $this->userid);
        $qStatus = $query->execute();
        $query->close();

        return ($qStatus ? true : false);
    }

    public function _destroy($sess_id, $oldsessid = null) {
        if ($oldsessid !== null) {
            $sess_id = $oldsessid;
        } else {
            $sess_id = $this->_getSessionId($sess_id);
        }

        $query = $this->db->prepare("DELETE FROM sessions WHERE sessid = ?");
        $query->bind_param("s", $sess_id);
        $qStatus = $query->execute();
        $query->close();

        if (!$this->startAutomatically) {
            @setcookie($this->sessionCookieName, null, -1, '/');
        }

        return ($qStatus ? true : false);
    }

    public function _gc($sess_maxlifetime) {
        //garbage collection
        $old = time() - $sess_maxlifetime;
        $query = $this->db->query("DELETE FROM sessions WHERE lastactivity < " . intval($old));
        return ($query ? true : false);
    }

    public function _createSID() {
        $newSessID = md5(uniqid(microtime(), true));
        return $newSessID;
    }

    private function _getSessionId($sess_id) {
        /* for token key, hashing ip and useragent not enough for full security (vulnerable from LAN, spoofing) */
        return hash_hmac("md5", $sess_id, $sess_id . $this->ip . $this->ua);
    }

    private function _isSessionTokenValid($sess_id) {
        return preg_match('/^([-,a-zA-Z0-9]{32}|[-,a-zA-Z0-9]{32}_[0-9]{1,10})$/', $sess_id) > 0;
    }

    private function isBot() {
        return (isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/bot|crawl|slurp|spider|mediapartners/i', $_SERVER['HTTP_USER_AGENT']) ? 1 : 0);
    }

    private function startSession() {
        //check if session already started
        if (session_status() !== 2) {
            session_start();
        }
    }

    private function unserialize_session($session_data, $start_index = 0, &$arr = null) {
        isset($arr) || $arr = array();
        $name_end = strpos($session_data, "|", $start_index);
        if ($name_end !== false) {
            $name = substr($session_data, $start_index, $name_end - $start_index);
            $rest = substr($session_data, $name_end + 1);

            $value = unserialize($rest);
            $arr[$name] = $value;
            return $this->unserialize_session($session_data, $name_end + 1 + strlen(serialize($value)), $arr);
        }
        return $arr;
    }

    public function destroyUserSession($userid) {
        if (!$userid) {
            return false;
        }
        if($this->db === null){
            $this->_open(null,null);
        }

        $query = $this->db->prepare("DELETE FROM sessions WHERE userid = ?");
        $query->bind_param("d", $userid);
        $qStatus = $query->execute();
        $query->close();

        if($this->db === null){
            $this->_close();
        }

        return $qStatus ? true : false;
    }

    public function get($var) {
        return isset($_SESSION[$var]) ? $_SESSION[$var] : false;
    }

    public function set($var, $value) {
        $this->startSession();
        if ($var === "uid") {
            $this->userid = $value;
        }
        $_SESSION[$var] = $value;
        return $_SESSION[$var];
    }
}