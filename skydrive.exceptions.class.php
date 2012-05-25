<?php 
/**
 * Skydrive API Exceptions Class
 * @version 1.0.0
 * @author Pedro Piedade
 */


/**
 * Generic Exception class
 */
class SkydriveException extends Exception {

}

/**
 * Will be thrown if cURL is not available or on other errors with cURL
 */
class SkydriveException_Curl extends SkydriveException {
	
}

/**
 * Will be thrown if the the response is invalid
 */
class SkydriveException_InvalidResponse extends SkydriveException {
	
}

/**
 * Will be thrown if the Access Token expired
 */
class SkydriveException_InvalidToken extends SkydriveException {
	
}

/**
 * Will be thrown if the folder already exists
 */
class SkydriveException_FolderError extends SkydriveException {

}

/**
 * Will be thrown if the scope is invalid 
 */
class SkydriveException_InvalidScope extends SkydriveException {

}