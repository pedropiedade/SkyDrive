<?php 
/**
 * Skydrive API Class
 * Still in early stages of development - needs many more functionalities
 * Right now the class has methods for authentication, getting user permissions and quota, listing folders/files, creating folders  
 * @version 0.5.0
 * @author Pedro Piedade
 */
require_once SKYDRIVE_PATH.DS.'skydrive.exceptions.class.php';

/*
 * Define your API settings here or copy this to your config/init file.
 * 
 * Callback URL
 * defined('SKYDRIVE_URL') ? null : define('SKYDRIVE_URL', 'http://www.foobar.baz/skydrive.php');
 * 
 * API Key
 * defined('SKYDRIVE_API_KEY') ? null : define("SKYDRIVE_API_KEY", "...");
 * 
 * API secret
 * defined('SKYDRIVE_API_SECRET') ? null : define("SKYDRIVE_API_SECRET", "...");
 * 
 */

class Skydrive
{
	/**
	 *  Authentication URL
	 */
	const authUrl = "https://login.live.com/oauth20_authorize.srf";
	/**
	 *  Token code URL
	 */
	const codeUrl = "https://login.live.com/oauth20_token.srf";
	/**
	 *  API base URL
	 */
	const baseUrl = "https://apis.live.net/v5.0/";

	/**
	 *  Access token
	 */
	protected $accessToken = null;
	/**
	 *  Refresh token
	 */
	protected $refreshToken = null;
	/**
	 *  Token expiration
	 */
	protected $tokenExpiration = null;

	/**
	 * List of available valid scopes
	 */
	private $validScopes = array(
			"wl.basic", "wl.offline_access", "wl.signin", "wl.birthday", "wl.calendars", "wl.calendars_update",
			"wl.contacts_create", "wl.contacts_calendars", "wl.contacts_photos", "wl.contacts_skydrive",
			"wl.emails", "wl.events_create", "wl.messenger", "wl.phone_numbers", "wl.photos", "wl.postal_addresses",
			"wl.share", "wl.skydrive", "wl.skydrive_update", "wl.work_profile", "wl.applications", "wl.applications_create"
	);

	/**
	 * List of default basic scopes
	 */
	private $defaultBasicScopes = array("wl.skydrive");

	/**
	 * List of default filesystem access scopes
	 */
	private $defaultFileAccessScopes = array("wl.skydrive", "wl.skydrive_update", "wl.offline_access");
	
	/**
	 * Build the authentication URL
	 * First step in authentication process: redirect the user to this URL
	 * @param array Scopes
	 * @return string The URL
	 * @throws SkydriveException When there are no valid scopes
	 */
	public function getAuthUrl($scope = array())
	{
		if(empty($scope)) {
			$scope = $this->defaultFileAccessScopes;
		}		
		$scope = array_intersect($scope, $this->validScopes);		
		if(empty($scope) === true) {
			throw new SkydriveException_InvalidScope();
		}
		$scopeList = implode("%20", $scope);
		$url = self::authUrl . "?client_id=" . SKYDRIVE_API_KEY . "&scope=" . $scopeList . "&response_type=code&redirect_uri=" . rawurlencode(SKYDRIVE_URL);
		return $url;
	}

	/**
	 * Get the access token (and refresh token if available)
	 * Second step in the authentication process: after the user authorizes access on step 1, get the access (and optional refresh) tokens and token expiration.
	 * These tokens should be kept (eg: on a database) for future use.
	 * The code parameter is sent (with GET) from  the proccess started in step 1, after redirection from the skydrive website.
	 * If this is a call for access token refreshing, the refresh code should be stored from a previous call to this method. 
	 * @param string $code Code or refresh token code.  
	 * @param boolean Is it a refresh token?
	 * @return array Tokens
	 * @throws SkydriveException On an invalid response
	 */
	public function getTokens($code, $refresh = false) {

		$return = array();
		$url = self::codeUrl;
		$postData = "client_id=" . SKYDRIVE_API_KEY . "&redirect_uri=" . rawurlencode(SKYDRIVE_URL) . "&client_secret=" . SKYDRIVE_API_SECRET;
		$headers = array("Content-Type: application/x-www-form-urlencoded");
		if($refresh === false) {
			$postData .= "&code=" . $code . "&grant_type=authorization_code";
		}
		else {
			$postData .= "&refresh_token=" . $code . "&grant_type=refresh_token";
		}
		$curlOptions = array("type"=>"POST", "postdata"=>$postData, "headers"=>$headers);
		$result = $this->fetch($url, $curlOptions);		
		$auth = json_decode($result, true);
		if(!is_array($auth)) {
			throw new SkydriveException_InvalidResponse();
		}
		
		if(array_key_exists("access_token", $auth)) {
			$this->accessToken = $return["access_token"] = $auth["access_token"];
		}
		if(array_key_exists("refresh_token", $auth)) {
			$this->refreshToken = $return["refresh_token"] = $auth["refresh_token"];
		}
		if(array_key_exists("expires_in", $auth)) {
			$this->tokenExpiration = $return["token_expiration"] = $auth["expires_in"];
		}
		return $return;
	}

