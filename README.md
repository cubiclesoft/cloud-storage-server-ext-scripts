Cloud Storage Server /scripts Extension
=======================================

A powerful and flexible cross-platform /scripts extension for the [self-hosted cloud storage API](https://github.com/cubiclesoft/cloud-storage-server) for starting and monitoring long-running scripts.  Includes a PHP SDK for interacting with the /scripts API.

The /scripts extension is useful for starting long-running scripts that take a while to complete and tracking completion status, running scripts as other users (e.g. root/SYSTEM), and notifying other systems that are monitoring for script completions that they can start doing work immediately instead of polling every 1-5 minutes and being told by the remote system that there is nothing to do.

NOTE:  This extension is considered largely obsolete in favor of [xcron](https://github.com/cubiclesoft/xcron), which can do most of the same things but far more elegantly and with a richer feature set.

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/) [![Discord](https://img.shields.io/discord/777282089980526602?label=chat&logo=discord)](https://cubiclesoft.com/product-support/github/)

Features
--------

* Cross-platform support for all major platforms, including Windows.
* Script queues and limits on how many of each script can be running at one time.
* Tracks completion status of tasks and subtasks within each script.
* Logs each script run in a SQLite database.  Useful for later analysis.
* Uses a crontab-like format, per-user definition file to define what scripts can run.
* Supports passing parameters and limited 'stdin' to the target script.
* Run scripts as other users and groups on the system (*NIX only).
* RESTful status checking and live WebSocket monitoring support.
* Also has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

A Note On Security
------------------

This extension is meant to be running on a [Cloud Storage Server](https://github.com/cubiclesoft/cloud-storage-server) that is running as the root/SYSTEM user.  As such, it should be installed on an isolated Cloud Storage Server instance and on a different port if another instance is running on the same machine.  Then be sure to firewall the server running this extension so that only those systems that really need access can access it.

Leaving a root/SYSTEM user Cloud Storage Server open to the whole Internet or a large network is a bad idea.  With great power comes great responsibility.

Installation
------------

Extract and copy the Cloud Storage Server files as you would for a server.  Remove the `/server_exts/files.php` file.  Copy the `/server_exts/scripts.php` file to the `/server_exts` directory.  Now install the Cloud Storage Server under the root/SYSTEM user and set up your firewall to isolate the system as per the security note above.

If you are running Cloud Storage Server on a Windows or Windows Server OS, you will also need to copy the contents of the `/support` directory from this extension to the Cloud Storage Server `/support` directory.  It contains [createprocess.exe](https://github.com/cubiclesoft/createprocess-windows), which is used as an intermediate layer between PHP and the running script due to [PHP Bug #47918](https://bugs.php.net/bug.php?id=47918).

You may find the cross-platform [Service Manager](https://github.com/cubiclesoft/service-manager/) tool to be useful to enable Cloud Storage Server to function as a system service.

Be sure to create a user using Cloud Storage Server `manage.php` and add the /scripts extension to the user account.

Next, you'll need to initialize the user account's /scripts extension.  To do this, use the PHP SDK to run a script like:

````php
<?php
	require_once "sdk/support/sdk_cloud_storage_server_scripts.php";

	$css = new CloudStorageServerScripts();
	$css->SetAccessInfo("http://127.0.0.1:9893", "YOUR_API_KEY", "", "");

	$result = $css->RunScript("test");
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	$id = $result["body"]["id"];

	do
	{
		sleep(3);
		$result = $css->GetStatus($id);
		if (!$result["success"])
		{
			var_dump($result);
			exit();
		}

		var_dump(file_exists("D:/somepathhere/1/scripts/status/" . $id . ".json"));
		var_dump($result["body"]);
	} while ($result["body"]["state"] !== "done");
?>
````

The above will attempt to start a script with the name of "test" but, since the "exectab.txt" file for the user will be empty at first, it will bail out fairly early on with an 'invalid_name' error.  At this point, the installation is complete and it is time to move onto setting up your first script in 'exectab.txt'.

The exectab.txt File Format
---------------------------

Locate the Cloud Storage Server storage directory as specified by the configuration.  Within it are user ID directories.  Within a user ID directory is a set of other directories associated with each enabled and used extension.  Find the newly set up user account and the /scripts directory.  Within the /scripts directory is a SQLite database that tracks all script runs, a /status subdirectory, and a file called 'exectab.txt'.  The 'exectab.txt' file is very similar to crontab except it doesn't have anything to do with scheduling.

Example 'exectab.txt' file:

````
# Run a PHP script as a specific user and group with a limit of five simultaneous runs of this script running at the same time (unlimited queue size).
-u=someuser -g=somegroup -s=5 test /usr/bin/php /var/scripts/myscript.php -o=@@1 @@2

# Run a PHP script as the same user/group as the Cloud Storage Server process (probably root) with a limit of one script running at a time and with a starting directory of /var/log/apache2.
-d=/var/log/apache2 test2 /usr/bin/php /var/scripts/myscript2.php

# Does not start any processes but will notify listeners (via /scripts/v1/monitor) that 'test3' has finished running.
test3

# Does not start any processes but passes unescaped parameters to 'test4'.
-n test4 @@1 @@2
````

The above defines several script names:  `test`, `test2`, `test3`, and `test4`.  Each one does something different.  The format for script execution lines is:

`[options] scriptname [executable [params]]`

The full list of options for scripts is:

* -d=startdir - The starting directory for the target process.
* -e=envvar - An environment variable to set for the target process.
* -g=group - The *NIX group to run the process under (*NIX only).
* -i - Allow 'stdin' passthrough.  Without this option, passing non-empty 'stdin' strings to /scripts/v1/run is an error.
* -m=num - Maximum queue length.  Default is unlimited.
* -n - No process execution.  No parameter escaping.  Useful for passing parameters to monitors.
* -r - Remove successful and cancelled script runs from the log.  The status/result won't be available after completion.
* -s=num - The number of items in the queue that may run simultaneously.  Default is 1.
* -u=user - The *NIX user to run the process under (*NIX only).

Parameters passed to the /scripts/v1/run API may be referenced using the @@ prefix starting at @@1.  All modified parameters are sanitized using escapeshellarg() before they are executed to avoid security issues with untrusted user input.

The 'stdin' passthrough is limited to approximately 1MB of data.  For most purposes, this limit is more than sufficient.

Tracking Progress
-----------------

While a process is running, it may write to either stdout or stderr.  The /scripts extension looks at each line of output for lines that start with brackets.  Depending on usage, the line will indicate a task or a subtask.

````
[1/3] Task 1
[50%] Subtask
[88%] Subtask
[2] Task 2
[15%] Subtask
[45%] Subtask
[98%] Subtask
[3] Task 3
````

The first line specifies that it is the first of three tasks.  The rest of the line after the part in brackets becomes the task title/name.  The second line specifies a '%' sign before the closing bracket, which means that line is a subtask of the main task.  The title/name for a subtask is usually something like "Processing...".

The title/name portion of a task/subtask is made available to via /scripts/v1/status API, which could be used, for example, to track progress in a web application.  Be sure to avoid exposing internal details of the server to the /scripts API.  All other lines of output are ignored by the /scripts extension, which allows most debugging information to be left in just in case the process needs to be run manually to diagnose some issue.

Processes can be queued to launch in the future at a specified time.  Queued processes that have not started running can be cancelled with the /scripts/v1/cancel API.

Localhost Performance
---------------------

If a script is started via /scripts on the same host as a web server (i.e. the web server uses the API to start the script), it is possible to avoid using the /scripts/v1/status API to query the status.  As the script runs, the information is dumped into the /scripts/status subdirectory for the user account as a JSON file.

To improve performance and avoid using the SDK, first attempt to load the file directly and parse it as JSON.  If that fails for any reason, fallback to the SDK to get the status.  There are a few reasons the attempt to load the file might fail such as attempting to load and read the file while it is being written to disk resulting in an incomplete JSON object or the process might have ended and the file no longer exists.

Monitoring
----------

Let's say you have a server behind a firewall on a corporate network (server A) and you have another server in your DMZ (server B).  A user connects to server B and performs a task.  A cron job runs on server A every couple of minutes and polls server B to see if it has anything to do.  About 99% of the time, server B responds that there is nothing to do.  Therefore, the script on server A does nothing and simply terminates.  This repeats ad nauseum until the end of time, wasting bandwidth and, if web servers had feelings, server B would find server A to be rather annoying.  "Do you have anything for me?  No.  Do you have anything for me?  No.  Do you have anything for me?  No!  ..."  In addition, users of the system are secretly miffed because they have to wait for the cron job to run before stuff happens.

The /scripts/v1/monitor API lets server A establish a WebSocket connection to server B and watch a specific script name.  Whenever that script name runs and completes via the /scripts extension, server B notifies server A immediately.  This API has several advantages:  It stops wasting perfectly good bandwidth, establishes fewer TCP/IP connections, and users are happier because the overall system appears to be more responsive.

Example monitoring script:

````php
<?php
	require_once "sdk/support/sdk_cloud_storage_server_scripts.php";

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	$css = new CloudStorageServerScripts();

	@mkdir($rootpath . "/cache", 0775);
	$result = $css->InitSSLCache("https://remoteserver.com:9892", $rootpath . "/cache/css_ca.pem", $rootpath . "/cache/css_cert.pem");
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	$css->SetAccessInfo("https://remoteserver.com:9892", "YOUR_API_KEY", $rootpath . "/cache/css_ca.pem", file_get_contents($rootpath . "/cache/css_cert.pem"));

	$result = $css->StartMonitoring("test");
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	$ws = $result["ws"];
	$api_sequence = $result["api_sequence"];

	// Main loop.
	$result = $ws->Wait();
	while ($result["success"])
	{
		do
		{
			$result = $ws->Read();
			if (!$result["success"])  break;

			if ($result["data"] !== false)
			{
				$data = json_decode($result["data"]["payload"], true);

				var_dump($data);
				if ($data["state"] === "done")
				{
					// Do something here...
				}
			}
		} while ($result["data"] !== false);

		$result = $ws->Wait();
	}

	// An error occurred.
	var_dump($result);
?>
````

There are various ways to get the monitoring script to start.  If you want to start it at system boot and keep it going should the script terminate at some point, the cross-platform [Service Manager](https://github.com/cubiclesoft/service-manager/) tool is useful.

Extension:  /scripts
--------------------

The /scripts extension implements the /scripts/v1 API.  To try to keep this page relatively short, here is the list of available APIs, the input request method, and successful return values (always JSON output):

POST /scripts/v1/run

* name - Script name
* args - An array of arguments to use to replace @@ tokens with
* stdin - Data to pass through to stdin of the process
* queue - Optional UNIX timestamp (integer) to specify when to start the process
* Returns:  success (boolean), id (string), name (string), state (string), queued (integer, UNIX timestamp), position (integer)

POST /scripts/v1/cancel/ID

* ID - Script run ID
* Returns: success (boolean)
* Summary: Cancels a previously queued script that has not started running

GET /scripts/v1/status[/ID]

* ID - Script run ID
* Returns (with ID):  success (boolean), id (string), name (string), state (string), additional info (various)
* Returns (without ID):  success (boolean), queued (object), running (object)

GET /scripts/v1/monitor (WebSocket only)

* name - Script name OR empty string to monitor all names
* Returns:  success (boolean), name (string), enabled (boolean)

GET /scripts/v1/guest/list

* Returns: success (boolean), guests (array)

POST /scripts/v1/guest/create

* name - Script name
* run - Guest can run scripts
* cancel - Guest can cancel queued scripts
* status - Guest can retrieve the status of scripts
* monitor - Guest can live monitor scripts
* expires - Unix timestamp (integer)
* Returns: success (boolean), id (string), info (array)
* Summary: The 'info' array contains: apikey, created, expires, info (various)

POST /scripts/v1/guest/delete/ID

* ID - Guest ID
* Returns: success (boolean)
