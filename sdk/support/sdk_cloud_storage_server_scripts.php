<?php
	// Cloud Storage Server scripts SDK class.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	// Load dependency.
	if (!class_exists("CloudStorageServer_APIBase", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/sdk_cloud_storage_server_api_base.php";

	// This class only supports the /scripts API.
	class CloudStorageServerScripts
	{
		public function __construct()
		{
			parent::__contstruct();

			$this->apiprefix = "/scripts/v1";
		}

		public function RunScript($name, $args = array(), $stdin = "", $queue = false)
		{
			$options = array(
				"name" => $name,
				"args" => $args,
				"stdin" => $stdin
			);

			if ($queue !== false)  $options["queue"] = (int)$queue;

			return $this->RunAPI("POST", "run", $options);
		}

		public function CancelScript($id)
		{
			return $this->RunAPI("POST", "cancel/" . $id);
		}

		public function GetStatus($id = false)
		{
			return $this->RunAPI("GET", "status" . ($id !== false ? "/" . $id : ""));
		}

		public function StartMonitoring($name)
		{
			$result = $this->InitWebSocket();
			if (!$result["success"])  return $result;

			$options = array(
				"api_method" => "GET",
				"api_path" => $this->apiprefix . "/monitor",
				"api_sequence" => 1,
				"name" => $name
			);

			$result2 = $result["ws"]->Write(json_encode($options), WebSocket::FRAMETYPE_TEXT);
			if (!$result2["success"])  return $result2;

			$result["api_sequence"] = 1;

			return $result;
		}

		public function CreateGuest($name, $run, $cancel, $status, $monitor, $expires)
		{
			$options = array(
				"name" => $name,
				"run" => (int)(bool)$run,
				"cancel" => (int)(bool)$cancel,
				"status" => (int)(bool)$status,
				"monitor" => (int)(bool)$monitor,
				"expires" => (int)$expires
			);

			return $this->RunAPI("POST", "guest/create", $options);
		}

		public function GetGuestList()
		{
			return $this->RunAPI("GET", "guest/list");
		}

		public function DeleteGuest($id)
		{
			return $this->RunAPI("DELETE", "guest/delete/" . $id);
		}
	}
?>