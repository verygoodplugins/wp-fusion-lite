PHP iSDK
==================

This SDK allows developers to easily use the Infusionsoft API with PHP

Install Instructions
==================

This SDK requires at least PHP 5.2

1. Clone the repository to your system
 - ```git clone https://github.com/infusionsoft/PHP-iSDK.git```
 - ```alternatively you can install using composer```
2. Copy the "src" folder (or its contents) to the folder that will hold the SDK

Configuration
==================

There are two ways we can connect to the API.

1. First way is to use the src/conn.cfg.php configuration file

    A. You will need your Application Name and Your API Key
     - ```You can find your application name in the url you goto to login. eg. https://YOURAPPNAME.infusionsoft.com```
     - ```You can get your API Key by following this article on the User Guide``` [http://ug.infusionsoft.com/article/AA-00442/0/How-do-I-enable-the-Infusionsoft-API-and-generate-an-API-Key.html](http://bit.ly/14ewmJH "User Guide")

    B. In src/conn.cfg.php file you will need to replace the following:
     - ```connectionName - This can be anything you want```
     - ```applicationName - This is just the application name that we got in step 1```
     - ```APIKEYGOESHERE - This is the API Key you got in step 1```

2. We can pass in the Application Name and API Key directly into the cfgCon function

    A. You will need your Application Name and Your API Key
     - ```You can find your application name in the url you goto to login. eg. https://YOURAPPNAME.infusionsoft.com```
     - ```You can get your API Key by following this article on the User Guide``` [http://ug.infusionsoft.com/article/AA-00442/0/How-do-I-enable-the-Infusionsoft-API-and-generate-an-API-Key.html](http://bit.ly/14ewmJH "User Guide")

Making Your First API Call
==================

In the script you want to make the API call in you will need to do the following:

1. We need to require the iSDK
 - ```require_once('src/isdk.php');```
2. Next we need to create an object
 - ```$app = new iSDK();```
3. Next we need to create the connection
 - ```$app->cfgCon("connectionName");```
OR
 - ```$app->cfgCon("applicationName", "APIKEYGOESHERE");```
4. Next we will make our first API call using the ContactService.findByEmail method. This method returns contact information by an email address we send
 - ```$contacts = $app->findByEmail('test@example.com',array('Id', 'FirstName', 'LastName', 'Email'));```
 - ```This will return a contact's Id, First Name, Last Name, and Email that has the email 'test@example.com'```
5. Finally we want to print the return information to the browser window
 - ```print_r($contacts);```

How to Use Logging
==================

As of Version 1.8.3 the iSDK has the ability to log API calls to a CSV. By default logging is disabled.

To enable logging do the following:

1. In the script you want to log the API Calls of add this after you create the object
 - ```$app->enableLogging(1);    //0 is off  1 is on```
2. (Optional) You can set the location of the CSV. By default the csv is created in the same directory as isdk.php
 - ```$app->setLog('apilog.csv');  //This is the full path to the file```

Misc Functions
==================

1. infuDate() - formats your date string for use with the API. Has an optional parameter for doing UK date formats.
 - ```infuDate('10/26/2013') will return '20131026T06:00:00'```
 - ```infuDate('10/26/2013','UK') will return '2013-26-10T06:00:00'```

# iSDK