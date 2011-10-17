<?php
/**
 * ------------------------------------------------
 * Encrypt PHP session data using files
 * ------------------------------------------------
 * The encryption is built using mcrypt extension 
 * and the randomness is managed by openssl
 * The default encryption algorithm is Rijndael-256
 * and we use CBC+HMAC (Encrypt-then-mac)
 * 
 * @author Enrico Zimuel (enrico@zimuel.it)
 * @copyright GNU General Public License
 */
class SecureSession {
    /**
     * Encryption algorithm
     * 
     * @var string
     */
    protected $_algo= MCRYPT_RIJNDAEL_256;    
    /**
     * Key for encryption/decryption
    * 
    * @var string
    */
    protected $_encKey;
    /**
     * Key for HMAC authentication
    * 
    * @var string
    */
    protected $_authKey;
    /**
     * Path of the session file
     *
     * @var string
     */
    protected $_path;
    /**
     * Session name (optional)
     * 
     * @var string
     */
    protected $_name;
    /**
     * Size of the IV vector for encryption
     * 
     * @var integer
     */
    protected $_ivSize;
    /**
     * Cookie variable name of the encryption key
     * 
     * @var string
     */
    protected $_encKeyName;
    /**
     * Cookie variable name of the HMAC key
     * 
     * @var string
     */
    protected $_authKeyName;
    /**
     * Generate a random key
     * fallback to mt_rand if PHP < 5.3 or no openssl available
     * 
     * @param integer $length
     * @return string
     */
    protected function _randomKey($length=32) {
        if(function_exists('openssl_random_pseudo_bytes')) {
            $rnd = openssl_random_pseudo_bytes($length, $strong);
            if ($strong === TRUE) { 
                return $rnd;
            }    
        }
        for ($i=0;$i<$length;$i++) {
            $sha= sha1(mt_rand());
            $char= mt_rand(0,30);
            $rnd.= chr(hexdec($sha[$char] . $sha[$char+1]));
        }	
        return $rnd;
    }
    /**
     * Constructor
     */
    public function __construct()
    {
        session_set_save_handler(
            array($this, "open"),
            array($this, "close"),
            array($this, "read"),
            array($this, "write"),
            array($this, "destroy"),
            array($this, "gc")
        );
    }
    /**
     * Open the session
     * 
     * @param string $save_path
     * @param string $session_name
     * @return bool
     */
    public function open($save_path, $session_name) 
    {
        $this->_path = $save_path.'/';	
	$this->_name = $session_name;
	$this->_encKeyName = "KEY_$session_name";
        $this->_authKeyName = "AUTH_$session_name";
	$this->_ivSize = mcrypt_get_iv_size($this->_algo, MCRYPT_MODE_CBC);
		
	if (empty($_COOKIE[$this->_encKeyName])) {
            $keyLength = mcrypt_get_key_size($this->_algo, MCRYPT_MODE_CBC);
            $this->_encKey = self::_randomKey($keyLength);
            $this->_authKey = self::_randomKey(32);
            $cookie_param = session_get_cookie_params();
            setcookie(
                $this->_encKeyName,
                base64_encode($this->_encKey),
                $cookie_param['lifetime'],
                $cookie_param['path'],
                $cookie_param['domain'],
                $cookie_param['secure'],
                $cookie_param['httponly']
            );
            setcookie(
                $this->_authKeyName,
                base64_encode($this->_authKey),
                $cookie_param['lifetime'],
                $cookie_param['path'],
                $cookie_param['domain'],
                $cookie_param['secure'],
                $cookie_param['httponly']
            );
	} else {
            $this->_encKey  = base64_decode($_COOKIE[$this->_encKeyName]);
            $this->_authKey = base64_decode($_COOKIE[$this->_authKeyName]);
	} 
	return true;
    }
    /**
     * Close the session
     * 
     * @return bool
     */
    public function close() 
    {
        return true;
    }
    /**
     * Read and decrypt the session
     * 
     * @param integer $id
     * @return string 
     */
    public function read($id) 
    {
        $sess_file = $this->_path.$this->_name."_$id";
        if (!file_exists($sess_file)) {
            return false;
        }    
  	$data = file_get_contents($sess_file);
        list($hmac, $iv, $encrypted)= explode(':',$data);
        $iv = base64_decode($iv);
        $encrypted = base64_decode($encrypted);
        $newHmac= hash_hmac('sha256', $iv . $this->_algo . $encrypted, $this->_authKey);
        if ($hmac!==$newHmac) {
            return false;
        }
  	$decrypt = mcrypt_decrypt(
            $this->_algo,
            $this->_encKey,
            $encrypted,
            MCRYPT_MODE_CBC,
            $iv
        );
        return rtrim($decrypt, "\0"); 
    }
    /**
     * Encrypt and write the session
     * 
     * @param integer $id
     * @param string $data
     * @return bool
     */
    public function write($id, $data) 
    {
        $sess_file = $this->_path . $this->_name . "_$id";
	$iv = mcrypt_create_iv($this->_ivSize, MCRYPT_RAND);
        $encrypted = mcrypt_encrypt(
            $this->_algo,
            $this->_encKey,
            $data,
            MCRYPT_MODE_CBC,
            $iv
        );
        $hmac= hash_hmac('sha256', $iv . $this->_algo . $encrypted, $this->_authKey);
        $bytes= file_put_contents($sess_file, $hmac . ':' . base64_encode($iv) . ':' . base64_encode($encrypted));
        return ($bytes!==false);  
    }
    /**
     * Destoroy the session
     * 
     * @param int $id
     * @return bool
     */
    public function destroy($id) 
    {
        $sess_file = $this->_path . $this->_name . "_$id";
        setcookie ($this->_encKeyName, '', time() - 3600);
        setcookie ($this->_authKeyName, '', time() - 3600);
	return(@unlink($sess_file));
    }
    /**
     * Garbage Collector
     * 
     * @param int $max 
     * @return bool
     */
    public function gc($max) 
    {
    	foreach (glob($this->_path . $this->_name . '_*') as $filename) {
            if (filemtime($filename) + $max < time()) {
                @unlink($filename);
            }
  	}
  	return true;
    }
}

new SecureSession();