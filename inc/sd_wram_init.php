<?php

class SD_WRAM_INIT
{
	
	function __construct(){}

	private static function activationActions(){
		// Actions are executed when the plugin is activated
	}

	private static function deactivationActions(){
		// Actions are executed when the plugin is deactivated
	}

	public static function Activate(){
		self::activationActions();	
		flush_rewrite_rules();
	}

	public static function Deactivate(){
		self::deactivationActions();
		flush_rewrite_rules();
	}
	
}

?>