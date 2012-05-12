<?php
/**
*
* @package Toolio - Config ACP Module
* @copyright (c) 2012 nickvergessen - http://www.flying-bits.org/
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

interface phpbb_ext_nickvergessen_toolio_acp_config_interface
{
	/**
	* Create function for the ACP module
	*
	* @param	int		$id		The ID of the module
	* @param	string	$mode	The name of the mode we want to display
	* @return	void
	*/
	public function main($id, $mode);

	/**
	* This function is called, when the main() function is called.
	* It can be used to add language files, check for a valid mode, unset config options and more.
	*
	* @param	int		$id		The ID of the module
	* @param	string	$mode	The name of the mode we want to display
	* @return	void
	*/
	public function init($id, $mode);

	/**
	* Overwrite the name of a config.
	*
	* @param	string	$config_name	The name of the config
	* @return	string
	*/
	public function config_key2name($config_name);

	/**
	* You can manipulate the value of the config before it's updated
	*
	* @param	string	$config_name	The name of the config
	* @param	mixed	$config_value	The validated value of the config (see 'validate' attribute on display_data array)
	* @param	array	$display_data	The array from this config in the display_vars array
	* @return	mixed		If the return value is null, the value will not be updated anymore, otherwise the value should be returned
	*/
	public function check_config($config_name, $config_value, $display_data);

	/**
	* You can manipulate the value of the config before it's updated
	*
	* @param	string	$config_name	The name of the config
	* @param	mixed	$config_value	The value of the config
	* @return	void
	*/
	public function update_config($config_name, $config_value);

	/**
	* You should clear your cache of the config in this function.
	* Afterwards a trigger_error with a backlink should be called.
	*
	* @return	void
	*/
	public function submit_finished();

	/**
	* Returns an array with the current value of the configs
	*
	* @return	array		An array of "name => value" tupels
	*/
	public function get_config_array();

	/**
	* Returns an array with the display_var array for the given mode
	* The returned display must have the two keys title and vars
	*		@key	string	title		The page title or lang key for the page title
	*		@key	array	vars		An array of tupels, one foreach config option we display:
	*					@key		The name of the config in the get_config_array() array.
	*								If the key starts with 'legend' a new box is opened with the value being the title of this box.
	*					@value		An array with several options:
	*						@key lang		Description for the config value (can be a language key)
	*						@key explain	Boolean whether the config has an explanation of not.
	*										If true, <lang>_EXP (and <lang>_EXPLAIN) is displayed as explanation
	*						@key validate	The config value can be validated as bool, int or string.
	*										Additional a min and max value can be specified for integers
	*										On strings the min and max value are the length of the string
	*										If your config value shall not be casted, remove the validate-key.
	*						@key type		The type of the config option:
	*										- Radio buttons:		Either with "Yes and No" (radio:yes_no) or "Enabled and Disabled" (radio:enabled_disabled) as description
	*										- Text/password field:	"text:<field-size>:<text-max-length>" and "password:<field-size>:<text-max-length>"
	*										- Select:				"select" requires the key "function" or "method" to be set which provides the html code for the options
	*										- Custom template:		"custom" requires the key "function" or "method" to be set which provides the html code
	*						@key function/method	Required when using type select and custom
	*						@key append		A language string that is appended after the config type (e.g. You can append 'px' to a pixel size field)
	* This last parameter is optional
	*		@key	string	tpl			Name of the template file we use to display the configs
	*
	* @param	string	$mode	The name of the mode we want to display
	* @return	array		See description above
	*/
	//public function get_display_vars($mode);
}