	/**
	 * Get the current user permissions
	 * @return string
	 * @throws SkydriveException
	 */
	public function getPermissions()
	{
		if($this->accessToken === null) {
			throw new SkydriveException_InvalidToken();
		}
		$url = self::baseUrl . "permissions?access_token=" . $this->accessToken;
		$result = $this->fetch($url);
		return $result;
	}

	/**
	 * Get the current user quota (total and used)
	 * @return array With "total" and "used" keys
	 * @throws SkydriveException
	 */
	public function getQuota()
	{
		if($this->accessToken === null) {
			throw new SkydriveException_InvalidToken();
		}
		$url = self::baseUrl . "me/skydrive/quota?access_token=" . $this->accessToken;
		$info = json_decode($this->fetch($url), true);
		$total = (float)$info["quota"];
		$available = (float)$info["available"];
		$used = $total - $available;
		$quota = array("total"=>$total, "used"=>$used);
		return $quota;
	}
	
	/**
	 * Gets a folder list for a specified parent folder
	 * @param string $parent Parent folder, default is root folder
	 * @return string JSON format list
	 * @throws SkydriveException
	 */
	public function getFolderList($parent = "") {
		if($this->accessToken === null) {
			throw new SkydriveException_InvalidToken();
		}
		$url = ($parent == "" ? "me/skydrive/" : $parent);
		$url = self::baseUrl . $url . "?access_token=" . $this->accessToken;
		$result = $this->fetch($url);
		return $result;
	}
	
	/**
	 * Gets a file list for a specified folder
	 * @param string $parent Parent folder, default is root folder
	 * @return string JSON format list
	 * @throws SkydriveException
	 */
	public function getFileList($parent = "") {
		if($this->accessToken === null) {
			throw new SkydriveException_InvalidToken();
		}
		$url = ($parent == "" ? "me/skydrive/files" : $parent . "/files");
		$url = self::baseUrl . $url . "?access_token=" . $this->accessToken;
		$result = $this->fetch($url);
		return $result;
	}

	/**
	 * Creates a new folder
	 * @param array $data Associative array with "name" and "description" for the new folder
	 * @param string $parent Parent folder name, default is root folder
	 * @throws SkydriveException_ParensFolderDoesNotExist
	 */
	public function createFolder($data, $parent = "") {
		if($this->accessToken === null) {
			throw new SkydriveException_InvalidToken();
		}
		if(!is_array($data)) {
			return false;
		}
		if($parent != "") {
			$result = $this->checkFolderExists($parent);
			if($result === false) {
				throw new SkydriveException_FolderError("Invalid parent folder");
			}
		}

		$folderId = $this->checkFolderExists($data["name"], $parent);
		if($folderId !== false) {
			// If the folder already exists then return it's ID
			return $folderId;
		}

		$headers = array("Content-Type: application/json", "Authorization: Bearer " . $this->accessToken);
		$postData = json_encode($data);
		$url = self::baseUrl . "me/skydrive/" . $parent;

		$curlOptions = array("type"=>"POST", "postdata"=>$postData, "headers"=>$headers);
		$result = $this->fetch($url, $curlOptions);
		$result = json_decode($result);

		if(is_array($result) && array_key_exists("data", $result) && is_array($result["data"]) && array_key_exists("id", $result["data"])) {
			$return =  $result["data"]["id"];
			return $return;
		}
		return false;
	}

	/**
	 * Checks if a folder exists
	 * @param array $name Folder name
	 * @param string $parent Parent folder name
	 */
	public function checkFolderExists($name, $parent = null)
	{
		if($this->accessToken === null) {
			throw new SkydriveException_InvalidToken();
		}
		$result = json_decode($this->getFileList($parent), true);
		if(!is_array($result)) {
			throw new SkydriveException_InvalidToken();
		}
		if(array_key_exists("error", $result)) {
			if($result["error"]["code"] == "request_token_expired") {
				throw new SkydriveException_InvalidToken();
			}
		}
		$folders = array_values($result["data"]);
		foreach($folders as $item) {
			if($item["name"] == $name) {
				return $item["id"];
			}
		}
		return false;
	}


	/**
	 * cURL fetch - for accessing the REST API
	 * @param string $url URL
	 * @param string $options Array with options
	 * @throws SkydriveException_Curl
	 * @return mixed
	 */
	private function fetch($url, $options = array()) {
		
		if(function_exists('curl_init') === false) {
			throw new SkydriveException_Curl("cURL is not installed.");
		}
		if(is_array($options) === false) {
			throw new SkydriveException_Curl("Invalid cURL options.");
		}
		foreach ($options as $key => $value) {
			$$key = $value;
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		if(!isset($ssl) || (isset($ssl) && $ssl === false)) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		}
		
		if(isset($headers)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		if(isset($type)) {
			if($type == "POST") {
				curl_setopt($ch, CURLOPT_POST, true);
			}
		}
		if(isset($postdata)) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
		}
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}
}
