<?php
	// Cloud Storage Server scripts extension.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	class CSS_Extension_scripts
	{
		private $baseenv, $exectabs, $exectabsts, $runqueue, $running, $idmap, $monitors, $usercache, $groupcache;

		public function Install()
		{
			global $rootpath;

			@mkdir($rootpath . "/user_init/scripts", 0770, true);
		}

		public function AddUserExtension($userrow)
		{
			echo "[Scripts Ext] Allow guest creation/deletion (Y/N):  ";
			$guests = (substr(strtoupper(trim(fgets(STDIN))), 0, 1) == "Y");

			return array("success" => true, "info" => array("guests" => $guests));
		}

		public function RegisterHandlers($em)
		{
		}

		public function InitServer()
		{
			$ignore = array(
				"PHP_SELF" => true,
				"SCRIPT_NAME" => true,
				"SCRIPT_FILENAME" => true,
				"PATH_TRANSLATED" => true,
				"DOCUMENT_ROOT" => true,
				"REQUEST_TIME_FLOAT" => true,
				"REQUEST_TIME" => true,
				"argv" => true,
				"argc" => true,
			);

			$this->baseenv = array();
			foreach ($_SERVER as $key => $val)
			{
				if (!isset($ignore[$key]) && is_string($val))  $this->baseenv[$key] = $val;
			}

			$this->exectabs = array();
			$this->exectabsts = array();
			$this->runqueue = array();
			$this->running = array();
			$this->idmap = array();
			$this->monitors = array();
			$this->usercache = array();
			$this->groupcache = array();
		}

		private function GetUserInfoByID($uid)
		{
			if (!function_exists("posix_getpwuid"))  return false;

			if (!isset($this->usercache[$uid]))
			{
				$user = @posix_getpwuid($uid);
				if ($user === false || !is_array($user))  $this->usercache[$uid] = false;
				else
				{
					$this->usercache[$uid] = $user;
					$this->usercache["_" . $user["name"]] = $user;
				}
			}

			return $this->usercache[$uid];
		}

		private function GetUserInfoByName($name)
		{
			if (!function_exists("posix_getpwnam"))  return false;

			if (!isset($this->usercache["_" . $name]))
			{
				$user = @posix_getpwnam($name);
				if ($user === false || !is_array($user))  $this->usercache["_" . $name] = false;
				else
				{
					$this->usercache[$user["uid"]] = $user;
					$this->usercache["_" . $name] = $user;
				}
			}

			return $this->usercache["_" . $name];
		}

		private function GetUserName($uid)
		{
			$user = $this->GetUserInfoByID($uid);

			return ($user !== false ? $user["name"] : "");
		}

		private function GetGroupInfoByID($gid)
		{
			if (!function_exists("posix_getgrgid"))  return false;

			if (!isset($this->groupcache[$gid]))
			{
				$group = @posix_getgrgid($gid);
				if ($group === false || !is_array($group))  $this->groupcache[$gid] = "";
				else
				{
					$this->groupcache[$gid] = $group;
					$this->groupcache["_" . $group["name"]] = $group;
				}
			}

			return $this->groupcache[$gid];
		}

		private function GetGroupInfoByName($name)
		{
			if (!function_exists("posix_getgrnam"))  return false;

			if (!isset($this->groupcache["_" . $name]))
			{
				$group = @posix_getgrnam($name);
				if ($group === false || !is_array($group))  $this->groupcache["_" . $name] = "";
				else
				{
					$this->groupcache[$group["gid"]] = $group;
					$this->groupcache["_" . $name] = $group;
				}
			}

			return $this->groupcache["_" . $name];
		}

		private function GetGroupName($gid)
		{
			$group = $this->GetGroupInfoByID($gid);

			return ($group !== false ? $group["name"] : "");
		}

		private function GetQueuedStatusResult($id, $name, &$info)
		{
			return array("success" => true, "id" => $id, "name" => $name, "state" => "queued", "queued" => $info["queued"], "position" => $info["queuepos"]);
		}

		private function GetRunningStatusResult($id, $name, &$info)
		{
			return array("success" => true, "id" => $id, "name" => $name, "state" => "running", "task" => $info["task"], "tasknum" => $info["tasknum"], "maxtasks" => $info["maxtasks"], "taskstart" => $info["taskstart"], "subtask" => $info["subtask"], "subtaskpercent" => $info["subtaskpercent"]);
		}

		private function GetFinalStatusResult($row)
		{
			$info = @json_decode($row->info, true);

			return array("success" => true, "id" => $row->id, "name" => $row->script, "state" => ($row->finished > 0 ? "done" : "incomplete_log"), "started" => (double)$row->started, "finished" => (double)$row->finished, "args" => $info["args"], "first" => (!count($info["tasks"]) ? $info["first"] : ""), "last" => (!count($info["tasks"]) ? $info["last"] : ""), "tasks" => $info["tasks"], "removelog" => $info["args"]["removelog"]);
		}

		private function HasMonitor($uid, $name)
		{
			return (isset($this->monitors[$uid]) && (isset($this->monitors[$uid][$name]) || isset($this->monitors[$uid][""])));
		}

		private function NotifyMonitors($uid, $name, $result)
		{
			global $wsserver;

			if (isset($this->monitors[$uid]) && isset($this->monitors[$uid][$name]))
			{
				foreach ($this->monitors[$uid][$name] as $wsid => $api_sequence)
				{
					$client = $wsserver->GetClient($wsid);
					if ($client === false)
					{
						unset($this->monitors[$uid][$name][$wsid]);
						if (!count($this->monitors[$uid][$name]))
						{
							unset($this->monitors[$uid][$name]);
							if (!count($this->monitors[$uid]))  unset($this->monitors[$uid]);
						}
					}
					else
					{
						$result["api_sequence"] = $api_sequence;

						$client->websocket->Write(json_encode($result), WebSocket::FRAMETYPE_TEXT);
					}
				}
			}
		}

		private function FinalizeProcess($uid, $name, $id, $db, &$info)
		{
			if ($info["proc"] !== false)
			{
				foreach ($info["pipes"] as $fp)  fclose($fp);

				proc_close($info["proc"]);
			}

			@unlink($info["basedir"] . "/status/" . $id . ".json");

			if ($db !== false && $this->HasMonitor($uid, $name))
			{
				try
				{
					$row = $db->GetRow("SELECT", array(
						"*",
						"FROM" => "?",
						"WHERE" => "id = ?",
					), "log", $id);

					if (!$row)
					{
						CSS_DisplayError("Unable to locate a log entry with the ID '" . $id . "' for user '" . $uid . "'.", false, false);

						return;
					}

					$result = $this->GetFinalStatusResult($row);
					$result["name"] = $name;

					$this->NotifyMonitors($uid, $name, $result);
					$this->NotifyMonitors($uid, "", $result);

					if ($result["removelog"])  $db->Query("DELETE", array("log", "WHERE" => "id = ?"), array($id));
				}
				catch (Exception $e)
				{
					CSS_DisplayError("A database query failed while retrieving ID '" . $id . "' for user '" . $uid . "'.", false, false);
				}
			}

			unset($this->idmap[$uid][$id]);
			if (!count($this->idmap[$uid]))  unset($this->idmap[$uid]);
		}

		private function ProcessStartFailed($info, $msg, $db, $uid, $id)
		{
			$info["loginfo"]["first"] = "[ERROR] " . $msg;
			$info["loginfo"]["last"] = "[ERROR] " . $msg;

			if ($db !== false)
			{
				try
				{
					$db->Query("UPDATE", array("log", array(
						"finished" => microtime(true),
						"info" => json_encode($info["loginfo"], JSON_UNESCAPED_SLASHES)
					), "WHERE" => "id = ?"), $id);
				}
				catch (Exception $e)
				{
					CSS_DisplayError("A database query failed while updating the process log.", false, false);
				}
			}

			unset($this->idmap[$uid][$id]);
			if (!count($this->idmap[$uid]))  unset($this->idmap[$uid]);
		}

		private function RemoveRunQueueEntry($uid, $name, $id)
		{
			unset($this->runqueue[$uid][$name][$id]);
			if (!count($this->runqueue[$uid][$name]))
			{
				unset($this->runqueue[$uid][$name]);
				if (!count($this->runqueue[$uid]))  unset($this->runqueue[$uid]);
			}
			else
			{
				$num = 0;
				foreach ($this->runqueue[$uid][$name] as $id2 => $info2)
				{
					$this->runqueue[$uid][$name][$id2]["queuepos"] = $num;
					$info2["queuepos"] = $num;

					@file_put_contents($info2["basedir"] . "/status/" . $id2 . ".json", json_encode($this->GetQueuedStatusResult($id2, $name, $info2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

					$num++;
				}
			}
		}

		private function UpdateRunningScripts()
		{
			global $rootpath;

			// Process running scripts first.
			foreach ($this->running as $uid => $names)
			{
				foreach ($names as $name => $idsinfo)
				{
					foreach ($idsinfo as $id => $info)
					{
						// Process stdin.
						if (isset($info["pipes"][0]))
						{
							if ($info["stdin"] !== "")
							{
								$result = @fwrite($info["pipes"][0], $info["stdin"]);
								if ($result === false)
								{
									@fclose($info["pipes"][0]);
									unset($info["pipes"][0]);

									$this->running[$uid][$name][$id]["stdin"] = "";
								}
								else if ($result > 0)
								{
									$this->running[$uid][$name][$id]["stdin"] = (string)substr($info["stdin"], $result);
								}
							}
							else
							{
								@fclose($info["pipes"][0]);
								unset($this->running[$uid][$name][$id]["pipes"][0]);
							}
						}

						// Process stdout/stderr.
						for ($x = 1; $x < 3; $x++)
						{
							if (isset($info["pipes"][$x]))
							{
								$line = @fgets($info["pipes"][$x]);
								if ($line === false)
								{
									if (feof($info["pipes"][$x]))
									{
										@fclose($info["pipes"][$x]);
										unset($this->running[$uid][$name][$id]["pipes"][$x]);
									}
								}
								else if ($line !== "")
								{
									$line = trim($line);
									if ($info["loginfo"]["first"] === false)  $this->running[$uid][$name][$id]["loginfo"]["first"] = substr($line, 0, 10000);
									$this->running[$uid][$name][$id]["loginfo"]["last"] = substr($line, 0, 10000);

									if ($line{0} === "[")
									{
										$pos = strpos($line, "]");
										if ($pos !== false)
										{
											$task = (string)substr($line, 1, $pos - 1);
											if ($task !== "")
											{
												$line = ltrim(substr($line, $pos + 1));

												if (substr($task, -1) === "%")
												{
													// Subtask percentage complete.
													$this->running[$uid][$name][$id]["subtask"] = $line;
													$this->running[$uid][$name][$id]["subtaskpercent"] = (int)$task;
												}
												else
												{
													// Main task changed.
													$pos = strpos($task, "/");
													if ($pos !== false)
													{
														$maxtasks = (int)substr($task, $pos + 1);
														$task = substr($task, 0, $pos);
														if ($info["maxtasks"] < $maxtasks)  $this->running[$uid][$name][$id]["maxtasks"] = $maxtasks;
													}

													$tasknum = (int)$task - 1;
													if ($info["task"] === false || $info["tasknum"] < $tasknum)  $this->running[$uid][$name][$id]["taskstart"] = microtime(true);
													if ($info["tasknum"] < $tasknum)
													{
														if ($info["task"] !== false)
														{
															$this->running[$uid][$name][$id]["loginfo"]["tasks"][] = array(
																"task" => $info["task"],
																"start" => $info["taskstart"],
																"end" => microtime(true)
															);
														}

														$this->running[$uid][$name][$id]["tasknum"] = $tasknum;
														if ($this->running[$uid][$name][$id]["maxtasks"] <= $tasknum)  $this->running[$uid][$name][$id]["maxtasks"] = $tasknum + 1;
														$this->running[$uid][$name][$id]["subtask"] = false;
														$this->running[$uid][$name][$id]["subtaskpercent"] = 0;
													}

													if ($info["tasknum"] <= $tasknum)  $this->running[$uid][$name][$id]["task"] = $line;

													$info = $this->running[$uid][$name][$id];
												}

												@file_put_contents($info["basedir"] . "/status/" . $id . ".json", json_encode($this->GetRunningStatusResult($id, $name, $this->running[$uid][$name][$id]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
											}
										}
									}
								}
							}
						}

						// If all handles have been closed, assume the process has been terminated or will be very shortly and remove this process from the running queue.
						if (!count($this->running[$uid][$name][$id]["pipes"]))
						{
							if ($info["task"] !== false)
							{
								$this->running[$uid][$name][$id]["loginfo"]["tasks"][] = array(
									"task" => $info["task"],
									"start" => $info["taskstart"],
									"end" => microtime(true)
								);
							}

							// Connect to the database.
							$result = self::GetUserScriptsDB($info["basedir"]);
							$db = ($result["success"] ? $result["db"] : false);

							if ($db !== false)
							{
								try
								{
									$ts = microtime(true);

									$db->Query("UPDATE", array("log", array(
										"finished" => $ts,
										"info" => json_encode($this->running[$uid][$name][$id]["loginfo"], JSON_UNESCAPED_SLASHES)
									), array(
										"duration" => $ts . " - started"
									), "WHERE" => "id = ?"), $id);
								}
								catch (Exception $e)
								{
									CSS_DisplayError("A database query failed while updating the process log.", false, false);
								}
							}

							$this->FinalizeProcess($uid, $name, $id, $db, $this->running[$uid][$name][$id]);

							unset($this->running[$uid][$name][$id]);
							if (!count($this->running[$uid][$name]))
							{
								unset($this->running[$uid][$name]);
								if (!count($this->running[$uid]))  unset($this->running[$uid]);
							}
						}
					}
				}
			}

			// Start queued scripts up to the maximum simultaneous allowed.
			foreach ($this->runqueue as $uid => $names)
			{
				foreach ($names as $name => $idsinfo)
				{
					if (!isset($this->running[$uid]) || !isset($this->running[$uid][$name]) || !isset($this->exectabs[$uid][$name]) || count($this->running[$uid][$name]) < $this->exectabs[$uid][$name]["opts"]["simultaneous"])
					{
						foreach ($idsinfo as $id => $info)
						{
							if (isset($this->running[$uid]) && isset($this->running[$uid][$name]) && isset($this->exectabs[$uid][$name]) && count($this->running[$uid][$name]) >= $this->exectabs[$uid][$name]["opts"]["simultaneous"])  break;

							// Skip future run items.
							if ($info["queued"] > time())  break;

							// Remove this entry from the run queue.
							$this->RemoveRunQueueEntry($uid, $name, $id);

							// Connect to the database.
							$result = self::GetUserScriptsDB($info["basedir"]);
							$db = ($result["success"] ? $result["db"] : false);

							if (!count($info["args"]["params"]) || (isset($this->exectabs[$uid][$name]) && $this->exectabs[$uid][$name]["opts"]["noexec"]))
							{
								// No process.  Just finalize the log entry and notify any monitors.
								if ($db !== false)
								{
									$info["loginfo"]["first"] = "";
									$info["loginfo"]["last"] = "";

									try
									{
										$ts = microtime(true);

										$db->Query("UPDATE", array("log", array(
											"started" => $ts,
											"finished" => $ts,
											"info" => $info["loginfo"]
										), "WHERE" => "id = ?"), $id);
									}
									catch (Exception $e)
									{
										CSS_DisplayError("A database query failed while updating the process log.", false, false);
									}

									$this->FinalizeProcess($uid, $name, $id, $db, $info);
								}
							}
							else
							{
								// Set up the process environment.
								$env = $this->baseenv;
								foreach ($info["args"]["opts"]["envvar"] as $var)
								{
									$pos = strpos($var, "=");
									if ($pos !== false)
									{
										$key = substr($var, 0, $pos);
										$val = (string)substr($var, $pos + 1);

										foreach ($env as $key2 => $val2)
										{
											if (!strcasecmp($key, $key2))  $key = $key2;

											$val = str_ireplace("%" . $key2 . "%", $val2, $val);
										}

										$env[$key] = $val;
									}
								}

								// Set effective user and group.
								if (function_exists("posix_geteuid"))
								{
									$prevuid = posix_geteuid();
									$prevgid = posix_getegid();

									if (isset($info["args"]["opts"]["user"]))
									{
										$userinfo = $this->GetUserInfoByName($info["args"]["opts"]["user"]);
										if ($userinfo !== false)
										{
											posix_seteuid($userinfo["uid"]);
											posix_setegid($userinfo["gid"]);
										}
									}

									if (isset($info["args"]["opts"]["group"]))
									{
										$groupinfo = $this->GetGroupInfoByName($info["args"]["opts"]["group"]);
										if ($groupinfo !== false)  posix_setegid($groupinfo["gid"]);
									}
								}

								// Windows requires redirecting pipes through sockets so they can be configured to be non-blocking.
								$os = php_uname("s");

								if (strtoupper(substr($os, 0, 3)) == "WIN")
								{
									$serverfp = stream_socket_server("tcp://127.0.0.1:0", $errornum, $errorstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
									if ($serverfp === false)
									{
										// The TCP/IP server failed to start.
										$this->ProcessStartFailed($info, "Localhost TCP/IP server failed to start.", $db, $uid, $id);

										continue;
									}

									$serverinfo = stream_socket_get_name($serverfp, false);
									$pos = strrpos($serverinfo, ":");
									$serverip = substr($serverinfo, 0, $pos);
									$serverport = (int)substr($serverinfo, $pos + 1);

									$extraparams = array(
										escapeshellarg(str_replace("/", "\\", $rootpath . "/support/createprocess.exe")),
										"/w",
										"/socketip=127.0.0.1",
										"/socketport=" . $serverport,
										"/stdin=socket",
										"/stdout=socket",
										"/stderr=socket"
									);

									$info["args"]["params"] = array_merge($extraparams, $info["args"]["params"]);
								}

								$cmd = implode(" ", $info["args"]["params"]);
//echo $cmd . "\n";

								// Start the process.
								$procpipes = array(array("pipe", "r"), array("pipe", "w"), array("pipe", "w"));
								$proc = @proc_open($cmd, $procpipes, $pipes, (isset($info["args"]["opts"]["dir"]) ? $info["args"]["opts"]["dir"] : NULL), $env, array("suppress_errors" => true, "bypass_shell" => true));

								// Restore effective user and group.
								if (function_exists("posix_geteuid"))
								{
									posix_seteuid($prevuid);
									posix_setegid($prevgid);
								}

								if (!is_resource($proc))
								{
									// The process/shell failed to start.
									$this->ProcessStartFailed($info, "Process failed to start.", $db, $uid, $id);

									// Remove TCP/IP server on Windows.
									if (strtoupper(substr($os, 0, 3)) == "WIN")  fclose($serverfp);
								}
								else
								{
									// Rebuild the pipes on Windows by waiting for three valid inbound TCP/IP connections.
									if (strtoupper(substr($os, 0, 3)) == "WIN")
									{
										// Close the pipes created by PHP.
										foreach ($pipes as $fp)  fclose($fp);

										$pipes = array();
										while (count($pipes) < 3)
										{
											$readfps = array($serverfp);
											$writefps = array();
											$exceptfps = NULL;
											$result = @stream_select($readfps, $writefps, $exceptfps, 1);
											if ($result === false)  break;

											$info2 = @proc_get_status($proc);
											if (!$info2["running"])  break;

											if (count($readfps) && ($fp = @stream_socket_accept($serverfp)) !== false)
											{
												// Read in one byte.
												$num = ord(fread($fp, 1));

												if ($num >= 0 && $num <= 2)  $pipes[$num] = $fp;
											}
										}

										fclose($serverfp);

										if (count($pipes) < 3)
										{
											// The process/shell failed to start.
											$this->ProcessStartFailed($info, "The process started but failed to connect to the localhost TCP/IP server before terminating.", $db, $uid, $id);

											continue;
										}
									}

									// Move the process to the active running state.
									$info["proc"] = $proc;
									foreach ($pipes as $fp)  stream_set_blocking($fp, 0);
									$info["pipes"] = $pipes;

									if (!isset($this->running[$uid]))  $this->running[$uid] = array();
									if (!isset($this->running[$uid][$name]))  $this->running[$uid][$name] = array();
									$this->running[$uid][$name][$id] = $info;

									@file_put_contents($info["basedir"] . "/status/" . $id . ".json", json_encode($this->GetRunningStatusResult($id, $name, $info), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

									$this->idmap[$uid][$id]["running"] = true;

									if ($db !== false)
									{
										try
										{
											$db->Query("UPDATE", array("log", array(
												"started" => microtime(true)
											), "WHERE" => "id = ?"), $id);
										}
										catch (Exception $e)
										{
											CSS_DisplayError("A database query failed while updating the process log.", false, false);
										}

										// Notify monitors that a process started successfully.
										// If a monitor cares about task progress, then it can make status API calls.
										if ($this->HasMonitor($uid, $name))
										{
											$result = $this->GetRunningStatusResult($id, $name, $info);

											$this->NotifyMonitors($uid, $name, $result);
											$this->NotifyMonitors($uid, "", $result);
										}
									}
								}
							}
						}
					}
				}
			}
		}

		public function UpdateStreamsAndTimeout($prefix, &$timeout, &$readfps, &$writefps)
		{
			$this->UpdateRunningScripts();

			foreach ($this->running as $uid => $names)
			{
				foreach ($names as $name => $idsinfo)
				{
					foreach ($idsinfo as $id => $info)
					{
						if (isset($info["pipes"][0]))  $writefps[$prefix . "ext_scripts_" . $uid . "_" . $id . "_i"] = $info["pipes"][0];
						if (isset($info["pipes"][1]))  $readfps[$prefix . "ext_scripts_" . $uid . "_" . $id . "_o"] = $info["pipes"][1];
						if (isset($info["pipes"][2]))  $readfps[$prefix . "ext_scripts_" . $uid . "_" . $id . "_e"] = $info["pipes"][2];
					}
				}
			}
		}

		public function HTTPPreProcessAPI($pathparts, $client, $userrow, $guestrow)
		{
		}

		public static function InitUserScriptsBasePath($userrow)
		{
			$basedir = $userrow->basepath . "/" . $userrow->id . "/scripts";
			@mkdir($basedir, 0770, true);

			return $basedir;
		}

		public static function GetUserScriptsDB($basedir)
		{
			$filename = $basedir . "/main.db";

			// Only ProcessAPI() should create the database.
			if (!file_exists($filename))  return array("success" => false, "error" => "The database '" . $filename . "' does not exist.", "errorcode" => "db_not_found");

			$db = new CSDB_sqlite();

			try
			{
				$db->Connect("sqlite:" . $filename);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "The database failed to open.", "errorcode" => "db_open_error");
			}

			return array("success" => true, "db" => $db);
		}

		public function ProcessAPI($reqmethod, $pathparts, $client, $userrow, $guestrow, $data)
		{
			global $rootpath, $userhelper;

			$basedir = self::InitUserScriptsBasePath($userrow);

			$filename = $basedir . "/main.db";

			$runinit = !file_exists($filename);

			$db = new CSDB_sqlite();

			try
			{
				$db->Connect("sqlite:" . $filename);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "The database failed to open.", "errorcode" => "db_open_error");
			}

			if ($runinit)
			{
				// Create database tables.
				if (!$db->TableExists("log"))
				{
					try
					{
						$db->Query("CREATE TABLE", array("log", array(
							"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
							"script" => array("STRING", 1, 255, "NOT NULL" => true),
							"run_user" => array("STRING", 1, 255, "NOT NULL" => true),
							"run_group" => array("STRING", 1, 255, "NOT NULL" => true),
							"started" => array("FLOAT", "NOT NULL" => true),
							"duration" => array("FLOAT", "NOT NULL" => true),
							"finished" => array("FLOAT", "NOT NULL" => true),
							"info" => array("STRING", 4, "NOT NULL" => true),
						),
						array(
							array("KEY", array("script", "duration"), "NAME" => "script_duration"),
						)));
					}
					catch (Exception $e)
					{
						$db->Disconnect();
						@unlink($filename);

						return array("success" => false, "error" => "Database table creation failed.", "errorcode" => "db_table_error");
					}
				}

				// Copy staging file into directory.
				if (!file_exists($basedir . "/exectab.txt"))
				{
					$bytesdiff = 0;
					if (!file_exists($rootpath . "/user_init/scripts/exectab.txt"))  $data2 = "";
					else  $data2 = file_get_contents($rootpath . "/user_init/scripts/exectab.txt");
					$bytesdiff = strlen($data2);
					file_put_contents($basedir . "/exectab.txt", $data2);

					// Adjust total bytes stored.
					$userhelper->AdjustUserTotalBytes($userrow->id, $bytesdiff);
				}

				// Create status tracking directory for direct non-API access.
				@mkdir($basedir . "/status", 0775);
			}

			// Parse 'exectab.txt' if it has changed since the last API call.
			$filename = $basedir . "/exectab.txt";
			if (!isset($this->exectabsts[$userrow->id]))  $this->exectabsts[$userrow->id] = 0;
			if ($this->exectabsts[$userrow->id] < filemtime($filename) && filemtime($filename) < time())
			{
				require_once $rootpath . "/support/cli.php";

				$cmdopts = array(
					"shortmap" => array(
						"d" => "dir",
						"e" => "envvar",
						"g" => "group",
						"i" => "stdinallowed",
						"m" => "maxqueue",
						"n" => "noexec",
						"r" => "removelog",
						"s" => "simultaneous",
						"u" => "user"
					),
					"rules" => array(
						"dir" => array("arg" => true),
						"envvar" => array("multiple" => true, "arg" => true),
						"group" => array("arg" => true),
						"stdinallowed" => array("arg" => false),
						"maxqueue" => array("arg" => true),
						"noexec" => array("arg" => false),
						"removelog" => array("arg" => false),
						"simultaneous" => array("arg" => true),
						"user" => array("arg" => true)
					),
					"allow_opts_after_param" => false
				);

				$this->exectabs[$userrow->id] = array();
				$fp = fopen($filename, "rb");
				while (($line = fgets($fp)) !== false)
				{
					$line = trim($line);

					if ($line !== "" && $line{0} !== "#" && substr($line, 0, 2) !== "//")
					{
						$args = CLI::ParseCommandLine($cmdopts, ". " . $line);

						if (!isset($args["opts"]["removelog"]))  $args["opts"]["removelog"] = false;
						if (!isset($args["opts"]["simultaneous"]) || $args["opts"]["simultaneous"] < 1)  $args["opts"]["simultaneous"] = 1;
						if (!isset($args["opts"]["envvar"]))  $args["opts"]["envvar"] = array();

						if (count($args["params"]))
						{
							$name = array_shift($args["params"]);

							$this->exectabs[$userrow->id][$name] = $args;
						}
					}
				}
				fclose($fp);

				$this->exectabsts[$userrow->id] = filemtime($filename);

				// Cleanup status directory.
				$dir = @opendir($basedir . "/status");
				if ($dir)
				{
					while (($file = readdir($dir)) !== false)
					{
						if (substr($file, -5) === ".json")
						{
							if (!isset($this->idmap[$userrow->id]) || !isset($this->idmap[$userrow->id][substr($file, 0, -5)]))  @unlink($basedir . "/status/" . $file);
						}
					}

					closedir($dir);
				}
			}

			// Main API.
			$y = count($pathparts);
			if ($y < 4)  return array("success" => false, "error" => "Invalid API call.", "errorcode" => "invalid_api_call");

			if ($pathparts[3] === "run")
			{
				// /scripts/v1/run
				if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /scripts/v1/run", "errorcode" => "use_post_request");
				if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
				if (!isset($this->exectabs[$userrow->id][$data["name"]]))  return array("success" => false, "error" => "No script found for the given name.", "errorcode" => "invalid_name");
				if (!isset($data["args"]))  $data["args"] = array();
				if (!is_array($data["args"]))  return array("success" => false, "error" => "Invalid 'args'.  Expected an array.", "errorcode" => "invalid_args");
				if (!isset($data["stdin"]))  $data["stdin"] = "";
				if (!is_string($data["stdin"]))  return array("success" => false, "error" => "Invalid 'stdin'.  Expected a string.", "errorcode" => "invalid_stdin");
				if (!isset($data["queue"]))  $data["queue"] = time();
				if (!is_int($data["queue"]))  return array("success" => false, "error" => "Invalid 'queue'.  Expected a UNIX timestamp integer.", "errorcode" => "invalid_queue");
				if ($guestrow !== false && !$guestrow->serverexts["scripts"]["run"])  return array("success" => false, "error" => "Execute/Run access denied.", "errorcode" => "access_denied");
				if ($guestrow !== false && $guestrow->serverexts["scripts"]["name"] !== $data["name"])  return array("success" => false, "error" => "Script run access denied to the specified name.", "errorcode" => "access_denied");

				$name = $data["name"];
				$args = $this->exectabs[$userrow->id][$name];
				if (!isset($this->runqueue[$userrow->id]))  $this->runqueue[$userrow->id] = array();
				if (!isset($this->runqueue[$userrow->id][$name]))  $this->runqueue[$userrow->id][$name] = array();

				if (!isset($args["opts"]["stdinallowed"]) && $data["stdin"] !== "")  return array("success" => false, "error" => "The process does not allow 'stdin'.  Non-empty 'stdin' string encountered.", "errorcode" => "stdin_not_allowed");

				if (isset($args["opts"]["maxqueue"]) && $args["opts"]["maxqueue"] > 0)
				{
					$total = count($this->runqueue[$userrow->id][$name]);
					if (isset($this->running[$userrow->id][$name]))  $total += count($this->running[$userrow->id][$name]);

					if ($total >= $args["opts"]["maxqueue"])  return array("success" => false, "error" => "The queue is full.  Try again later.", "errorcode" => "queue_full");
				}

				// Merge arguments into parameters.
				foreach ($args["params"] as $num => $param)
				{
					$modified = false;
					while (preg_match("/@@(\d+)/", $param, $match))
					{
						$num2 = (int)$match[1];
						if (!isset($data["args"][$num2 - 1]))  return array("success" => false, "error" => "Missing an entry in 'args'.  Expected at least " . $num2 . " entries.", "errorcode" => "invalid_args");

						$param = str_replace("@@" . $num2, $data["args"][$num2 - 1], $param);

						$modified = true;
					}

					if ($modified && !$args["opts"]["noexec"])  $args["params"][$num] = escapeshellarg($param);
				}

				$info = array(
					"args" => $args,
					"first" => false,
					"last" => false,
					"tasks" => array()
				);

				try
				{
					$db->Query("INSERT", array("log", array(
						"script" => $name,
						"run_user" => (isset($args["opts"]["user"]) && $this->GetUserInfoByName($args["opts"]["user"]) !== false ? $args["opts"]["user"] : (function_exists("posix_geteuid") ? $this->GetUserName(posix_geteuid()) : "")),
						"run_group" => (isset($args["opts"]["group"]) && $this->GetGroupInfoByName($args["opts"]["group"]) !== false ? $args["opts"]["group"] : (function_exists("posix_getegid") ? $this->GetGroupName(posix_getegid()) : "")),
						"started" => 0,
						"duration" => 0,
						"finished" => 0,
						"info" => json_encode($info, JSON_UNESCAPED_SLASHES)
					), "AUTO INCREMENT" => "id"));

					$id = $db->GetInsertID();
				}
				catch (Exception $e)
				{
					return array("success" => false, "error" => "A database query failed while logging the task.", "errorcode" => "db_query_error");
				}

				// Add the process to the run queue.  Processes are started/updated during core cycles.
				if ($data["queue"] < time())  $data["queue"] = time();
				$queue = array();
				$qinfo = array(
					"id" => $id,
					"queued" => $data["queue"],
					"queuepos" => 0,
					"basedir" => $basedir,
					"args" => $args,
					"proc" => false,
					"pipes" => false,
					"stdin" => $data["stdin"],
					"task" => false,
					"tasknum" => 0,
					"maxtasks" => 1,
					"taskstart" => 0,
					"subtask" => false,
					"subtaskpercent" => 0,
					"loginfo" => $info
				);
				foreach ($this->runqueue[$userrow->id][$name] as $id2 => $info2)
				{
					if (!isset($queue[$id]) && $data["queue"] < $info2["queued"])
					{
						$qinfo["queuepos"] = count($queue);
						$queue[$id] = $qinfo;
					}

					$info2["queuepos"] = count($queue);
					$queue[$id2] = $info2;

					if (isset($queue[$id]))
					{
						$result = $this->GetQueuedStatusResult($id2, $name, $info2);

						@file_put_contents($basedir . "/status/" . $id2 . ".json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
					}
				}
				if (!isset($queue[$id]))
				{
					$qinfo["queuepos"] = count($queue);
					$queue[$id] = $qinfo;
				}
				$this->runqueue[$userrow->id][$name] = $queue;

				if (!isset($this->idmap[$userrow->id]))  $this->idmap[$userrow->id] = array();

				$this->idmap[$userrow->id][$id] = array(
					"running" => false,
					"name" => $name,
					"id" => $id
				);

				$info = $this->runqueue[$userrow->id][$name][$id];

				$result = $this->GetQueuedStatusResult($id, $name, $info);

				@file_put_contents($basedir . "/status/" . $id . ".json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

				return $result;
			}
			else if ($pathparts[3] === "cancel")
			{
				// /scripts/v1/cancel/ID
				if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /scripts/v1/cancel/ID", "errorcode" => "use_post_request");
				if ($y < 5)  return array("success" => false, "error" => "Missing script log ID for:  /scripts/v1/cancel/ID", "errorcode" => "missing_id");
				if ($guestrow !== false && !$guestrow->serverexts["scripts"]["cancel"])  return array("success" => false, "error" => "Script cancel access denied.", "errorcode" => "access_denied");

				$id = $pathparts[4];

				// If the script is queued or running, then don't access the database.
				if (!isset($this->idmap[$userrow->id]) || !isset($this->idmap[$userrow->id][$id]))  return array("success" => false, "error" => "Script not queued or running.", "errorcode" => "script_not_queued_running");

				$info = $this->idmap[$userrow->id][$id];
				$name = $info["name"];
				if ($guestrow !== false && $guestrow->serverexts["scripts"]["name"] !== $name)  return array("success" => false, "error" => "Script status access denied to the specified name.", "errorcode" => "access_denied");
				if ($info["running"])  return array("success" => false, "error" => "Script is currently running.  This API can only cancel queued scripts.", "errorcode" => "script_running");

				$info = $this->runqueue[$userrow->id][$name][$id];

				// Remove this entry from the run queue.
				$this->RemoveRunQueueEntry($userrow->id, $name, $id);

				$info["loginfo"]["first"] = "[CANCEL]";
				$info["loginfo"]["last"] = "[CANCEL]";

				try
				{
					if ($info["loginfo"]["args"]["removelog"])
					{
						$db->Query("DELETE", array("log", "WHERE" => "id = ?"), array($id));
					}
					else
					{
						$ts = microtime(true);

						$db->Query("UPDATE", array("log", array(
							"started" => $ts,
							"finished" => $ts,
							"info" => json_encode($info["loginfo"], JSON_UNESCAPED_SLASHES)
						), "WHERE" => "id = ?"), $id);
					}
				}
				catch (Exception $e)
				{
					return array("success" => false, "error" => "A database query failed while updating the process log.", "errorcode" => "db_query_error");
				}

				// Finalize the process but don't trigger any monitor notifications.
				$this->FinalizeProcess($userrow->id, $name, $id, false, $info);

				return array("success" => true);
			}
			else if ($pathparts[3] === "status")
			{
				// /scripts/v1/status/ID
				if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /scripts/v1/status/ID", "errorcode" => "use_get_request");
				if ($guestrow !== false && !$guestrow->serverexts["scripts"]["status"])  return array("success" => false, "error" => "Script status access denied.", "errorcode" => "access_denied");

				if ($y < 5)
				{
					$queued = array();
					foreach ($this->runqueue[$userrow->id] as $name => $idsinfo)  $queued[$name] = ($guestrow === false || $guestrow->serverexts["scripts"]["name"] === $name ? array_keys($idsinfo) : count($idsinfo));

					$running = array();
					foreach ($this->running[$userrow->id] as $name => $idsinfo)  $running[$name] = ($guestrow === false || $guestrow->serverexts["scripts"]["name"] === $name ? array_keys($idsinfo) : count($idsinfo));

					return array("success" => true, "queued" => (object)$queued, "running" => (object)$running);
				}
				else
				{
					$id = $pathparts[4];

					// If the script is queued or running, then don't access the database.
					if (isset($this->idmap[$userrow->id]) && isset($this->idmap[$userrow->id][$id]))
					{
						$info = $this->idmap[$userrow->id][$id];
						$name = $info["name"];
						if ($guestrow !== false && $guestrow->serverexts["scripts"]["name"] !== $name)  return array("success" => false, "error" => "Script status access denied to the specified name.", "errorcode" => "access_denied");

						if (!$info["running"])
						{
							$info = $this->runqueue[$userrow->id][$name][$id];

							return $this->GetQueuedStatusResult($id, $name, $info);
						}
						else
						{
							$info = $this->running[$userrow->id][$name][$id];

							return $this->GetRunningStatusResult($id, $name, $info);
						}
					}

					try
					{
						$row = $db->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ?",
						), "log", $id);

						if (!$row)  return array("success" => false, "error" => "Unable to locate a log entry with the specified ID.", "errorcode" => "invalid_id");

						if ($guestrow !== false && $guestrow->serverexts["scripts"]["name"] !== $row->script)  return array("success" => false, "error" => "Script status access denied to the specified name.", "errorcode" => "access_denied");
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving an ID.", "errorcode" => "db_query_error");
					}

					return $this->GetFinalStatusResult($row);
				}
			}
			else if ($pathparts[3] === "monitor")
			{
				if ($client instanceof WebServer_Client)  return array("success" => false, "error" => "WebSocket connection is required for:  /scripts/v1/monitor", "errorcode" => "use_websocket");
				if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /scripts/v1/monitor", "errorcode" => "use_get_request");
				if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
				if ($data["name"] !== "" && !isset($this->exectabs[$userrow->id][$data["name"]]))  return array("success" => false, "error" => "No script found for the given name.", "errorcode" => "invalid_name");
				if ($guestrow !== false && !$guestrow->serverexts["scripts"]["monitor"])  return array("success" => false, "error" => "Monitor access denied.", "errorcode" => "access_denied");
				if ($guestrow !== false && $guestrow->serverexts["scripts"]["name"] !== $data["name"])  return array("success" => false, "error" => "Script status access denied to the specified name.", "errorcode" => "access_denied");

				$uid = $userrow->id;
				$name = $data["name"];

				if (!isset($this->monitors[$uid]))  $this->monitors[$uid] = array();
				if (!isset($this->monitors[$uid][$name]))  $this->monitors[$uid][$name] = array();

				if (isset($this->monitors[$uid][$name][$client->id]))
				{
					unset($this->monitors[$uid][$name][$client->id]);
					if (!count($this->monitors[$uid][$name]))
					{
						unset($this->monitors[$uid][$name]);
						if (!count($this->monitors[$uid]))  unset($this->monitors[$uid]);
					}

					return array("success" => true, "name" => $name, "enabled" => false);
				}
				else
				{
					$this->monitors[$uid][$name][$client->id] = $data["api_sequence"];

					return array("success" => true, "name" => $name, "enabled" => true);
				}
			}
			else if ($pathparts[3] === "guest")
			{
				// Guest API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /scripts/v1/guest.", "errorcode" => "invalid_api_call");
				if ($guestrow !== false)  return array("success" => false, "error" => "Guest API key detected.  Access denied to /scripts/v1/guest.", "errorcode" => "access_denied");
				if (!$userrow->serverexts["scripts"]["guests"])  return array("success" => false, "error" => "Insufficient privileges.  Access denied to /scripts/v1/guest.", "errorcode" => "access_denied");

				if ($pathparts[4] === "list")
				{
					// /scripts/v1/guest/list
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /scripts/v1/guest/list", "errorcode" => "use_get_request");

					return $userhelper->GetGuestsByServerExtension($userrow->id, "scripts");
				}
				else if ($pathparts[4] === "create")
				{
					// /scripts/v1/guest/create
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /scripts/v1/guest/create", "errorcode" => "use_post_request");
					if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
					if (!isset($data["run"]))  return array("success" => false, "error" => "Missing 'run'.", "errorcode" => "missing_run");
					if (!isset($data["cancel"]))  return array("success" => false, "error" => "Missing 'cancel'.", "errorcode" => "missing_cancel");
					if (!isset($data["status"]))  return array("success" => false, "error" => "Missing 'status'.", "errorcode" => "missing_status");
					if (!isset($data["monitor"]))  return array("success" => false, "error" => "Missing 'monitor'.", "errorcode" => "missing_monitor");
					if (!isset($data["expires"]))  return array("success" => false, "error" => "Missing 'expires'.", "errorcode" => "missing_expires");

					$options = array(
						"name" => (string)$data["name"],
						"run" => (bool)(int)$data["run"],
						"cancel" => (bool)(int)$data["cancel"],
						"status" => (bool)(int)$data["status"],
						"monitor" => (bool)(int)$data["monitor"]
					);

					$expires = (int)$data["expires"];

					if ($expires <= time())  return array("success" => false, "error" => "Invalid 'expires' timestamp.", "errorcode" => "invalid_expires");

					return $userhelper->CreateGuest($userrow->id, "scripts", $options, $expires);
				}
				else if ($pathparts[4] === "delete")
				{
					// /scripts/v1/guest/delete/ID
					if ($reqmethod !== "DELETE")  return array("success" => false, "error" => "DELETE request required for:  /scripts/v1/guest/delete/ID", "errorcode" => "use_delete_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of guest for:  /scripts/v1/guest/delete/ID", "errorcode" => "missing_id");

					return $userhelper->DeleteGuest($pathparts[5], $userrow->id);
				}
			}

			return array("success" => false, "error" => "Invalid API call.", "errorcode" => "invalid_api_call");
		}
	}
?>